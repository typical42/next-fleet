<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\DocumentController;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\BookingMapper;
use OCA\NextFleet\Db\DocumentMapper;
use OCA\NextFleet\Db\EnergyMapper;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Db\MaintenanceMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Service\BookingService;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\OwnFiles;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OCP\Files\Config\IMountProviderCollection;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\ICacheFactory;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
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
	use Accounts;
	use CountsQueries;

	private const OWNER = 'nextfleet-test-doc-owner';
	/** Has a Files of their own, which the owner cannot read. */
	private const OTHER = 'nextfleet-test-doc-other';
	/**
	 * Receives a share, and nothing touches their Files before it exists: the server sets a user's
	 * mounts up once per process, so a share made later would not show.
	 */
	private const SHAREE = 'nextfleet-test-doc-sharee';
	/** An account: a receipt they attach to their own fill-up is a file in their own Files. */
	private const DRIVER = 'nextfleet-test-doc-driver';
	/** Moves an attached file into a share they receive; untouched before, as the sharee is. */
	private const MOVER = 'nextfleet-test-doc-mover';
	private const ACCOUNTS = [self::OWNER, self::OTHER, self::SHAREE, self::DRIVER, self::MOVER];
	/** What the owner's ForeignStorage holds, on the server's disk. */
	private const FOREIGN_DIR = '/tmp/nextfleet-test-foreign';

	private DocumentService $documents;
	private VehicleService $vehicles;
	private MaintenanceService $workshop;
	private EnergyService $fillUps;
	private BookingService $pool;
	/** @var list<int> */
	private array $vehicleIds = [];

	protected function setUp(): void {
		$this->documents = \OCP\Server::get(DocumentService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
		$this->workshop = \OCP\Server::get(MaintenanceService::class);
		$this->fillUps = \OCP\Server::get(EnergyService::class);
		$this->pool = \OCP\Server::get(BookingService::class);
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
		if (is_dir(self::FOREIGN_DIR)) {
			self::removeTree(self::FOREIGN_DIR);
		}
		mkdir(self::FOREIGN_DIR);
		\OCP\Server::get(IMountProviderCollection::class)->registerProvider(new ForeignStorage(self::OWNER, self::FOREIGN_DIR));
	}

	public static function tearDownAfterClass(): void {
		self::forgetAccounts();
		self::removeTree(self::FOREIGN_DIR);
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
			foreach (['fleet_documents', 'fleet_maintenance', 'fleet_energy', 'fleet_expenses', 'fleet_odo_readings', 'fleet_access', 'fleet_reminder_recipients', 'fleet_bookings'] as $table) {
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

	/** A retried attach is answered with the list and writes nothing: a detach since stands. */
	public function testAnAttachSentAgainUnderItsClientUuidAttachesNothing(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$paper = ['client_uuid' => '0195e2f1-3333-4000-8000-000000000001', 'file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'];
		$listed = $this->documents->attach(self::OWNER, $vehicle->getUuid(), $paper);
		$this->assertSame([$paper['client_uuid']], array_column($listed, 'uuid'));
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $paper['client_uuid']);

		try {
			$this->documents->attach(self::OWNER, $vehicle->getUuid(), $paper);
			$this->fail('attached again');
		} catch (AlreadyCreatedException $e) {
			$this->assertSame([], $e->answer);
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
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

	/**
	 * A group folder or an admin's external storage names whoever asks as the owner, and is still
	 * not their Files: somebody else may change or remove what sits there.
	 */
	public function testAFileOnAStorageThatIsNotTheAttachersHomeIsNotFound(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$foreign = $this->foreign()->newFile(bin2hex(random_bytes(6)) . '.pdf', '%PDF-1.4 test');

		try {
			$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $foreign->getId(), 'kind' => 'receipt']);
			$this->fail('attached a file from a storage that is not the attacher\'s home');
		} catch (DoesNotExistException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * What attaching refuses, a live paper does not serve either: moved there after attaching, the
	 * file is no longer one of the attacher's own. Listed without a name, as the screen shows a
	 * file it cannot open.
	 */
	public function testAFileMovedOntoAStorageThatIsNotTheAttachersHomeIsNoLongerServed(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$file = $this->folder(self::OWNER)->newFile('Police.pdf', '%PDF-1.4 test');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $file->getId(), 'kind' => 'insurance'])[0]['uuid'];

		$moved = $file->move($this->foreign()->getPath() . '/' . bin2hex(random_bytes(6)) . '.pdf');
		$this->assertSame($file->getId(), $moved->getId(), 'the move made a new file, so this test proves nothing');
		self::later();

		$this->assertNull($this->documents->list(self::OWNER, $vehicle->getUuid())[0]['name']);
		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
	}

	/** Moved into a folder somebody shared with the attacher, the file is the sharer's now. */
	public function testAFileMovedIntoAReceivedShareIsNoLongerServed(): void {
		$shared = $this->folder(self::OTHER);
		$shares = \OCP\Server::get(IShareManager::class);
		$share = $shares->newShare()
			->setNode($shared)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith(self::MOVER)
			->setSharedBy(self::OTHER)
			->setShareOwner(self::OTHER)
			->setPermissions(Constants::PERMISSION_ALL);
		$shares->acceptShare($shares->createShare($share), self::MOVER);
		$seen = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::MOVER)->getFirstNodeById($shared->getId());
		$this->assertInstanceOf(Folder::class, $seen, 'the share did not reach the mover\'s Files, so this test proves nothing');
		$vehicle = $this->vehicle(self::MOVER);
		$file = $this->folder(self::MOVER)->newFile('Police.pdf', '%PDF-1.4 test');
		$uuid = $this->documents->attach(self::MOVER, $vehicle->getUuid(), ['file_id' => $file->getId(), 'kind' => 'insurance'])[0]['uuid'];

		$moved = $file->move($seen->getPath() . '/Police.pdf');
		$this->assertSame($file->getId(), $moved->getId(), 'the move made a new file, so this test proves nothing');
		self::later();

		$this->assertNull($this->documents->list(self::MOVER, $vehicle->getUuid())[0]['name']);
		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::MOVER, $vehicle->getUuid(), $uuid);
	}

	/**
	 * An erased attacher's papers name a pseudonym, which has no Files: the list goes on without
	 * their names rather than failing for everyone.
	 */
	public function testAPaperWhoseAttacherIsGoneIsListedWithoutAName(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];
		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->update('fleet_documents')
			->set('created_by', $qb->createNamedParameter('erased:' . str_repeat('a', 20)))
			->where($qb->expr()->eq('uuid', $qb->createNamedParameter($uuid)));
		$qb->executeStatement();

		$this->assertNull($this->documents->list(self::OWNER, $vehicle->getUuid())[0]['name']);
		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
	}

	/** One file on many rows is looked up once: a page of papers costs per file, not per paper. */
	public function testOneFileOnManyRowsCostsWhatItCostsOnOne(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Rechnung.pdf');
		$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'receipt', 'linked_type' => 'maintenance', 'linked_uuid' => $this->maintenance($vehicle)]);
		$one = self::queriesOf(fn () => $this->documents->list(self::OWNER, $vehicle->getUuid()));
		for ($i = 0; $i < 4; $i++) {
			$this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'receipt', 'linked_type' => 'maintenance', 'linked_uuid' => $this->maintenance($vehicle)]);
		}

		$five = self::queriesOf(fn () => $this->documents->list(self::OWNER, $vehicle->getUuid()));

		$this->assertCount(5, $this->documents->list(self::OWNER, $vehicle->getUuid()));
		$this->assertSame($one, $five);
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

	/**
	 * Access follows the vehicle, not the file: a driver sees papers in the owner's Files. The
	 * vehicle's own papers - registration, insurance - stay a manager's to keep.
	 */
	public function testADriverReadsTheListButNeitherAttachesNorDetachesTheVehiclesOwnPapers(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];

		$listed = $this->documents->list(self::DRIVER, $vehicle->getUuid());
		$this->assertSame(['Police.pdf'], array_column($listed, 'name'));
		$this->assertSame([], $listed[0]['may']);
		foreach ([
			fn () => $this->documents->attach(self::DRIVER, $vehicle->getUuid(), ['file_id' => $this->file(self::DRIVER, 'Handbuch.pdf'), 'kind' => 'manual']),
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

	/** Done when: a driver attaches a receipt to an entry they entered, and may take it off again. */
	public function testADriverKeepsTheReceiptOfTheirOwnFillUp(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$fillUp = $this->fillUp($vehicle, self::DRIVER);

		$listed = $this->documents->attach(self::DRIVER, $vehicle->getUuid(), [
			'file_id' => $this->file(self::DRIVER, 'Tankbeleg.pdf'),
			'kind' => 'receipt',
			'linked_type' => 'energy',
			'linked_uuid' => $fillUp,
		]);

		$this->assertSame('energy', $listed[0]['linked_type']);
		$this->assertSame($fillUp, $listed[0]['linked_uuid']);
		$this->assertSame(['detach'], $listed[0]['may']);
		$this->assertSame(['detach'], $this->documents->list(self::OWNER, $vehicle->getUuid())[0]['may']);
		$this->assertSame([], $this->documents->detach(self::DRIVER, $vehicle->getUuid(), $listed[0]['uuid']));
	}

	/** Somebody else's entry is theirs, or a manager's, to file papers on. */
	public function testADriverAttachesNothingToSomebodyElsesEntry(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$fillUp = $this->fillUp($vehicle, self::OWNER);

		try {
			$this->documents->attach(self::DRIVER, $vehicle->getUuid(), [
				'file_id' => $this->file(self::DRIVER, 'Tankbeleg.pdf'),
				'kind' => 'receipt',
				'linked_type' => 'energy',
				'linked_uuid' => $fillUp,
			]);
			$this->fail('a driver filed a paper on the owner\'s fill-up');
		} catch (AccessDeniedException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * Detaching takes the rule of the row the paper hangs on, not of who attached it: a receipt the
	 * owner filed on the driver's fill-up is the driver's to take off, as the fill-up is theirs to
	 * change.
	 */
	public function testDetachingFollowsTheEntryThePaperBelongsTo(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$theirs = $this->fillUp($vehicle, self::DRIVER);
		$mine = $this->fillUp($vehicle, self::OWNER);
		$paper = fn (string $fillUp, string $name): string => array_column($this->documents->attach(self::OWNER, $vehicle->getUuid(), [
			'file_id' => $this->file(self::OWNER, $name), 'kind' => 'receipt', 'linked_type' => 'energy', 'linked_uuid' => $fillUp,
		]), 'uuid', 'linked_uuid')[$fillUp];
		$onTheirs = $paper($theirs, 'Theirs.pdf');
		$onMine = $paper($mine, 'Mine.pdf');

		$mayOf = array_column($this->documents->list(self::DRIVER, $vehicle->getUuid()), 'may', 'uuid');
		$this->assertSame(['detach'], $mayOf[$onTheirs]);
		$this->assertSame([], $mayOf[$onMine]);
		try {
			$this->documents->detach(self::DRIVER, $vehicle->getUuid(), $onMine);
			$this->fail('a driver took a paper off the owner\'s fill-up');
		} catch (AccessDeniedException) {
		}
		$this->assertSame([$onMine], array_column($this->documents->detach(self::DRIVER, $vehicle->getUuid(), $onTheirs), 'uuid'));
	}

	/** Done when: handover photos attach to the booking, under the booking's rule. */
	public function testAHandoverPhotoBelongsToTheBooking(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$booking = $this->pool->book(self::DRIVER, $vehicle->getUuid(), self::tomorrow())['uuid'];
		$photo = [
			'kind' => 'photo',
			'linked_type' => 'booking',
			'linked_uuid' => $booking,
		];

		$listed = $this->documents->attach(self::DRIVER, $vehicle->getUuid(), $photo + ['file_id' => $this->file(self::DRIVER, 'Kratzer.jpg')]);

		$this->assertSame('booking', $listed[0]['linked_type']);
		$this->assertSame($booking, $listed[0]['linked_uuid']);
		$this->assertSame(['detach'], $listed[0]['may']);
		$this->expectException(AccessDeniedException::class);
		$this->documents->attach(self::DRIVER, $vehicle->getUuid(), [
			'linked_uuid' => $this->pool->book(self::OWNER, $vehicle->getUuid(), self::tomorrow(2))['uuid'],
			'file_id' => $this->file(self::DRIVER, 'Delle.jpg'),
		] + $photo);
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
		self::later();
		$this->assertSame(['Moved.jpg'], array_column($this->documents->list(self::OWNER, $vehicle->getUuid()), 'name'));

		\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($file->getId())?->delete();
		self::later();
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

	/**
	 * A `file_id` survives a delete in no usable form: the trash bin keeps it, and we do not serve
	 * from there. The list may name the file for five minutes more; the download asks live.
	 */
	public function testAPaperWhoseFileWasDeletedIsNotFound(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Foto.jpg');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'photo'])[0]['uuid'];
		\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($fileId)?->delete();
		$this->assertSame('Foto.jpg', $this->documents->list(self::OWNER, $vehicle->getUuid())[0]['name']);

		$this->expectException(DoesNotExistException::class);
		$this->documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
	}

	/** A database failing while the file is looked up is a failure to retry, not a file removed. */
	public function testADatabaseFailureFindingTheFileIsNoFileGone(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Police.pdf'), 'kind' => 'insurance'])[0]['uuid'];
		$files = $this->createMock(OwnFiles::class);
		$files->method('mine')->willThrowException(new \OCP\DB\Exception('connection lost'));
		$documents = new DocumentService(
			\OCP\Server::get(DocumentMapper::class),
			\OCP\Server::get(VehicleService::class),
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(EnergyMapper::class),
			\OCP\Server::get(MaintenanceMapper::class),
			\OCP\Server::get(ExpenseMapper::class),
			\OCP\Server::get(BookingMapper::class),
			\OCP\Server::get(VehicleAccess::class),
			$files,
			\OCP\Server::get(IDBConnection::class),
		);

		$this->expectException(\OCP\DB\Exception::class);
		$documents->download(self::OWNER, $vehicle->getUuid(), $uuid);
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

	/**
	 * The screen fetches a paper rather than following its link, and says the refusal by its status
	 * (src/utils/papers.js): a removed paper and a deleted file are each a 404 with a message, a
	 * stranger a 403, never an empty body a tab would show.
	 */
	public function testARefusedDownloadAnswersAStatusTheScreenCanSay(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fileId = $this->file(self::OWNER, 'Foto.jpg');
		$removed = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Handbuch.pdf'), 'kind' => 'manual'])[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $removed);
		$gone = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $fileId, 'kind' => 'photo'])[0]['uuid'];
		\OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->getFirstNodeById($fileId)?->delete();

		$answers = [];
		foreach ([[self::OWNER, $removed], [self::OWNER, $gone], [self::OTHER, $gone]] as [$who, $paper]) {
			$response = $this->door($who)->download($vehicle->getUuid(), $paper);
			$this->assertInstanceOf(DataResponse::class, $response);
			$answers[] = [$response->getStatus(), $response->getData()];
		}

		$this->assertSame([
			[Http::STATUS_NOT_FOUND, ['message' => 'No such document']],
			[Http::STATUS_NOT_FOUND, ['message' => 'No such document']],
			[Http::STATUS_FORBIDDEN, ['message' => 'Not yours']],
		], $answers);
	}

	/** The undo after *Remove*: the same paper, same uuid, back on the vehicle. */
	public function testRestoringBringsADetachedPaperBack(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Handbuch.pdf'), 'kind' => 'manual'])[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $uuid);

		$this->assertSame([$uuid], array_column($this->documents->restore(self::OWNER, $vehicle->getUuid(), $uuid), 'uuid'));
		$this->assertSame([$uuid], array_column($this->documents->list(self::OWNER, $vehicle->getUuid()), 'uuid'));
	}

	/** Whoever may not take a paper off may not put it back either: the vehicle's own take `edit`. */
	public function testRestoringFollowsTheRuleDetachingTakes(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$this->grant($vehicle, self::DRIVER, 'driver');
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $this->file(self::OWNER, 'Schein.pdf'), 'kind' => 'registration'])[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $uuid);

		try {
			$this->documents->restore(self::DRIVER, $vehicle->getUuid(), $uuid);
			$this->fail('a driver restored the vehicle\'s own paper');
		} catch (AccessDeniedException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/**
	 * Attached again before the undo: the file is on that place once already, and a second paper
	 * for it would break "the same file twice is one". A paper that is live is left as it is.
	 */
	public function testRestoringWhatIsAlreadyThereChangesNothing(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fields = ['file_id' => $this->file(self::OWNER, 'Schein.pdf'), 'kind' => 'registration'];
		$first = $this->documents->attach(self::OWNER, $vehicle->getUuid(), $fields)[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $first);
		$second = $this->documents->attach(self::OWNER, $vehicle->getUuid(), $fields)[0]['uuid'];

		$this->assertSame([$second], array_column($this->documents->restore(self::OWNER, $vehicle->getUuid(), $first), 'uuid'));
		$this->assertSame([$second], array_column($this->documents->restore(self::OWNER, $vehicle->getUuid(), $second), 'uuid'));
	}

	/** A paper comes back only onto a live row, as attaching it would: a deleted fill-up takes none. */
	public function testAPaperIsNotRestoredOntoADeletedEntry(): void {
		$vehicle = $this->vehicle(self::OWNER);
		$fillUp = $this->fillUps->record(self::OWNER, $vehicle->getUuid(), [
			'filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000,
		]);
		$uuid = $this->documents->attach(self::OWNER, $vehicle->getUuid(), [
			'file_id' => $this->file(self::OWNER, 'Tankbeleg.pdf'), 'kind' => 'receipt', 'linked_type' => 'energy', 'linked_uuid' => $fillUp['uuid'],
		])[0]['uuid'];
		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $uuid);
		$this->fillUps->delete(self::OWNER, $vehicle->getUuid(), $fillUp['uuid'], $fillUp['updated_at']);

		try {
			$this->documents->restore(self::OWNER, $vehicle->getUuid(), $uuid);
			$this->fail('restored a paper onto a deleted fill-up');
		} catch (DoesNotExistException) {
		}
		$this->assertSame([], $this->documents->list(self::OWNER, $vehicle->getUuid()));
	}

	/** A detached paper's uuid names a row on its own vehicle, and on no other. */
	public function testAnotherVehiclesDetachedPaperIsNotRestoredThroughMine(): void {
		$mine = $this->vehicle(self::OWNER);
		$theirs = $this->vehicle(self::OTHER);
		$uuid = $this->documents->attach(self::OTHER, $theirs->getUuid(), ['file_id' => $this->file(self::OTHER, 'Schein.pdf'), 'kind' => 'registration'])[0]['uuid'];
		$this->documents->detach(self::OTHER, $theirs->getUuid(), $uuid);

		try {
			$this->documents->restore(self::OWNER, $mine->getUuid(), $uuid);
			$this->fail('restored another vehicle\'s paper');
		} catch (DoesNotExistException) {
		}
		$this->assertSame([], $this->documents->list(self::OTHER, $theirs->getUuid()));
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

	private function fillUp(Vehicle $vehicle, string $driver): string {
		return $this->fillUps->record($driver, $vehicle->getUuid(), [
			'filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000,
		])['uuid'];
	}

	/**
	 * A span a day from now, or `days` from now, which a booking may take.
	 *
	 * @return array<string, int>
	 */
	private static function tomorrow(int $days = 1): array {
		$start = time() + $days * 86400;

		return ['starts_at' => $start, 'starts_at_off' => 120, 'ends_at' => $start + 3 * 3600, 'ends_at_off' => 120];
	}

	/** The internal download route, as the framework hands `$userId` to it. */
	private function door(string $userId): DocumentController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new DocumentController(Application::APP_ID, $this->createMock(IRequest::class), $this->documents, $session);
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

	/** A folder of its own for each call on the owner's ForeignStorage. */
	private function foreign(): Folder {
		$mount = \OCP\Server::get(IRootFolder::class)->getUserFolder(self::OWNER)->get(ForeignStorage::FOLDER);
		$this->assertInstanceOf(Folder::class, $mount);
		$folder = $mount->newFolder(bin2hex(random_bytes(6)));
		// The server caches a mount only once its storage has a root, which this one lacked when
		// the owner's mounts were set up; a group folder in use has one.
		$owner = \OCP\Server::get(IUserManager::class)->get(self::OWNER);
		$this->assertNotNull($owner);
		\OCP\Server::get(IUserMountCache::class)->registerMounts($owner, [$mount->getMountPoint()], [ForeignStorage::class]);

		return $folder;
	}

	/** Five minutes on: what a list was told of each file has run out (OwnFiles::shown()). */
	private static function later(): void {
		\OCP\Server::get(ICacheFactory::class)->createDistributed(Application::APP_ID . '-files')->clear();
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
