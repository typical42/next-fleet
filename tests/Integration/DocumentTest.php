<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IUserManager;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * A vehicle's papers: attached from Files by `file_id`, listed and detached
 * (docs/architecture.md#documents). The accounts are real, because a document is a
 * file somebody's Files holds.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class DocumentTest extends TestCase {
	private const OWNER = 'nextfleet-test-doc-owner';
	/** Has a Files of their own, which the owner cannot read. */
	private const OTHER = 'nextfleet-test-doc-other';
	/**
	 * Receives a share, and nothing touches their Files before it exists: the server sets a user's
	 * mounts up once per process, so a share made later would not show.
	 */
	private const SHAREE = 'nextfleet-test-doc-sharee';
	/** Not an account: a grant is a string column, and reading the list needs no Files. */
	private const DRIVER = 'nextfleet-test-doc-driver';
	private const ACCOUNTS = [self::OWNER, self::OTHER, self::SHAREE];

	private DocumentService $documents;
	private VehicleService $vehicles;
	private MaintenanceService $workshop;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->documents = $container->get(DocumentService::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->workshop = $container->get(MaintenanceService::class);
	}

	/**
	 * The accounts live for the whole class: the server caches a user folder per uid, and an
	 * account deleted and made again under the same uid cannot write to it.
	 */
	public static function setUpBeforeClass(): void {
		self::forgetAccounts();
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
	}

	private static function forgetAccounts(): void {
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->get($uid)?->delete();
		}
	}

	/** Before the accounts go: a deleted account's uid is on none of its rows. */
	protected function tearDown(): void {
		$this->forget();
	}

	/** The rows this suite invents, gone for real and by vehicle. */
	private function forget(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (['fleet_documents', 'fleet_maintenance', 'fleet_expenses', 'fleet_odo_readings', 'fleet_access', 'fleet_reminder_recipients'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];
	}

	public function testAFileFromTheOwnersFilesIsAttachedAndListed(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Fahrzeugschein.pdf');

		$listed = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'registration']);

		$this->assertCount(1, $listed);
		$this->assertSame($fileId, $listed[0]['file_id']);
		$this->assertSame('registration', $listed[0]['kind']);
		$this->assertSame('Fahrzeugschein.pdf', $listed[0]['name']);
		$this->assertSame('application/pdf', $listed[0]['mime']);
		$this->assertNull($listed[0]['linked_type']);
		$this->assertNull($listed[0]['linked_uuid']);
		$this->assertSame($listed, $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * The download serves whatever is attached to whoever may view the vehicle, so attaching is
	 * where a file id must be one the attacher can read. Otherwise any id on the instance would be
	 * a way into somebody else's Files. Not found, rather than forbidden: whether the file exists
	 * is not the attacher's to learn.
	 */
	public function testAFileTheAttacherCannotReadIsNotFound(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$theirs = $this->file(self::OTHER, 'Private.pdf');

		try {
			$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $theirs, 'kind' => 'receipt']);
			$this->fail('attached a file from somebody else\'s Files');
		} catch (DoesNotExistException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * A share is readable but not the attacher's to pass on: the download would outlive a revoked
	 * share and ignore a view-only one.
	 */
	public function testAFileSharedWithTheAttacherIsNotFound(): void {
		$vehicle = $this->vehicle(self::SHAREE);
		$theirs = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::OTHER)->getFirstNodeById($this->file(self::OTHER, 'Shared.pdf'));
		$shares = \OCP\Server::get(IShareManager::class);
		$share = $shares->newShare()
			->setNode($theirs)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith(self::SHAREE)
			->setSharedBy(self::OTHER)
			->setShareOwner(self::OTHER)
			->setPermissions(Constants::PERMISSION_READ);
		$share = $shares->createShare($share);
		$shares->acceptShare($share, self::SHAREE);
		$seen = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::SHAREE)->getFirstNodeById($theirs->getId());
		$this->assertNotNull($seen, 'the share did not reach the sharee\'s Files, so this test proves nothing');

		$this->expectException(DoesNotExistException::class);
		$this->documents->attach(self::SHAREE, $vehicle->getUuid(), ['file_id' => $theirs->getId(), 'kind' => 'receipt']);
	}

	/** A folder is not a document, and a download could not serve one. */
	public function testAFolderIsNotADocument(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$folder = $this->folder(self::OWNER)->getId();

		$this->expectException(DoesNotExistException::class);
		$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $folder, 'kind' => 'manual']);
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function unreadableFields(): iterable {
		yield 'no file' => [['kind' => 'manual']];
		yield 'a word for a file' => [['file_id' => 'abc', 'kind' => 'manual']];
		yield 'no kind' => [['file_id' => 1]];
		yield 'an unknown kind' => [['file_id' => 1, 'kind' => 'tattoo']];
		yield 'an unknown link' => [['file_id' => 1, 'kind' => 'receipt', 'linked_type' => 'trip', 'linked_uuid' => 'x']];
		yield 'a link type without its row' => [['file_id' => 1, 'kind' => 'receipt', 'linked_type' => 'expense']];
		yield 'a row without its link type' => [['file_id' => 1, 'kind' => 'receipt', 'linked_uuid' => 'x']];
	}

	/**
	 * @dataProvider unreadableFields
	 * @param array<string, mixed> $fields
	 */
	public function testFieldsThatSayNothingUsableAreRefused(array $fields): void {
		$vehicle = $this->vehicle(self::OWNER);

		$this->expectException(\InvalidArgumentException::class);
		$this->documents->attach(self::OWNER, $vehicle->getUuid(), $fields);
	}

	/** Done when: a workshop invoice is reachable from its maintenance record. */
	public function testAnInvoiceIsLinkedToItsMaintenanceRecord(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$record = $this->maintenance($vehicle);

		$listed = $this->documents->attach(self::OWNER, $vehicle->getUuid(), [
			'file_id' => $this->file(self::OWNER, 'Rechnung.pdf'),
			'kind' => 'receipt',
			'linked_type' => 'maintenance',
			'linked_uuid' => $record,
		]);

		$this->assertSame('maintenance', $listed[0]['linked_type']);
		$this->assertSame($record, $listed[0]['linked_uuid']);
	}

	/** One vehicle of their own must not be a way to pin a paper on somebody else's entry. */
	public function testALinkToAnotherVehiclesEntryIsNotFound(): void {
		$mine = $this->vehicle(self::OWNER);
		$theirs = $this->vehicle(self::OTHER);
		$record = $this->maintenance($theirs);

		try {
			$this->documents->attach(self::OWNER, $mine->getUuid(), [
				'file_id' => $this->file(self::OWNER, 'Rechnung.pdf'),
				'kind' => 'receipt',
				'linked_type' => 'maintenance',
				'linked_uuid' => $record,
			]);
			$this->fail('linked a paper to another vehicle\'s record');
		} catch (DoesNotExistException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $mine->getUuid()));
	}

	/** Access follows the vehicle, not the file: a driver sees papers in the owner's Files. */
	public function testADriverReadsTheListButNeitherAttachesNorDetaches(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];

		$this->assertSame(['Police.pdf'], array_column($this->documents->list(self::DRIVER, $vehicle->getUuid()), 'name'));
		foreach ([
			fn () => $this->documents->attach(self::DRIVER, $vehicle->getUuid(), ['file_id' => 1, 'kind' => 'manual']),
			fn () => $this->documents->detach(self::DRIVER, $vehicle->getUuid(), $uuid),
		] as $call) {
			try {
				$call();
				$this->fail('a driver wrote the papers');
			} catch (AccessDeniedException) {
			}
		}
		$this->assertCount(1, $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/** Detaching leaves the file where it is: it was never ours. */
	public function testDetachingTakesThePaperOffAndLeavesTheFile(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Handbuch.pdf');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'manual'])[0]['uuid'];

		$this->assertSame([], $this->documents->detach(self::OWNER, $vehicle->getUuid(), $uuid));
		$this->assertNotNull(\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($fileId));
	}

	/** A document uuid names a row on the vehicle the route names, and on no other. */
	public function testAnotherVehiclesPaperIsNotFoundThroughMine(): void {
		$mine = $this->vehicle(self::OWNER);
		$theirs = $this->vehicle(self::OTHER);
		$uuid = $this->documents->attach(self::OTHER, $theirs->getUuid(), ['file_id' => $this->file(self::OTHER, 'Schein.pdf'), 'kind' => 'registration'])[0]['uuid'];

		try {
			$this->documents->detach(self::OWNER, $mine->getUuid(), $uuid);
			$this->fail('detached another vehicle\'s paper');
		} catch (DoesNotExistException) {
		}
		$this->assertCount(1, $this->documents->list(self::OTHER, $theirs->getUuid()));
	}

	/** A `file_id` survives a move but not a delete, and the list says so rather than failing. */
	public function testAFileDeletedInFilesStaysListedWithoutAName(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$folder = $this->folder(self::OWNER);
		$file = $folder->newFile('Foto.jpg', 'not really a jpeg');
		$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $file->getId(), 'kind' => 'photo']);

		$file->move($this->folder(self::OWNER)->getPath() . '/Moved.jpg');
		$this->assertSame(['Moved.jpg'], array_column($this->documents->list(self::OWNER, $vehicle->getUuid()), 'name'));

		\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($file->getId())?->delete();
		$listed = $this->documents->list(self::OWNER, $vehicle->getUuid());
		$this->assertCount(1, $listed);
		$this->assertNull($listed[0]['name']);
		$this->assertNull($listed[0]['mime']);
	}

	/**
	 * Done when: a paper is there for everyone with access to the vehicle, whoever owns the file.
	 * The driver has no Files of their own, so nothing but the vehicle's grant can be serving it.
	 */
	public function testADriverDownloadsAPaperFromTheOwnersFiles(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];

		$file = $this->documents->download(self::DRIVER, $vehicle->getUuid(), $uuid);

		$this->assertSame('Police.pdf', $file->getName());
		$this->assertSame('%PDF-1.4 test', $file->getContent());
	}

	/** Whoever has no grant on the vehicle gets nothing, however real the paper's uuid. */
	public function testAStrangerDoesNotDownloadARealPaper(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];

		$this->expectException(AccessDeniedException::class);
		$this->documents->download(self::OTHER, $vehicle->getUuid(), $uuid);
	}

	/** A `file_id` survives a delete in no usable form: the trash bin keeps it, and we do not serve from there. */
	public function testAPaperWhoseFileWasDeletedIsNotFound(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Foto.jpg');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'photo'])[0]['uuid'];
		\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($fileId)?->delete();

		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
	}

	/**
	 * A paper uuid names a row on the vehicle the route names. Otherwise one vehicle of their own
	 * would open every other vehicle's papers to whoever learnt a uuid.
	 */
	public function testAnotherVehiclesPaperIsNotDownloadedThroughMine(): void {
		$mine = $this->vehicle(self::OWNER);
		$theirs = $this->vehicle(self::OTHER);
		$uuid = $this->documents->attach(self::OTHER, $theirs->getUuid(), ['file_id' => $this->file(self::OTHER, 'Schein.pdf'), 'kind' => 'registration'])[0]['uuid'];

		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $mine->getUuid(), $uuid);
	}

	/** A detached paper is off the vehicle, even though its file is still in Files. */
	public function testADetachedPaperIsNotFound(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Handbuch.pdf'), 'kind' => 'manual'])[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $uuid);

		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
	}

	/** Picking the same file twice for the same place is one paper. */
	public function testAttachingTheSameFileTwiceIsOnce(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fields = ['file_id' => $this->file(self::OWNER, 'Schein.pdf'), 'kind' => 'registration'];

		$this->documents->attach(self::OWNER, $vehicle->getUuid(), $fields);

		$this->assertCount(1, $this->documents->attach(self::OWNER, $vehicle->getUuid(), $fields));
	}

	private function maintenance(Vehicle $vehicle): string {
		return $this->workshop->record($vehicle->getUserId(), $vehicle->getUuid(), [
			'done_at' => 1750000000, 'done_at_off' => 120, 'title' => 'Inspektion',
		])['uuid'];
	}

	private function vehicle(string $owner): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => 'B-DC 1']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	/** A file in the account's own Files, as the Files app or the phone's auto-upload puts it. */
	private function file(string $uid, string $name): int {
		return $this->folder($uid)->newFile($name, '%PDF-1.4 test')->getId();
	}

	/** A folder of its own for each call, since the accounts outlive a test. */
	private function folder(string $uid): Folder {
		return \OCP\Server::get(IRootFolder::class)->getUserFolder($uid)->newFolder(bin2hex(random_bytes(6)));
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy(self::OWNER);
		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
