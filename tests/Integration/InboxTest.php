<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\InboxController;
use OCA\NextFleet\Controller\PreferencesController;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\DocumentService;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\InboxService;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Constants;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

/**
 * The receipt inbox: a folder of the user's own Files, chosen as a preference, and the files in it
 * that belong to no vehicle yet (docs/architecture.md#the-inbox). The accounts are real, because
 * the inbox is a folder somebody's Files holds.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class InboxTest extends TestCase {
	use Accounts;

	private const OWNER = 'nextfleet-test-inbox-owner';
	/** Shares a folder with the sharee, and owns a vehicle the owner drives. */
	private const OTHER = 'nextfleet-test-inbox-other';
	/**
	 * Receives a share, and nothing touches their Files before it exists: the server sets a user's
	 * mounts up once per process, so a share made later would not show.
	 */
	private const SHAREE = 'nextfleet-test-inbox-sharee';
	/** As the sharee: a share mounted inside their inbox folder. */
	private const NESTER = 'nextfleet-test-inbox-nester';
	/** As the sharee: moves their inbox folder into a share they receive. */
	private const MOVER = 'nextfleet-test-inbox-mover';
	private const ACCOUNTS = [self::OWNER, self::OTHER, self::SHAREE, self::NESTER, self::MOVER];

	private DocumentService $documents;
	private VehicleService $vehicles;
	/** @var list<int> */
	private array $vehicleIds = [];

	public static function setUpBeforeClass(): void {
		self::deleteAccounts(self::ACCOUNTS);
		$users = \OCP\Server::get(IUserManager::class);
		foreach (self::ACCOUNTS as $uid) {
			$users->createUser($uid, bin2hex(random_bytes(16)));
		}
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts(self::ACCOUNTS);
	}

	protected function setUp(): void {
		$this->documents = \OCP\Server::get(DocumentService::class);
		$this->vehicles = \OCP\Server::get(VehicleService::class);
	}

	protected function tearDown(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		if ($this->vehicleIds !== []) {
			foreach (['fleet_documents', 'fleet_energy', 'fleet_odo_readings', 'fleet_access'] as $table) {
				$qb = $db->getQueryBuilder();
				$qb->delete($table)->where($qb->expr()->in('vehicle_id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
				$qb->executeStatement();
			}
			$qb = $db->getQueryBuilder();
			$qb->delete('fleet_vehicles')->where($qb->expr()->in('id', $qb->createNamedParameter($this->vehicleIds, $qb::PARAM_INT_ARRAY)));
			$qb->executeStatement();
		}
		$this->vehicleIds = [];
		foreach (self::ACCOUNTS as $uid) {
			\OCP\Server::get(IConfig::class)->deleteUserValue($uid, Application::APP_ID, 'inbox_folder');
		}
	}

	/**
	 * What the phone's auto-upload leaves is what the inbox shows: the images and PDFs right in the
	 * folder, the newest first. A subfolder's files are not walked, and a note is no receipt.
	 */
	public function testTheInboxListsTheImagesAndPdfsInTheFolderNewestFirst(): void {
		$inbox = $this->folder(self::OWNER);
		$old = $this->file($inbox, 'Tanken.jpg', 1750000000);
		$new = $this->file($inbox, 'Werkstatt.pdf', 1750002000);
		$this->file($inbox, 'Parken.png', 1750001000);
		$this->file($inbox, 'Notiz.txt', 1750003000);
		$this->file($inbox->newFolder('2026-10'), 'Tiefer.jpg', 1750004000);

		$this->assertSame(Http::STATUS_OK, $this->choose(self::OWNER, $inbox->getId())->getStatus());
		$read = $this->inbox(self::OWNER);

		$this->assertSame(['file_id' => $inbox->getId(), 'path' => '/' . $inbox->getName()], $read['folder']);
		$this->assertSame(['Werkstatt.pdf', 'Parken.png', 'Tanken.jpg'], array_column($read['files'], 'name'));
		$this->assertSame(3, $read['count']);
		$this->assertSame(
			['file_id' => $new, 'name' => 'Werkstatt.pdf', 'mime' => 'application/pdf', 'mtime' => 1750002000, 'size' => 13],
			$read['files'][0],
		);
		$this->assertSame($old, $read['files'][2]['file_id']);
		$this->assertSame('image/jpeg', $read['files'][2]['mime']);
	}

	/** The choice is a preference: the next session reads the same folder. */
	public function testTheChosenFolderIsAPreferenceAndNullClearsIt(): void {
		$inbox = $this->folder(self::OWNER);

		$this->assertSame($inbox->getId(), $this->choose(self::OWNER, $inbox->getId())->getData()['preferences']['inbox_folder']);
		$this->assertSame($inbox->getId(), $this->settings(self::OWNER)->index()->getData()['preferences']['inbox_folder']);

		$this->assertNull($this->choose(self::OWNER, null)->getData()['preferences']['inbox_folder']);
		$this->assertSame(['folder' => null, 'files' => [], 'count' => 0], $this->inbox(self::OWNER));
	}

	/** Attaching is what empties the inbox; a detached paper is back where it waits. */
	public function testAnAttachedFileLeavesTheInboxAndADetachedOneComesBack(): void {
		$inbox = $this->folder(self::OWNER);
		$receipt = $this->file($inbox, 'Beleg.pdf', 1750000000);
		$this->choose(self::OWNER, $inbox->getId());
		$vehicle = $this->vehicle(self::OWNER);

		$listed = $this->documents->attach(self::OWNER, $vehicle->getUuid(), ['file_id' => $receipt, 'kind' => 'receipt']);
		$this->assertSame(0, $this->inbox(self::OWNER)['count']);

		$this->documents->detach(self::OWNER, $vehicle->getUuid(), $listed[0]['uuid']);
		$this->assertSame([$receipt], array_column($this->inbox(self::OWNER)['files'], 'file_id'));
	}

	/**
	 * Only a vehicle the user still reaches holds a file out of the inbox: a receipt on a car they
	 * no longer drive is one they cannot see attached anywhere.
	 */
	public function testAFileOnAVehicleTheUserNoLongerReachesIsBackInTheInbox(): void {
		$inbox = $this->folder(self::OWNER);
		$receipt = $this->file($inbox, 'Beleg.pdf', 1750000000);
		$this->choose(self::OWNER, $inbox->getId());
		$theirs = $this->vehicle(self::OTHER);
		$grant = $this->grant($theirs, self::OWNER, 'driver');
		$fillUp = \OCP\Server::get(EnergyService::class)->record(self::OWNER, $theirs->getUuid(), [
			'filled_at' => 1750000000, 'filled_at_off' => 120, 'energy' => 'diesel', 'amount' => 42000,
		])['uuid'];
		$this->documents->attach(self::OWNER, $theirs->getUuid(), ['file_id' => $receipt, 'kind' => 'receipt', 'linked_type' => 'energy', 'linked_uuid' => $fillUp]);
		$this->assertSame(0, $this->inbox(self::OWNER)['count']);

		\OCP\Server::get(AccessMapper::class)->softDelete($grant, $grant->getUpdatedAt());

		$this->assertSame([$receipt], array_column($this->inbox(self::OWNER)['files'], 'file_id'));
	}

	/** A large folder answers its newest hundred, and says how many there are in all. */
	public function testAtMostAHundredAreListedAndAllAreCounted(): void {
		$inbox = $this->folder(self::OWNER);
		for ($i = 0; $i < 101; $i++) {
			$this->file($inbox, sprintf('IMG_%03d.jpg', $i), 1750000000 + $i);
		}
		$this->choose(self::OWNER, $inbox->getId());

		$read = $this->inbox(self::OWNER);

		$this->assertCount(100, $read['files']);
		$this->assertSame(101, $read['count']);
		$this->assertSame('IMG_100.jpg', $read['files'][0]['name']);
		$this->assertSame('IMG_001.jpg', $read['files'][99]['name']);
	}

	/**
	 * A shared folder is somebody else's: what lands there is not the user's to attach, as
	 * attaching refuses a shared file.
	 */
	public function testASharedFolderIsRefusedAndNothingIsStored(): void {
		$theirs = $this->folder(self::OTHER);
		$this->receive(self::SHAREE, $theirs);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->choose(self::SHAREE, $theirs->getId())->getStatus());
		$this->assertNull($this->settings(self::SHAREE)->index()->getData()['preferences']['inbox_folder']);
	}

	/** A share moved into the inbox folder is somebody else's, as attaching would say. */
	public function testAShareInsideTheFolderIsNotListed(): void {
		$seen = $this->receive(self::NESTER, $this->folder(self::OTHER)->newFile('Fremd.pdf', '%PDF-1.4 test'));
		$inbox = $this->folder(self::NESTER);
		$mine = $this->file($inbox, 'Beleg.pdf', 1750000000);
		$seen->move($inbox->getPath() . '/Fremd.pdf');
		$this->assertTrue($inbox->nodeExists('Fremd.pdf'), 'the shared file is not in the inbox folder, so this test proves nothing');
		$this->choose(self::NESTER, $inbox->getId());

		$this->assertSame([$mine], array_column($this->inbox(self::NESTER)['files'], 'file_id'));
	}

	/** Moved into a share after it was chosen, the folder is the sharer's: no inbox, rather than theirs. */
	public function testAFolderMovedIntoAReceivedShareReadsAsNone(): void {
		$seen = $this->receive(self::MOVER, $this->folder(self::OTHER));
		$inbox = $this->folder(self::MOVER);
		$this->file($inbox, 'Beleg.pdf', 1750000000);
		$this->assertSame(Http::STATUS_OK, $this->choose(self::MOVER, $inbox->getId())->getStatus());

		$moved = $inbox->move($seen->getPath() . '/Belege');
		$this->assertSame($inbox->getId(), $moved->getId(), 'the move made a new folder, so this test proves nothing');

		$this->assertSame(['folder' => null, 'files' => [], 'count' => 0], $this->inbox(self::MOVER));
	}

	/**
	 * A file is no folder to list, and somebody else's folder is not found in the user's Files.
	 *
	 * @dataProvider notAnInbox
	 */
	public function testWhatIsNoFolderOfTheUsersOwnIsRefused(string $what): void {
		$id = match ($what) {
			'a file' => $this->file($this->folder(self::OWNER), 'Beleg.pdf', 1750000000),
			'another user\'s folder' => $this->folder(self::OTHER)->getId(),
			'no number' => 'Belege',
		};

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->choose(self::OWNER, $id)->getStatus());
		$this->assertNull($this->settings(self::OWNER)->index()->getData()['preferences']['inbox_folder']);
	}

	/** @return iterable<string, array{string}> */
	public static function notAnInbox(): iterable {
		yield 'a file' => ['a file'];
		yield 'another user\'s folder' => ['another user\'s folder'];
		yield 'no number' => ['no number'];
	}

	/** A folder deleted in Files leaves no inbox behind, rather than an error. */
	public function testADeletedFolderReadsAsNone(): void {
		$inbox = $this->folder(self::OWNER);
		$this->file($inbox, 'Beleg.pdf', 1750000000);
		$this->choose(self::OWNER, $inbox->getId());

		$inbox->delete();

		$this->assertSame(['folder' => null, 'files' => [], 'count' => 0], $this->inbox(self::OWNER));
	}

	/** Nothing registers these classes, so the container has to build them from their types alone. */
	public function testTheControllerIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(InboxController::class, \OCP\Server::get(InboxController::class));
	}

	private function choose(string $uid, mixed $folderId): DataResponse {
		return $this->settings($uid, ['inbox_folder' => $folderId])->update();
	}

	/** @return array<array-key, mixed> */
	private function inbox(string $uid): array {
		$answer = (new InboxController(
			Application::APP_ID,
			$this->createMock(IRequest::class),
			\OCP\Server::get(InboxService::class),
			$this->session($uid),
		))->index();
		$this->assertSame(Http::STATUS_OK, $answer->getStatus());
		$data = $answer->getData();
		$this->assertIsArray($data);

		return $data;
	}

	/** @param array<string, mixed> $params */
	private function settings(string $uid, array $params = []): PreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		return new PreferencesController(
			Application::APP_ID,
			$request,
			\OCP\Server::get(PreferencesService::class),
			$this->session($uid),
		);
	}

	private function session(string $uid): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}

	/**
	 * `$node` shared by OTHER with `$sharee`, as it shows in the sharee's Files. Once per sharee: the
	 * server sets their mounts up on the first look.
	 */
	private function receive(string $sharee, Node $node): Node {
		$shares = \OCP\Server::get(IShareManager::class);
		$share = $shares->newShare()
			->setNode($node)
			->setShareType(IShare::TYPE_USER)
			->setSharedWith($sharee)
			->setSharedBy(self::OTHER)
			->setShareOwner(self::OTHER)
			->setPermissions($node instanceof Folder ? Constants::PERMISSION_ALL : Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE | Constants::PERMISSION_SHARE);
		$shares->acceptShare($shares->createShare($share), $sharee);
		$seen = \OCP\Server::get(IRootFolder::class)->getUserFolder($sharee)->getFirstNodeById($node->getId());
		$this->assertNotNull($seen, 'the share did not reach the sharee\'s Files, so this test proves nothing');

		return $seen;
	}

	/** A folder of its own for each call, since the accounts outlive a test. */
	private function folder(string $uid): Folder {
		return \OCP\Server::get(IRootFolder::class)->getUserFolder($uid)->newFolder(bin2hex(random_bytes(6)));
	}

	/** A file as auto-upload leaves it, taken at `$mtime`. */
	private function file(Folder $folder, string $name, int $mtime): int {
		$file = $folder->newFile($name, '%PDF-1.4 test');
		$file->touch($mtime);

		return $file->getId();
	}

	private function vehicle(string $owner): Vehicle {
		$vehicle = $this->vehicles->create($owner, ['plate' => 'B-IN 1']);
		$this->vehicleIds[] = (int)$vehicle->getId();

		return $vehicle;
	}

	private function grant(Vehicle $vehicle, string $grantee, string $role): Access {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy($vehicle->getUserId());

		return \OCP\Server::get(AccessMapper::class)->insert($grant);
	}
}
