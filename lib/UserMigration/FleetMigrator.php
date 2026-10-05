<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\UserMigration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\ReminderRecipient;
use OCA\NextFleet\Db\TripMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use OCP\IUser;
use OCP\UserMigration\IExportDestination;
use OCP\UserMigration\IImportSource;
use OCP\UserMigration\IMigrator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The personal data export (Art. 15, 20 GDPR) through Nextcloud's user migration
 * (docs/architecture.md#personal-data-export). Export only - see import().
 */
class FleetMigrator implements IMigrator {
	/**
	 * What the account wrote about its own doing: a trip it drove, a fill-up it paid. Theirs in
	 * full, whoever may see the vehicle now.
	 */
	private const AUTHORED = ['fleet_trips', 'fleet_energy', 'fleet_maintenance', 'fleet_expenses', 'fleet_odo_readings', 'fleet_bookings', 'fleet_documents', 'fleet_reminder_receipts'];

	/** @var array<int, bool> by vehicle id */
	private array $sees = [];

	public function __construct(
		private AccountTables $tables,
		private VehicleMapper $vehicles,
		private TripMapper $trips,
		private VehicleAccess $access,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\UserMigration\UserMigrationException
	 */
	public function export(IUser $user, IExportDestination $exportDestination, OutputInterface $output): void {
		$output->writeln('Exporting NextFleet rows…');
		$count = 0;
		foreach ($this->tables->all() as $table) {
			$uid = $user->getUID();
			$rows = array_map(fn (BaseEntity $entity): array => $this->rowFor($uid, $table->getTableName(), $entity), $table->findNaming($uid));
			$count += count($rows);
			// Substituted, not thrown: one bad byte in a note would fail every app's export.
			$json = json_encode($rows, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
			$exportDestination->addFileContents($this->getId() . '/' . $table->getTableName() . '.json', $json);
		}

		// docs/security.md#what-is-logged
		$this->logger->info('User data export', ['app' => Application::APP_ID, 'user' => $user->getUID(), 'rows' => $count]);
	}

	/**
	 * Restoring would mean deciding whose vehicle a row belongs to on a server where the people
	 * and vehicles differ, so it is not done. Said rather than thrown: a throw fails every other
	 * app's import with it.
	 */
	public function import(IUser $user, IImportSource $importSource, OutputInterface $output): void {
		$output->writeln('NextFleet does not restore from an export; its rows stay in the archive for reading.');
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getDisplayName(): string {
		return 'NextFleet';
	}

	public function getDescription(): string {
		return $this->l->t('Every NextFleet row that names you: vehicles, entries, reminders, bookings and access');
	}

	public function getVersion(): int {
		return 1;
	}

	/** Any version: import() reads nothing, so no archive can be one it fails on. */
	public function canImport(IImportSource $importSource): bool {
		return true;
	}

	/**
	 * The row in full, or only that it exists. A row that mirrors a vehicle's shared state - the
	 * vehicle, its reminders, its grants, its audit trail - and names the account only as its
	 * writer shows that state as it is now, which may be somebody else's: a vehicle handed to a
	 * new owner, a reminder edited after the account lost its grant. Art. 15(4) GDPR: the copy
	 * must not reach into other people's data, so such a row stays whole only while the account
	 * may still see the vehicle.
	 *
	 * @return array<string, mixed>
	 * @throws \OCP\DB\Exception
	 */
	private function rowFor(string $uid, string $table, BaseEntity $entity): array {
		$row = self::row($entity);
		if (in_array($table, self::AUTHORED, true) || ($row['created_by'] ?? null) !== $uid || $this->sees($uid, $this->vehicleOf($entity))) {
			return $row;
		}

		return [
			'uuid' => $row['uuid'] ?? null,
			'created_by' => $uid,
			'created_at' => $row['created_at'] ?? null,
			'withheld' => 'The rest of this row belongs to a vehicle you can no longer see.',
		];
	}

	/** @throws \OCP\DB\Exception */
	private function vehicleOf(BaseEntity $entity): ?int {
		try {
			return match (true) {
				$entity instanceof Vehicle => (int)$entity->getId(),
				$entity instanceof Audit => $entity->getEntity() === Audit::TRIP
					? $this->trips->findAnyById($entity->getEntityId())->getVehicleId()
					: $entity->getEntityId(),
				// The rest of the tables that mirror shared state; AUTHORED took the others.
				$entity instanceof Access, $entity instanceof Reminder, $entity instanceof ReminderRecipient => $entity->getVehicleId(),
				default => null,
			};
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** @throws \OCP\DB\Exception */
	private function sees(string $uid, ?int $vehicleId): bool {
		if ($vehicleId === null) {
			return false;
		}
		if (!array_key_exists($vehicleId, $this->sees)) {
			try {
				$this->sees[$vehicleId] = $this->access->may($uid, VehicleAccess::VIEW, $this->vehicles->findAnyById($vehicleId));
			} catch (DoesNotExistException) {
				$this->sees[$vehicleId] = false;
			}
		}

		return $this->sees[$vehicleId];
	}

	/** @return array<string, mixed> column => value, as the table holds it */
	private static function row(BaseEntity $entity): array {
		$row = [];
		foreach (array_keys($entity->getFieldTypes()) as $property) {
			$value = $entity->{'get' . ucfirst($property)}();
			// A DATE column comes back as a DateTime, which JSON would spell as an object.
			$row[$entity->propertyToColumn($property)] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
		}

		return $row;
	}
}
