<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Db\BookingMapper;
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
use OCA\NextFleet\Exception\AccessDeniedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\Files\File;
use OCP\Files\Node;
use OCP\IDBConnection;

/**
 * A vehicle's papers (docs/architecture.md#documents). The file stays where the Files app put it
 * and is referenced by `file_id`; there is no upload path.
 *
 * @psalm-import-type NextFleetDocument from \OCA\NextFleet\ResponseDefinitions as Listed
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
		private BookingMapper $bookings,
		private VehicleAccess $access,
		private OwnFiles $files,
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
		return $this->of($userId, $this->fleet->reach($userId, VehicleAccess::VIEW, $vehicleUuid));
	}

	/**
	 * Attaches a file the user picked in Files, optionally to one entry or booking of the same
	 * vehicle, under that row's rule (keeps()).
	 *
	 * The file must be the user's own, in their own Files (OwnFiles): the download serves it to
	 * everyone who may view the vehicle (docs/architecture.md#documents).
	 *
	 * @param array<string, mixed> $fields `file_id`, `kind`, and `linked_type` with `linked_uuid` or neither
	 * @return list<Listed> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not keep papers on this vehicle, or on that row
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle, the file or the linked row is not there for this user
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function attach(string $userId, string $vehicleUuid, array $fields): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$once = Once::of(
			$fields,
			$this->documents,
			static fn (Document $row): bool => $row->getVehicleId() === (int)$vehicle->getId(),
			fn (): array => $this->of($userId, $vehicle),
		);

		return $once->run(fn (): array => $this->insert($userId, $vehicle, $fields, $once));
	}

	/**
	 * What attach() writes once it knows the request is no retry.
	 *
	 * @param array<string, mixed> $fields
	 * @param Once<Document> $once
	 * @return list<Listed>
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	private function insert(string $userId, Vehicle $vehicle, array $fields, Once $once): array {
		$fileId = OwnFiles::id($fields['file_id'] ?? null);
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

		$vehicleId = (int)$vehicle->getId();
		// Before the file: whether a file id exists is nothing to tell somebody the rule refuses.
		// The row's author never changes, so the lookup under the hold below cannot change the answer.
		$this->permit($userId, $vehicle, $linkedType === null ? null : $this->linked($linkedType)->findOnVehicle($vehicleId, (string)$linkedUuid));

		$this->files->find($userId, $fileId);

		$this->atomicRetry(function () use ($userId, $vehicleId, $fileId, $kind, $linkedType, $linkedUuid, $once): void {
			$this->vehicles->hold($vehicleId);
			$once->check();
			// Looked up under the hold, so the row cannot be deleted between the check and the insert.
			$linkedId = $linkedType === null ? null
				: (int)$this->linked($linkedType)->findOnVehicle($vehicleId, (string)$linkedUuid)->getId();
			// The first kind stands; a different one here is not an edit (docs/architecture.md#documents).
			if ($this->holds($vehicleId, $fileId, $linkedType, $linkedId)) {
				return;
			}

			$row = new Document();
			$once->stamp($row);
			$row->setVehicleId($vehicleId);
			$row->setFileId($fileId);
			$row->setKind($kind);
			$row->setLinkedType($linkedType);
			$row->setLinkedId($linkedId);
			$row->setCreatedBy($userId);
			$this->documents->insert($row);
		}, $this->db);

		return $this->of($userId, $vehicle);
	}

	/**
	 * Takes a paper off the vehicle, under the rule attaching it took (keeps()). The file stays in
	 * Files: it was never ours.
	 *
	 * @return list<Listed> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not keep this paper
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle has no such document
	 * @throws \OCP\DB\Exception
	 */
	public function detach(string $userId, string $vehicleUuid, string $documentUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$this->atomicRetry(function () use ($userId, $vehicle, $documentUuid): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$document = $this->documents->findOnVehicle((int)$vehicle->getId(), $documentUuid);
			$this->permit($userId, $vehicle, $this->owners($vehicle, [$document])[(int)$document->getId()]);
			// Nothing edits a document, so the row's own token is the one read: the hold orders
			// two detaches, and the second finds nothing.
			$this->documents->softDelete($document, $document->getUpdatedAt());
		}, $this->db);

		return $this->of($userId, $vehicle);
	}

	/**
	 * The undo of detach(), under the rule detaching took. A paper that is live already, or whose
	 * file is on the same row again by now, stays as it is: attaching the same file to the same
	 * place twice is one paper.
	 *
	 * @return list<Listed> the list as it now stands
	 * @throws \OCA\NextFleet\Exception\AccessDeniedException if the user may not keep this paper
	 * @throws \OCP\AppFramework\Db\DoesNotExistException if the vehicle has no such document, live or detached, or the row it belonged to is deleted
	 * @throws \OCP\DB\Exception
	 */
	public function restore(string $userId, string $vehicleUuid, string $documentUuid): array {
		$vehicle = $this->fleet->reach($userId, VehicleAccess::LOG, $vehicleUuid);
		$this->atomicRetry(function () use ($userId, $vehicle, $documentUuid): void {
			$this->vehicles->hold((int)$vehicle->getId());
			$document = $this->documents->findAnyOnVehicle((int)$vehicle->getId(), $documentUuid);
			$owner = $this->owners($vehicle, [$document])[(int)$document->getId()];
			$this->permit($userId, $vehicle, $owner);
			if ($document->getDeletedAt() === null
				|| $this->holds((int)$vehicle->getId(), $document->getFileId(), $document->getLinkedType(), $document->getLinkedId())) {
				return;
			}
			// Attaching takes a live row only, and so does coming back: a deleted entry shows no paper.
			if ($document->getLinkedType() !== null && ($owner === null || $owner->getDeletedAt() !== null)) {
				throw new DoesNotExistException('The row document ' . $documentUuid . ' belonged to is gone');
			}
			// The row's own token, for the reason detach() reads it.
			$this->documents->restoreChecked($document, $document->getUpdatedAt());
		}, $this->db);

		return $this->of($userId, $vehicle);
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
		$file = $this->asked(fn (): ?Node => $this->files->mine($document->getCreatedBy(), $document->getFileId()));

		return $file instanceof File ? $file
			: throw new DoesNotExistException('The file behind document ' . $documentUuid . ' is no longer a file of its own');
	}

	/**
	 * What the attached file is while it is still the attacher's own, wherever in their Files it
	 * moved, or null. Access follows the vehicle, so the file's side is what the attacher vouched
	 * for: moved into a share, a group folder or an external storage, somebody else can change or
	 * remove it (docs/security.md#authorization). A file in the trash bin is outside their Files.
	 *
	 * OwnFiles mounts the attacher's Files, not `IRootFolder::getById`: in a web request that
	 * searches only the session user's mounts, so a driver would never find the owner's file.
	 *
	 * @template T
	 * @param \Closure(): T $ask
	 * @return T|null
	 * @throws \OCP\DB\Exception
	 */
	private function asked(\Closure $ask): mixed {
		try {
			return $ask();
		} catch (\OCP\DB\Exception $e) {
			// Ours to retry, not a file gone: the screen would tell everyone it was removed.
			throw $e;
		} catch (\Exception) {
			// An erased attacher's pseudonym has no Files (NoUserException, which OCP does not
			// name), nor does a storage that is down: their papers list without a name.
			return null;
		}
	}

	/**
	 * Whether the user may attach a paper to `$linked`, or take one off it: the rule for changing
	 * that row - an Entry's own-entry rule, a booking's (VehicleAccess::mayBooking()) - and `edit`
	 * for the vehicle's own papers. A driver files the receipt of their own fill-up; the
	 * registration and the insurance stay a manager's.
	 *
	 * @throws AccessDeniedException
	 */
	private function permit(string $userId, Vehicle $vehicle, ?BaseEntity $linked): void {
		if (!$this->keeps($userId, $vehicle, $linked)) {
			throw new AccessDeniedException();
		}
	}

	private function keeps(string $userId, Vehicle $vehicle, ?BaseEntity $linked): bool {
		return $linked instanceof Booking
			? $this->access->mayBooking($userId, $vehicle, $linked->getUserId())
			: $this->access->mayChange($userId, VehicleAccess::EDIT, $vehicle, $linked?->getCreatedBy());
	}

	/**
	 * Whether a live paper already puts this file on this place; one is all there is.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function holds(int $vehicleId, int $fileId, ?string $linkedType, ?int $linkedId): bool {
		foreach ($this->documents->findByVehicle($vehicleId) as $one) {
			if ($one->getFileId() === $fileId && $one->getLinkedType() === $linkedType && $one->getLinkedId() === $linkedId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return BaseMapper<Energy>|BaseMapper<Maintenance>|BaseMapper<Expense>|BaseMapper<Booking>
	 */
	private function linked(string $linkedType): BaseMapper {
		return match ($linkedType) {
			Document::ENERGY => $this->energy,
			Document::MAINTENANCE => $this->maintenance,
			Document::EXPENSE => $this->expenses,
			Document::BOOKING => $this->bookings,
		};
	}

	/**
	 * The row each paper hangs on, deleted ones too, by the paper's id; null for one of the
	 * vehicle's own papers. One query per kind of row, however many papers.
	 *
	 * @param list<Document> $documents
	 * @return array<int, ?BaseEntity>
	 * @throws \OCP\DB\Exception
	 */
	private function owners(Vehicle $vehicle, array $documents): array {
		$rows = [];
		foreach (Document::LINKABLE as $type) {
			$ids = [];
			foreach ($documents as $document) {
				if ($document->getLinkedType() === $type) {
					$ids[] = (int)$document->getLinkedId();
				}
			}
			$rows[$type] = $this->linked($type)->findAnyByIds((int)$vehicle->getId(), $ids);
		}

		$owners = [];
		foreach ($documents as $document) {
			$type = $document->getLinkedType();
			$owners[(int)$document->getId()] = $type === null ? null : ($rows[$type][(int)$document->getLinkedId()] ?? null);
		}

		return $owners;
	}

	/**
	 * @return list<Listed>
	 * @throws \OCP\DB\Exception
	 */
	private function of(string $userId, Vehicle $vehicle): array {
		return $this->wired($userId, $vehicle, $this->documents->findByVehicle((int)$vehicle->getId()));
	}

	/**
	 * Papers of one vehicle in their wire form, for the caller.
	 *
	 * @param list<Document> $documents
	 * @return list<Listed>
	 * @throws \OCP\DB\Exception
	 */
	public function wired(string $userId, Vehicle $vehicle, array $documents): array {
		$owners = $this->owners($vehicle, $documents);
		// One lookup per file, however many rows it is on.
		/** @var array<string, ?array{name: string, mime: string}> $shown */
		$shown = [];

		return array_map(function (Document $document) use ($userId, $vehicle, $owners, &$shown): array {
			$key = $document->getCreatedBy() . "\0" . $document->getFileId();
			if (!array_key_exists($key, $shown)) {
				$shown[$key] = $this->asked(fn (): ?array => $this->files->shown($document->getCreatedBy(), $document->getFileId()));
			}
			$file = $shown[$key];
			$owner = $owners[(int)$document->getId()];

			return [
				'uuid' => $document->getUuid(),
				'kind' => $document->getKind(),
				'file_id' => $document->getFileId(),
				// Null once the file is deleted or no longer the attacher's own: the row stays, and
				// the screen says the file is gone.
				'name' => $file['name'] ?? null,
				'mime' => $file['mime'] ?? null,
				'linked_type' => $document->getLinkedType(),
				'linked_uuid' => $owner?->getUuid(),
				'may' => $this->keeps($userId, $vehicle, $owner) ? ['detach'] : [],
			];
		}, $documents);
	}
}
