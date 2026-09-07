<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Controller\PreferencesController;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\VehicleService;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The settings screen against the real user config: what the route stores, and what the next
 * vehicle is written under because of it. The two halves are in different classes and agree only
 * on a config key, so nothing but a run through both proves they still meet.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class PreferencesTest extends TestCase {
	/** Not Nextcloud accounts: user config is keyed by a string, and these are two of them. */
	private const OWNER = 'nextfleet-test-alice';
	private const STRANGER = 'nextfleet-test-bob';

	private VehicleService $vehicles;
	private IConfig $config;

	protected function setUp(): void {
		$container = (new Application())->getContainer();
		$this->vehicles = $container->get(VehicleService::class);
		$this->config = \OCP\Server::get(IConfig::class);
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	/** The setting and the rows this suite invents, gone for real. */
	private function forget(): void {
		foreach ([self::OWNER, self::STRANGER] as $person) {
			$this->config->deleteUserValue($person, Application::APP_ID, 'jurisdiction');
		}

		$db = \OCP\Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('fleet_vehicles')
			->where($qb->expr()->in(
				'user_id',
				$qb->createNamedParameter([self::OWNER, self::STRANGER], $qb::PARAM_STR_ARRAY),
			));
		$qb->executeStatement();
	}

	/**
	 * The controller as a route reaches it, with a session that is whoever is asking.
	 *
	 * @param array<string, mixed> $params
	 */
	private function controller(array $params = [], string $userId = self::OWNER): PreferencesController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new PreferencesController(
			Application::APP_ID,
			$request,
			(new Application())->getContainer()->get(\OCA\NextFleet\Service\PreferencesService::class),
			$session,
		);
	}

	/** A user who has never opened the screen sees this release's answer and every country. */
	public function testTheScreenOpensOnThisReleasesJurisdiction(): void {
		$response = $this->controller()->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(Jurisdictions::DEFAULT, $response->getData()['preferences']['jurisdiction']);
		$this->assertSame(['de', 'generic'], array_column($response->getData()['jurisdictions'], 'key'));
	}

	/**
	 * The point of the whole screen: PreferencesService writes a key VehicleService reads, and
	 * the two only ever meet in the user's config. A vehicle created before the change keeps what
	 * it had - a setting is a default for what comes next, never a migration of what exists.
	 */
	public function testWhatTheScreenSavesIsWhatTheNextVehicleIsWrittenUnder(): void {
		$before = $this->vehicles->create(self::OWNER, []);

		$this->assertSame(
			Http::STATUS_OK,
			$this->controller(['jurisdiction' => 'generic'])->update()->getStatus(),
		);
		$after = $this->vehicles->create(self::OWNER, []);

		$this->assertSame('generic', $after->getJurisdiction());
		$this->assertNull($after->getCurrency(), 'the generic profile states no currency');
		$this->assertSame(Jurisdictions::DEFAULT, $this->vehicles->find(self::OWNER, $before->getUuid())->getJurisdiction());
	}

	/** The screen offers the registration list, so the list is what it may send back. */
	public function testACountryNobodyRegisteredIsRefusedAndNothingIsStored(): void {
		$response = $this->controller(['jurisdiction' => 'zz'])->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			Jurisdictions::DEFAULT,
			$this->config->getUserValue(self::OWNER, Application::APP_ID, 'jurisdiction', Jurisdictions::DEFAULT),
		);
	}

	/**
	 * The route names no user, so a preference is reached through the session and through nothing
	 * else (docs/security.md). One person changing theirs leaves everybody else's where it was -
	 * the IDOR sweep walks these two routes but has no second user to check that with.
	 */
	public function testOnePersonsChoiceIsNotAnothers(): void {
		$this->controller(['jurisdiction' => 'generic'], self::OWNER)->update();

		$this->assertSame(
			Jurisdictions::DEFAULT,
			$this->controller([], self::STRANGER)->index()->getData()['preferences']['jurisdiction'],
		);
	}

	/**
	 * Nothing registers these classes (lib/AppInfo/Application.php), so the container has to build
	 * the whole chain from constructor types alone - and a route that cannot be built is a 500 no
	 * unit test sees.
	 */
	public function testTheControllerIsBuiltFromItsConstructorTypesAlone(): void {
		$this->assertInstanceOf(
			PreferencesController::class,
			(new Application())->getContainer()->get(PreferencesController::class),
		);
	}
}
