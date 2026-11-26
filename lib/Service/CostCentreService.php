<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\CostCentre;
use OCA\NextFleet\Db\CostCentreMapper;
use OCA\NextFleet\Db\TypedText;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDBConnection;

/**
 * The owner's cost centres (CONTEXT.md, Cost Centre): the owner creates, renames, archives and
 * deletes them; whoever reaches one of the owner's vehicles reads the list, a viewer too. None
 * of it is money, so nothing here is hidden with the costs.
 *
 * @psalm-import-type NextFleetCostCentre from \OCA\NextFleet\ResponseDefinitions
 * @psalm-import-type NextFleetCostCentreGone from \OCA\NextFleet\ResponseDefinitions
 */
class CostCentreService {
	use TTransactional;

	/** The refusals a client words (RefusedException). */
	public const NAME_TAKEN = PartnerService::NAME_TAKEN;
	public const CODE_TAKEN = 'code_taken';
	public const IN_USE = 'in_use';

	public function __construct(
		private CostCentreMapper $centres,
		private VehicleMapper $vehicles,
		private VehicleService $fleet,
		private IDBConnection $db,
	) {
	}

	/**
	 * The caller's own list and that of every owner whose vehicle they reach, archived records
	 * too, by owner and name. Each says whose it is, so a sheet offers the vehicle owner's. A wider
	 * set than a partner's names go to (PartnerService::ownersLoggedFor()): a viewer names the cost
	 * centre an entry shows.
	 *
	 * @return list<NextFleetCostCentre>
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId): array {
		$owners = array_values(array_unique([$userId, ...array_map(static fn (Vehicle $vehicle): string => $vehicle->getUserId(), $this->fleet->list($userId))]));

		return array_map(self::wire(...), $this->centres->findOf($owners));
	}

	/**
	 * Adds a cost centre to the caller's own list.
	 *
	 * @param array<string, mixed> $fields `code` and `name`
	 * @return NextFleetCostCentre
	 * @throws RefusedException if another record has the code or the name
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function create(string $userId, array $fields): array {
		[$code, $name] = self::read($fields);

		return $this->atomicRetry(function () use ($userId, $code, $name): array {
			$centre = new CostCentre();
			$centre->setUserId($userId);
			$centre->setCreatedBy($userId);
			$this->name($centre, $code, $name);

			return self::wire($this->centres->insert($centre));
		}, $this->db);
	}

	/**
	 * Renames the record and archives it or takes it back. The whole record, as a partner's edit
	 * is: a field left out is emptied, so `archived` left out takes it back.
	 *
	 * @param array<string, mixed> $fields `code`, `name` and `archived`
	 * @return NextFleetCostCentre
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws StaleUpdateException
	 * @throws RefusedException if another record has the code or the name
	 * @throws \InvalidArgumentException if a field is not what its column holds
	 * @throws \OCP\DB\Exception
	 */
	public function update(string $userId, string $uuid, int $expectedUpdatedAt, array $fields): array {
		[$code, $name] = self::read($fields);
		$archived = Field::read('archived', 'flag', null, $fields['archived'] ?? null);

		return $this->atomicRetry(function () use ($userId, $uuid, $expectedUpdatedAt, $code, $name, $archived): array {
			$centre = $this->owned($userId, $uuid);
			$this->name($centre, $code, $name);
			$centre->setArchived($archived === true);

			return self::wire($this->centres->updateChecked($centre, $expectedUpdatedAt));
		}, $this->db);
	}

	/**
	 * Deletes a record nothing names. One an entry or a vehicle names is archived instead: it is
	 * what those entries count to.
	 *
	 * @return NextFleetCostCentreGone
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException if the caller does not own the list
	 * @throws StaleUpdateException
	 * @throws RefusedException if a row names it
	 * @throws \OCP\DB\Exception
	 */
	public function delete(string $userId, string $uuid, int $expectedUpdatedAt): array {
		return $this->atomicRetry(function () use ($userId, $uuid, $expectedUpdatedAt): array {
			$centre = $this->owned($userId, $uuid);
			// First: a row that names the record holds it too (CostCentreLinks::hold()), so it
			// either committed and is seen below, or waits and then finds the record gone.
			$this->centres->hold((int)$centre->getId());
			if ($this->centres->isNamed((int)$centre->getId())) {
				throw new RefusedException('cost centre ' . $uuid . ' is named by an entry or a vehicle', self::IN_USE);
			}
			$gone = $this->centres->softDelete($centre, $expectedUpdatedAt);

			return ['uuid' => $gone->getUuid(), 'deleted_at' => (int)$gone->getDeletedAt()];
		}, $this->db);
	}

	/**
	 * Sets the code and the name, each one the list does not hold yet: two records of one name
	 * would be two choices nobody can tell apart. Under the owner's hold, as a partner's rename.
	 *
	 * @throws RefusedException
	 * @throws \OCP\DB\Exception
	 */
	private function name(CostCentre $centre, ?string $code, string $name): void {
		$this->vehicles->holdOwner($centre->getUserId());
		foreach (['code' => $code, 'name' => $name] as $column => $value) {
			$taken = $value === null ? null : $this->centres->findBy($centre->getUserId(), $column, $value);
			if ($taken !== null && $taken->getId() !== $centre->getId()) {
				throw new RefusedException('another cost centre has the ' . $column . ' ' . $value, $column === 'code' ? self::CODE_TAKEN : self::NAME_TAKEN);
			}
		}
		$centre->setCode($code);
		$centre->setName($name);
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{?string, string} the code and the name as the list keeps them
	 * @throws \InvalidArgumentException
	 */
	private static function read(array $fields): array {
		$code = Field::read('code', 'text', CostCentre::CODE_LENGTH, $fields['code'] ?? null);
		$name = Field::read('name', 'text', CostCentre::NAME_LENGTH, $fields['name'] ?? null);

		return [
			TypedText::normal(is_string($code) ? $code : null, CostCentre::CODE_LENGTH),
			TypedText::normal(is_string($name) ? $name : null, CostCentre::NAME_LENGTH)
				?? throw new \InvalidArgumentException('name is a field every cost centre carries'),
		];
	}

	/**
	 * A live record of the caller's own list.
	 *
	 * @throws DoesNotExistException
	 * @throws AccessDeniedException
	 * @throws \OCP\DB\Exception
	 */
	private function owned(string $userId, string $uuid): CostCentre {
		$centre = $this->centres->findByUuid($uuid);
		if ($centre->getUserId() !== $userId) {
			throw new AccessDeniedException();
		}

		return $centre;
	}

	/** @return NextFleetCostCentre */
	public static function wire(CostCentre $centre): array {
		return [
			'uuid' => $centre->getUuid(),
			'user_id' => $centre->getUserId(),
			'code' => $centre->getCode(),
			'name' => $centre->getName(),
			'archived' => $centre->getArchived(),
			'created_at' => $centre->getCreatedAt(),
			'updated_at' => $centre->getUpdatedAt(),
		];
	}
}
