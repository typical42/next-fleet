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
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;

/**
 * A vehicle's papers (docs/architecture.md#nextcloud-integration). The file stays where the Files
 * app put it and is referenced by `file_id`; there is no upload path. Reading takes VIEW, attaching
 * and detaching take EDIT, and each answers with the list as it now stands.
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
	 * The file must be one the user can read in their own Files. The download serves an attached
	 * file to everyone who may view the vehicle, so without this check any `file_id` on the
	 * instance would open somebody else's Files.
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
		if (!$node instanceof File || !$node->isReadable()) {
			throw new DoesNotExistException('No such file in ' . $userId . '\'s Files');
		}

		$this->atomicRetry(function () use ($userId, $vehicle, $fileId, $kind, $linkedType, $linkedUuid): void {
			$this->vehicles->hold((int)$vehicle->getId());
			// Looked up under the hold, so the entry cannot be deleted between the check and the insert.
			$linkedId = $linkedType === null ? null
				: (int)$this->entries($linkedType)->findOnVehicle((int)$vehicle->getId(), (string)$linkedUuid)->getId();
			foreach ($this->documents->findByVehicle((int)$vehicle->getId()) as $one) {
				if ($one->getFileId() === $fileId && $one->getLinkedType() === $linkedType && $one->getLinkedId() === $linkedId) {
					return;
				}
			}

			$row = new Document();
			$row->setVehicleId((int)$vehicle->getId());
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
	 * The attached file wherever it lives now, in whoever's Files hold it: access follows the
	 * vehicle. A file in the trash bin keeps its id, but a paper somebody threw away is gone.
	 */
	private function file(int $fileId): ?File {
		foreach ($this->root->getById($fileId) as $node) {
			if ($node instanceof File && preg_match('#^/[^/]+/files/#', $node->getPath()) === 1) {
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
