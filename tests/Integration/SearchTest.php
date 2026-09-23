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
use OCA\NextFleet\Search\VehicleSearchProvider;
use OCA\NextFleet\Service\VehicleService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * Unified search against the real database: what a term finds, and whose vehicles it may find.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class SearchTest extends TestCase {
	/** Not Nextcloud accounts: `user_id` and `grantee` are string columns with no key on them. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';
	/** A real account, for the one case that goes through the server's own search route. */
	private const ACCOUNT = 'nextfleet-test-searcher';

	private VehicleSearchProvider $provider;
	private VehicleService $vehicles;

	protected function setUp(): void {
		// The router links only to a loaded app's routes. A search request has loaded it; the CLI
		// has not, and every link would come back as the bare server URL.
		\OCP\Server::get(IAppManager::class)->loadApp(Application::APP_ID);
		$container = (new Application())->getContainer();
		$this->provider = $container->get(VehicleSearchProvider::class);
		$this->vehicles = $container->get(VehicleService::class);
		$this->forgetTestRows();
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	/**
	 * The rows and the account this suite invents, gone for real - a soft delete would outlive
	 * the run. Rows first: deleting an account pseudonymises them, and they would leak.
	 */
	private function forgetTestRows(): void {
		$db = \OCP\Server::get(IDBConnection::class);
		$people = [self::OWNER, self::STRANGER, self::ACCOUNT];
		foreach (['fleet_vehicles' => 'user_id', 'fleet_access' => 'grantee'] as $table => $column) {
			$qb = $db->getQueryBuilder();
			$qb->delete($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($people, $qb::PARAM_STR_ARRAY)));
			$qb->executeStatement();
		}
		\OCP\Server::get(IUserManager::class)->get(self::ACCOUNT)?->delete();
	}

	/** Registered with the server: its own search route finds the vehicle and links to it. */
	public function testTheServerSearchFindsAVehicle(): void {
		$password = bin2hex(random_bytes(16));
		\OCP\Server::get(IUserManager::class)->createUser(self::ACCOUNT, $password);
		$golf = $this->vehicles->create(self::ACCOUNT, ['plate' => 'B-XY 123', 'model' => 'Golf']);

		$response = \OCP\Server::get(IClientService::class)->newClient()->get(
			'http://localhost/ocs/v2.php/search/providers/nextfleet/search?format=json&term=golf',
			[
				'auth' => [self::ACCOUNT, $password],
				'headers' => ['OCS-APIRequest' => 'true'],
				'nextcloud' => ['allow_local_address' => true],
			],
		);
		$entries = json_decode((string)$response->getBody(), true)['ocs']['data']['entries'];

		$this->assertSame(['B-XY 123'], array_column($entries, 'title'));
		$this->assertStringEndsWith('/apps/nextfleet/?vehicle=' . $golf->getUuid(), $entries[0]['resourceUrl']);
	}

	/** "Golf" is how people look for their vehicle. */
	public function testAVehicleIsFoundByItsModelWhateverTheCase(): void {
		$golf = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'manufacturer' => 'Volkswagen', 'model' => 'Golf']);

		$entries = $this->search(self::OWNER, 'golf');

		$this->assertCount(1, $entries);
		$this->assertSame('B-XY 123', $entries[0]['title']);
		$this->assertSame('Volkswagen Golf', $entries[0]['subline']);
		$this->assertStringEndsWith('/apps/nextfleet/?vehicle=' . $golf->getUuid(), $entries[0]['resourceUrl']);
		$this->assertStringEndsWith('/nextfleet/img/app.svg', $entries[0]['thumbnailUrl']);
	}

	/** Inside the app a vehicle is what someone is looking for; elsewhere files and mail come first. */
	public function testVehiclesComeFirstInsideTheApp(): void {
		$inside = $this->provider->getOrder('nextfleet.page.index', []);
		$outside = $this->provider->getOrder('files.view.index', []);

		$this->assertLessThan(0, $inside);
		$this->assertGreaterThan(0, $outside);
	}

	/** Named as the screen names it (src/utils/format.js `nameOf`): the make when there is no plate. */
	public function testAVehicleWithoutAPlateIsNamedByItsMake(): void {
		$this->vehicles->create(self::OWNER, ['manufacturer' => 'Volkswagen', 'model' => 'Golf']);

		$entries = $this->search(self::OWNER, 'golf');

		$this->assertSame('Volkswagen Golf', $entries[0]['title']);
		$this->assertSame('', $entries[0]['subline']);
	}

	/**
	 * A plate is typed the way it is remembered, and nobody remembers where the hyphen went.
	 *
	 * @return array<string, array{string}>
	 */
	public static function termsThatFindTheGolf(): array {
		return [
			'the plate as written' => ['B-XY 123'],
			'the plate without its separators' => ['bxy123'],
			'the plate with other separators' => ['B XY-123'],
			'part of the plate' => ['xy 1'],
			'the manufacturer' => ['Volks'],
			'make and model' => ['volkswagen golf'],
		];
	}

	/**
	 * @dataProvider termsThatFindTheGolf
	 */
	public function testAVehicleIsFoundByPlateOrMake(string $term): void {
		$this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'manufacturer' => 'Volkswagen', 'model' => 'Golf']);
		$this->vehicles->create(self::OWNER, ['plate' => 'M-AB 9', 'manufacturer' => 'Renault', 'model' => 'Zoe']);

		$this->assertSame(['B-XY 123'], array_column($this->search(self::OWNER, $term), 'title'));
	}

	/**
	 * A result carries the plate and the make, so it follows VIEW exactly as the overview does:
	 * someone else's vehicle is not found, a viewer's is, and a role the domain does not know widens
	 * nothing (docs/adr/0001-own-access-table.md).
	 */
	public function testItFindsOnlyWhatTheSearcherMayView(): void {
		$golf = $this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'model' => 'Golf']);

		$this->assertSame([], $this->search(self::STRANGER, 'golf'));

		$this->grant($golf, self::STRANGER, 'admin');
		$this->assertSame([], $this->search(self::STRANGER, 'golf'));

		$this->grant($golf, self::STRANGER, 'viewer');
		$this->assertSame(['B-XY 123'], array_column($this->search(self::STRANGER, 'golf'), 'title'));
	}

	/** The search box shows a few and asks for the rest with the cursor it was handed. */
	public function testMoreVehiclesThanTheLimitArriveInPages(): void {
		foreach (['B-XY 1', 'B-XY 2', 'B-XY 3'] as $plate) {
			$this->vehicles->create(self::OWNER, ['plate' => $plate, 'model' => 'Golf']);
		}

		$first = $this->answer(self::OWNER, 'golf', 2);
		$this->assertSame(['B-XY 1', 'B-XY 2'], array_column($first['entries'], 'title'));
		$this->assertTrue($first['isPaginated']);

		$rest = $this->answer(self::OWNER, 'golf', 2, $first['cursor']);
		$this->assertSame(['B-XY 3'], array_column($rest['entries'], 'title'));
	}

	/**
	 * A disposed vehicle has left the navigation (docs/ui.md), so its link would open the
	 * overview instead. A laid-up one is still there.
	 */
	public function testADisposedVehicleIsNotFound(): void {
		$this->vehicles->create(self::OWNER, ['plate' => 'B-XY 123', 'model' => 'Golf', 'lifecycle' => 'disposed', 'disposed_at' => '2025-06-30']);
		$this->vehicles->create(self::OWNER, ['plate' => 'B-XY 456', 'model' => 'Golf', 'lifecycle' => 'laid_up']);

		$this->assertSame(['B-XY 456'], array_column($this->search(self::OWNER, 'golf'), 'title'));
	}

	/** One grant, as the sharing UI will write it (M6). */
	private function grant(Vehicle $vehicle, string $grantee, string $role): void {
		$grant = new Access();
		$grant->setVehicleId((int)$vehicle->getId());
		$grant->setGrantee($grantee);
		$grant->setGranteeType(Access::USER);
		$grant->setRole($role);
		$grant->setCreatedBy($vehicle->getUserId());

		\OCP\Server::get(AccessMapper::class)->insert($grant);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function search(string $userId, string $term): array {
		return $this->answer($userId, $term)['entries'];
	}

	/**
	 * @return array{entries: list<array<string, mixed>>, isPaginated: bool, cursor: int|string|null}
	 */
	private function answer(string $userId, string $term, int $limit = 5, int|string|null $cursor = null): array {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn($limit);
		$query->method('getCursor')->willReturn($cursor);

		/** @var array{entries: list<array<string, mixed>>, isPaginated: bool, cursor: int|string|null} */
		return json_decode(json_encode($this->provider->search($user, $query), JSON_THROW_ON_ERROR), true);
	}
}
