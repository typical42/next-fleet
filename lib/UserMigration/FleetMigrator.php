<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\UserMigration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\AccountTables;
use OCA\NextFleet\Db\BaseEntity;
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
	public function __construct(
		private AccountTables $tables,
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
			$rows = array_map(self::row(...), $table->findNaming($user->getUID()));
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
