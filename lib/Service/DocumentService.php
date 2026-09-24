<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * A vehicle's papers (docs/architecture.md#documents). The file stays where the Files app put it
 * and is referenced by `file_id`; there is no upload path.
 *
 * @psalm-type Listed = array{uuid: string, kind: string, file_id: int, name: ?string, mime: ?string, linked_type: ?string, linked_uuid: ?string}
 */
class DocumentService {
	use TTransactional;

	public function __construct(
		private DocumentMapper $documents,
		private VehicleService $fleet,
		private VehicleMapper $vehicles,
		private EnergyMapper $energy,
		private MaintenanceMapper $maintenance,
		private ExpenseMapper $expenses,
		private IRootFolder $root,
		private IUserMountCache $mounts,
		private IDBConnection $db,
	) {
	}

	/**
	 * @return list<Listed>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not view this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId, string $vehicleUuid): array {
		return $this->of($this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid));
	}

	/**
	 * Attaches a file the user picked in Files, optionally to one entry of the same vehicle.
	 *
	 * The file must be the user's own, in their own Files: the download serves it to everyone who
	 * may view the vehicle, so this is the file's own access check (docs/architecture.md#documents).
	 *
	 * @param array<string, mixed> $fields `file_id`, `kind`, and `linked_type` with `linked_uuid` or neither
	 * @return list<Listed> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle, the file or the linked entry is not there for this user
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function attach(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);

		$fileId = filter_var($fields['file_id'] ?? null, FILTER_VALIDATE_INT);
		if ($fileId === false) {
			throw new \InvalidArgumentException('file_id is the id of a file in Files');
		}
		$kind = $fields['kind'] ?? null;
		if (!is_string($kind) || !in_array($kind, Document::KINDS, true)) {
			throw new \InvalidArgumentException('kind is one of ' . implode(', ', Document::KINDS));
		}
		$linkedType = $fields['linked_type'] ?? null;
		$linkedUuid = $fields['linked_uuid'] ?? null;
		if ($linkedType !== null && !in_array($linkedType, Document::LINKABLE, true)) {
			throw new \InvalidArgumentException('linked_type is one of ' . implode(', ', Document::LINKABLE));
		}
		if (($linkedType === null) !== ($linkedUuid === null) || ($linkedUuid !== null && !is_string($linkedUuid))) {
			throw new \InvalidArgumentException('linked_type and linked_uuid come together or not at all');
		}

		$node = $this->root->getUserFolder($userId)->getFirstNodeById($fileId);
		// Readable is not enough: a share is readable, and not the attacher's to pass on.
		if (!$node instanceof File || !$node->isReadable() || $node->getOwner()?->getUID() !== $userId) {
			throw new DoesNotExistException('No such file in ' . $userId . '\'s Files');
		}

		$vehicleId = (int)$vehicle->getId();
		$this->atomicRetry(function () use ($userId, $vehicleId, $fileId, $kind, $linkedType, $linkedUuid): void {
			$this->vehicles->hold($vehicleId);
			// Looked up under the hold, so the entry cannot be deleted between the check and the insert.
			$linkedId = $linkedType === null ? null
				: (int)$this->entries($linkedType)->findOnVehicle($vehicleId, (string)$linkedUuid)->getId();
			foreach ($this->documents->findByVehicle($vehicleId) as $one) {
				// The first kind stands; a different one here is not an edit (docs/architecture.md#documents).
				if ($one->getFileId() === $fileId && $one->getLinkedType() === $linkedType && $one->getLinkedId() === $linkedId) {
					return;
				}
			}

			$row = new Document();
			$row->setVehicleId($vehicleId);
			$row->setFileId($fileId);
			$row->setKind($kind);
			$row->setLinkedType($linkedType);
			$row->setLinkedId($linkedId);
			$row->setCreatedBy($userId);
			$this->documents->insert($row);
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * Takes a paper off the vehicle. The file stays in Files: it was never ours.
	 *
	 * @return list<Listed> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not edit this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle has no such document
	 * @throws \OCP\DB\Exception
	 */
	public function detach(string $userId, string $vehicleUuid, string $documentUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::EDIT, $vehicleUuid);
		$this->atomicRetry(function () use ($vehicle, $documentUuid): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$document = $this->documents->findOnVehicle((int)$vehicle->getId(), $documentUuid);
			// Nothing edits a document, so the row's own token is the one read: the hold orders
			// two detaches, and the second finds nothing.
			$this->documents->softDelete($document, $document->getUpdatedAt());
		}, $this->db);

		return $this->of($vehicle);
	}

	/**
	 * The file behind one paper, for whoever may view the vehicle: access follows the vehicle, not
	 * the file (docs/architecture.md#documents).
	 *
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not view this vehicle
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle has no such document, or its file is gone
	 * @throws \OCP\DB\Exception
	 */
	public function download(string $userId, string $vehicleUuid, string $documentUuid): File {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid);
		$document = $this->documents->findOnVehicle((int)$vehicle->getId(), $documentUuid);

		return $this->file($document->getFileId())
			?? throw new DoesNotExistException('The file behind document ' . $documentUuid . ' is gone');
	}

	/**
	 * The attached file wherever it lives now, in whoever's Files hold it: access follows the
	 * vehicle. A file in the trash bin keeps its id, but a paper somebody threw away is gone.
	 *
	 * Not `IRootFolder::getById`: in a web request that searches only the session user's mounts,
	 * so a driver would never find the owner's file. `getUserFolder` mounts that user's Files, and
	 * its trash bin is outside them.
	 */
	private function file(int $fileId): ?File {
		$holders = [];
		foreach ($this->mounts->getMountsForFileId($fileId) as $mount) {
			$holders[$mount->getUser()->getUID()] = true;
		}
		foreach (array_keys($holders) as $uid) {
			$node = $this->root->getUserFolder((string)$uid)->getFirstNodeById($fileId);
			if ($node instanceof File) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * @return BaseMapper<Energy>|BaseMapper<Maintenance>|BaseMapper<Expense>
	 */
	private function entries(string $linkedType): BaseMapper {
		return match ($linkedType) {
			Document::ENERGY => $this->energy,
			Document::MAINTENANCE => $this->maintenance,
			Document::EXPENSE => $this->expenses,
		};
	}

	/**
	 * @return list<Listed>
	 * @throws \OCP\DB\Exception
	 */
	private function of(Vehicle $vehicle): array {
		$vehicleId = (int)$vehicle->getId();
		$documents = $this->documents->findByVehicle($vehicleId);

		$linked = [];
		foreach (Document::LINKABLE as $type) {
			$ids = [];
			foreach ($documents as $document) {
				if ($document->getLinkedType() === $type) {
					$ids[] = (int)$document->getLinkedId();
				}
			}
			$linked[$type] = $this->entries($type)->uuidsById($vehicleId, $ids);
		}

		return array_map(function (Document $document) use ($linked): array {
			$file = $this->file($document->getFileId());
			$type = $document->getLinkedType();

			return [
				'uuid' => $document->getUuid(),
				'kind' => $document->getKind(),
				'file_id' => $document->getFileId(),
				// Null once the file is deleted: the row stays, and the screen says it is gone.
				'name' => $file?->getName(),
				'mime' => $file?->getMimeType(),
				'linked_type' => $type,
				'linked_uuid' => $type === null ? null : ($linked[$type][(int)$document->getLinkedId()] ?? null),
			];
		}, $documents);
	}
}
