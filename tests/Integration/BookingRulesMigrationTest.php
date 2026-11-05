<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use Doctrine\DBAL\Schema\Schema;
use OCA\NextFleet\Db\BookingRecipient;
use OCA\NextFleet\Db\BookingRecipientMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Migration\Version000011Date20261104000000;
use OCA\NextFleet\Service\VehicleService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * The booking rules' migration beyond its schema: a vehicle that asked keeps asking, one that did
 * not follows its owner, whose default starts off, and each booking recipient hears what its list
 * heard.
 */
class BookingRulesMigrationTest extends TestCase {
	private const OWNER = 'nextfleet-test-appr-migration';

	private VehicleService $vehicles;

	/**
	 * The step updates every vehicle and recipient on the instance, not only this test's, so each
	 * test is rolled back. Its schema change stays in memory.
	 */
	protected function setUp(): void {
		$this->vehicles = Server::get(VehicleService::class);
		Server::get(IDBConnection::class)->beginTransaction();
	}

	protected function tearDown(): void {
		Server::get(IDBConnection::class)->rollBack();
	}

	public function testAVehicleThatAskedKeepsAskingAndTheRestFollowTheirOwner(): void {
		$asked = $this->vehicleStored(true);
		$free = $this->vehicleStored(false);

		$this->migrate(false);

		$this->assertSame(Vehicle::APPROVAL_ALWAYS, $this->fresh($asked)->approvalRule());
		$this->assertSame([Vehicle::APPROVAL_DEFAULT, false], [$this->fresh($free)->approvalRule(), $this->fresh($free)->needsApproval()]);
	}

	/** A re-install meets the column moved already: its false is an owner's "never". */
	public function testAColumnMovedAlreadyKeepsItsNever(): void {
		$never = $this->vehicleStored(false);

		$this->migrate(null);

		$this->assertSame(Vehicle::APPROVAL_NEVER, $this->fresh($never)->approvalRule());
	}

	/**
	 * Each recipient keeps hearing what the vehicle's list heard, also a vehicle that never
	 * changed its events.
	 */
	public function testEachRecipientHearsWhatItsListHeard(): void {
		$ticked = $this->vehicles->create(self::OWNER, ['plate' => 'NF-MG 2', 'booking_notices' => [Vehicle::NOTICE_RETURNED]]);
		$untouched = $this->vehicleStored(false);
		$this->clearNotices($untouched);
		$this->recipientWithoutEvents($ticked, 'ann');
		$this->recipientWithoutEvents($untouched, 'ben');

		$this->migrate(false);

		$this->assertSame(['ann' => '["returned"]', 'ben' => json_encode(Vehicle::DEFAULT_NOTICES)], $this->storedEvents());
	}

	/** A run that died after adding the column left its rows without events; the rerun copies them. */
	public function testARerunAfterTheSchemaAloneStillCopiesTheEvents(): void {
		$ticked = $this->vehicles->create(self::OWNER, ['plate' => 'NF-MG 3', 'booking_notices' => [Vehicle::NOTICE_RETURNED]]);
		$this->recipientWithoutEvents($ticked, 'ann');

		$this->migrate(null, eventsAdded: true);

		$this->assertSame(['ann' => '["returned"]'], $this->storedEvents());
	}

	/**
	 * @param bool|null $default the column's default as the step meets it
	 * @param bool $eventsAdded whether the step meets the recipients' events column already there
	 */
	private function migrate(?bool $default, bool $eventsAdded = false): void {
		$schema = new Schema();
		$prefix = Server::get(\OCP\IConfig::class)->getSystemValueString('dbtableprefix', 'oc_');
		$schema->createTable($prefix . 'fleet_vehicles')
			->addColumn('booking_approval', 'boolean', ['notnull' => false, 'default' => $default]);
		// The same step adds three more columns; SchemaTest measures them.
		$schema->createTable($prefix . 'fleet_access')->addColumn('role', 'string', ['length' => 16]);
		$recipients = $schema->createTable($prefix . 'fleet_booking_recipients');
		$recipients->addColumn('vehicle_id', 'bigint');
		if ($eventsAdded) {
			$recipients->addColumn('events', 'json', ['notnull' => false]);
		}
		$schema->createTable($prefix . 'fleet_bookings')->addColumn('vehicle_id', 'bigint');
		$step = new Version000011Date20261104000000(Server::get(IDBConnection::class));
		$output = $this->createMock(IOutput::class);
		$wrapper = $this->serverWrapper($schema);

		$step->changeSchema($output, static fn (): ISchemaWrapper => $wrapper, []);
		$step->postSchemaChange($output, static fn (): ISchemaWrapper => $wrapper, []);
	}

	/**
	 * The wrapper MigrationService hands a step, for the reason SchemaTest::serverWrapper() gives.
	 *
	 * @psalm-suppress UndefinedClass, InvalidReturnType, InvalidReturnStatement
	 */
	private function serverWrapper(Schema $schema): ISchemaWrapper {
		return new \OC\DB\SchemaWrapper(Server::get(\OC\DB\Connection::class), $schema);
	}

	/** A vehicle whose column holds what a release before the owner's default wrote. */
	private function vehicleStored(bool $approval): Vehicle {
		$vehicle = $this->vehicles->create(self::OWNER, ['plate' => 'NF-MG 1']);
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_vehicles')
			->set('booking_approval', $qb->createNamedParameter($approval, IQueryBuilder::PARAM_BOOL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$vehicle->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();

		return $vehicle;
	}

	/** As a vehicle from before the events were a column holds them. */
	private function clearNotices(Vehicle $vehicle): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->update('fleet_vehicles')
			->set('booking_notices', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$vehicle->getId(), IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/** A row as a release before the events were per row wrote it. */
	private function recipientWithoutEvents(Vehicle $vehicle, string $uid): void {
		$row = new BookingRecipient();
		$row->setVehicleId((int)$vehicle->getId());
		$row->setUserId($uid);
		$row->setCreatedBy(self::OWNER);
		Server::get(BookingRecipientMapper::class)->insert($row);
	}

	/** @return array<string, string|null> each test recipient's events as stored, by account */
	private function storedEvents(): array {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select('user_id', 'events')
			->from('fleet_booking_recipients')
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)))
			->orderBy('user_id');
		$result = $qb->executeQuery();
		$events = [];
		while (($row = $result->fetch()) !== false) {
			$events[(string)$row['user_id']] = is_resource($row['events']) ? stream_get_contents($row['events']) : $row['events'];
		}
		$result->closeCursor();

		return $events;
	}

	private function fresh(Vehicle $vehicle): Vehicle {
		return Server::get(VehicleMapper::class)->findAnyByUuid($vehicle->getUuid());
	}
}
