<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\PreferencesService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * What the personal settings screen reads and what it may write: one user's own choices, and the
 * vocabulary each of them is picked from.
 */
class PreferencesServiceTest extends TestCase {
	private const USER = 'alice';

	private IConfig&MockObject $config;

	/** This user's config, as the double holds it. @var array<string, string> */
	private array $stored = [];

	/**
	 * A config that really remembers, because half of what this service does is read back what it
	 * wrote a moment earlier - a double that always answered the default would agree with a write
	 * that went nowhere.
	 */
	protected function setUp(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(
			function (string $userId, string $appId, string $key, string $default): string {
				$this->assertSame(Application::APP_ID, $appId, 'a preference was read from another app');

				return $userId === self::USER ? ($this->stored[$key] ?? $default) : $default;
			},
		);
		$this->config->method('setUserValue')->willReturnCallback(
			function (string $userId, string $appId, string $key, string $value): void {
				$this->assertSame(self::USER, $userId);
				$this->assertSame(Application::APP_ID, $appId, 'a preference was stored under another app');
				$this->stored[$key] = $value;
			},
		);
	}

	/**
	 * The real registration list, as VehicleServiceTest uses it: what the screen may offer is a
	 * question only the list can answer, and a stubbed one would prove nothing.
	 */
	private function service(): PreferencesService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			/** @param class-string $id */
			static fn (string $id): object => new $id(),
		);

		return new PreferencesService($this->config, new Jurisdictions($container));
	}

	/**
	 * The dropdown is fed by the registration list and by nothing else, so a country added under
	 * lib/Jurisdiction/ reaches the screen without a second edit (docs/contributing.md).
	 */
	public function testItOffersEveryRegisteredJurisdiction(): void {
		$jurisdictions = $this->service()->forUser(self::USER)['jurisdictions'];

		$this->assertSame(['de', 'generic'], array_column($jurisdictions, 'key'));
		foreach ($jurisdictions as $jurisdiction) {
			$this->assertNotSame('', $jurisdiction['name'], $jurisdiction['key'] . ' is offered unnamed');
		}
	}

	/** A user who has never opened the screen already has the answer a vehicle would take. */
	public function testItReadsThisReleasesJurisdictionUntilSomebodyChoosesOne(): void {
		$this->assertSame(Jurisdictions::DEFAULT, $this->service()->forUser(self::USER)['preferences']['jurisdiction']);
	}

	/**
	 * A country that left a later release is reported as it stands rather than corrected to the
	 * default: what the screen shows has to be what the next vehicle is really written under
	 * (lib/Service/VehicleService.php), and the dropdown offering no match is the honest answer.
	 */
	public function testItReadsAStoredJurisdictionTheListNoLongerOffers(): void {
		$this->stored['jurisdiction'] = 'zz';

		$this->assertSame('zz', $this->service()->forUser(self::USER)['preferences']['jurisdiction']);
	}

	public function testItWritesAJurisdictionTheListOffers(): void {
		$saved = $this->service()->write(self::USER, ['jurisdiction' => 'generic']);

		$this->assertSame(['jurisdiction' => 'generic'], $this->stored);
		$this->assertSame('generic', $saved['preferences']['jurisdiction']);
	}

	/**
	 * The screen offers the list and nothing else, so a value from anywhere else is a bad request
	 * - and storing it would leave every vehicle created afterwards under the generic profile
	 * with nobody having asked for that.
	 */
	public function testItRefusesAJurisdictionNobodyRegistered(): void {
		try {
			$this->service()->write(self::USER, ['jurisdiction' => 'zz']);
			$this->fail('an unregistered jurisdiction was stored');
		} catch (\InvalidArgumentException) {
			$this->assertSame([], $this->stored);
		}
	}

	/**
	 * Nextcloud merges its own routing parameters into every request, so the payload always
	 * carries fields this app knows nothing about. They are not a bad request; they are noise.
	 */
	public function testItIgnoresWhatIsNotAPreference(): void {
		$this->service()->write(self::USER, ['_route' => 'nextfleet.preferences.update', 'plate' => 'B-XY 123']);

		$this->assertSame([], $this->stored);
	}
}
