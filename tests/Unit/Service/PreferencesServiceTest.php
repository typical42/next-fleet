<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Jurisdiction\Generic;
use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\IRateProvider;
use OCA\NextFleet\Jurisdiction\IReportRenderer;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\OwnFiles;
use OCA\NextFleet\Service\PreferencesService;
use OCA\NextFleet\Tests\Stub\RegisteredProfiles;
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
	/** Two vehicles, as the uuids the client dismisses hints by (lib/Db/BaseMapper.php). */
	private const VEHICLE = '0195e2f1-0000-4000-8000-000000000001';
	private const OTHER = '0195e2f1-0000-4000-8000-000000000002';

	private IConfig&MockObject $config;

	/** This user's config, as the double holds it. @var array<string, string> */
	private array $stored = [];

	/**
	 * A config that remembers: a double that always answered the default would agree with a write
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

	/** The real registration list: only it can say what the screen may offer. */
	private function service(): PreferencesService {
		return new PreferencesService($this->config, RegisteredProfiles::jurisdictions(), $this->createMock(OwnFiles::class));
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

	/**
	 * The Reports screen offers the Fahrtenbuch only for a vehicle whose country prints one, and the
	 * profile is what knows that. The doubles take Germany's away, which no real profile does, so the
	 * flag cannot come from the key.
	 */
	public function testItSaysWhichJurisdictionsPrintALogbook(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(function (string $id): IJurisdiction {
			$profile = $this->createMock(IJurisdiction::class);
			$profile->method('key')->willReturn($id === Generic\Profile::class ? 'generic' : 'de');
			$profile->method('displayName')->willReturn($id);
			$profile->method('logbookRenderer')->willReturn($id === Generic\Profile::class ? $this->createMock(IReportRenderer::class) : null);

			return $profile;
		});

		$jurisdictions = (new PreferencesService($this->config, new Jurisdictions($container), $this->createMock(OwnFiles::class)))->forUser(self::USER)['jurisdictions'];

		$this->assertSame(['de' => false, 'generic' => true], array_column($jurisdictions, 'logbook_export', 'key'));
	}

	/**
	 * A mileage claim is offered where the country prints one and states a rate to value a trip
	 * at; either alone would open a 404.
	 */
	public function testItSaysWhichJurisdictionsPrintAMileageClaim(): void {
		$profiles = [];
		foreach (['both' => [true, true], 'no rates' => [true, false], 'no page' => [false, true]] as $key => [$page, $rates]) {
			$profile = $this->createMock(IJurisdiction::class);
			$profile->method('key')->willReturn($key);
			$profile->method('claimRenderer')->willReturn($page ? $this->createMock(IClaimRenderer::class) : null);
			$profile->method('rates')->willReturn($rates ? $this->createMock(IRateProvider::class) : null);
			$profiles[] = $profile;
		}
		$jurisdictions = $this->createMock(Jurisdictions::class);
		$jurisdictions->method('all')->willReturn($profiles);

		$answers = (new PreferencesService($this->config, $jurisdictions, $this->createMock(OwnFiles::class)))->forUser(self::USER)['jurisdictions'];

		$this->assertSame(['both' => true, 'no rates' => false, 'no page' => false], array_column($answers, 'mileage_claim', 'key'));
	}

	/**
	 * The overview asks whether to keep a logbook only where the country has a logbook ruleset - a
	 * tax office to keep it for - and the profile is what knows that, not the key.
	 */
	public function testItSaysWhichJurisdictionsHaveLogbookRules(): void {
		$profiles = [];
		foreach (['ruled' => true, 'free' => false] as $key => $ruled) {
			$profile = $this->createMock(IJurisdiction::class);
			$profile->method('key')->willReturn($key);
			$profile->method('logbookRules')->willReturn($ruled ? $this->createMock(ILogbookRules::class) : null);
			$profiles[] = $profile;
		}
		$jurisdictions = $this->createMock(Jurisdictions::class);
		$jurisdictions->method('all')->willReturn($profiles);

		$answers = (new PreferencesService($this->config, $jurisdictions, $this->createMock(OwnFiles::class)))->forUser(self::USER)['jurisdictions'];

		$this->assertSame(['ruled' => true, 'free' => false], array_column($answers, 'logbook_rules', 'key'));
	}

	/** A user who has never opened the screen already has the answer a vehicle would take. */
	public function testItReadsThisReleasesJurisdictionUntilSomebodyChoosesOne(): void {
		$this->assertSame(Jurisdictions::DEFAULT, $this->service()->forUser(self::USER)['preferences']['jurisdiction']);
	}

	/**
	 * A country a later release dropped is reported as stored, not corrected to the default: the
	 * screen shows what the next vehicle is really written under (lib/Service/VehicleService.php).
	 */
	public function testItReadsAStoredJurisdictionTheListNoLongerOffers(): void {
		$this->stored['jurisdiction'] = 'zz';

		$this->assertSame('zz', $this->service()->forUser(self::USER)['preferences']['jurisdiction']);
	}

	/** Nothing dismissed is an empty list, not null. */
	public function testItReadsNoDismissedHintUntilSomebodyDismissesOne(): void {
		$this->assertSame([], $this->service()->forUser(self::USER)['preferences']['dismissed_hints']);
	}

	public function testItWritesAJurisdictionTheListOffers(): void {
		$saved = $this->service()->write(self::USER, ['jurisdiction' => 'generic']);

		$this->assertSame(['jurisdiction' => 'generic'], $this->stored);
		$this->assertSame('generic', $saved['preferences']['jurisdiction']);
	}

	/** Stored, an unregistered key would put every later vehicle under the generic profile. */
	public function testItRefusesAJurisdictionNobodyRegistered(): void {
		try {
			$this->service()->write(self::USER, ['jurisdiction' => 'zz']);
			$this->fail('an unregistered jurisdiction was stored');
		} catch (\InvalidArgumentException) {
			$this->assertSame([], $this->stored);
		}
	}

	/** The screen sends the whole list it holds: a dismissal writes everything dismissed so far. */
	public function testItWritesTheHintsTheScreenHasDismissed(): void {
		$saved = $this->service()->write(self::USER, ['dismissed_hints' => [self::VEHICLE, self::OTHER]]);

		$this->assertSame([self::VEHICLE, self::OTHER], $saved['preferences']['dismissed_hints']);
		$this->assertSame([self::VEHICLE, self::OTHER], $this->service()->forUser(self::USER)['preferences']['dismissed_hints']);
	}

	/**
	 * A hint is dismissed per vehicle, so the list holds vehicle uuids and nothing else: a
	 * preference is no store for whatever arrives.
	 *
	 * @dataProvider notAListOfVehicles
	 */
	public function testItRefusesADismissalThatNamesNoVehicle(mixed $sent): void {
		try {
			$this->service()->write(self::USER, ['dismissed_hints' => $sent]);
			$this->fail('a dismissal naming no vehicle was stored');
		} catch (\InvalidArgumentException) {
			$this->assertSame([], $this->stored);
		}
	}

	/** The list is one config value: a thousand vehicles' hints, and no more (docs/security.md). */
	public function testItRefusesMoreDismissalsThanAThousand(): void {
		$many = array_map(static fn (int $i): string => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 1_001));
		$this->service()->write(self::USER, ['dismissed_hints' => array_slice($many, 0, 1_000)]);

		$this->expectExceptionObject(new \InvalidArgumentException('dismissed_hints holds 1000 vehicles at most'));
		$this->service()->write(self::USER, ['dismissed_hints' => $many]);
	}

	/**
	 * The Logbook Mode question is its own answer: dismissing what is missing from a vehicle says
	 * nothing about keeping a logbook with it, and the other way round.
	 */
	public function testTheLogbookQuestionIsDismissedApartFromTheMissingDetails(): void {
		$this->assertSame([], $this->service()->forUser(self::USER)['preferences']['dismissed_logbook_hints']);

		$saved = $this->service()->write(self::USER, ['dismissed_logbook_hints' => [self::VEHICLE]]);

		$this->assertSame([self::VEHICLE], $saved['preferences']['dismissed_logbook_hints']);
		$this->assertSame([], $saved['preferences']['dismissed_hints']);
		$this->assertSame([self::VEHICLE], $this->service()->forUser(self::USER)['preferences']['dismissed_logbook_hints']);
	}

	public function testALogbookDismissalNamesVehiclesAndNoMoreThanAThousand(): void {
		$many = array_map(static fn (int $i): string => sprintf('00000000-0000-4000-8000-%012d', $i), range(1, 1_001));

		try {
			$this->service()->write(self::USER, ['dismissed_logbook_hints' => ['nope']]);
			$this->fail('a dismissal naming no vehicle was stored');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('dismissed_logbook_hints holds something that is no vehicle uuid', $e->getMessage());
		}
		$this->expectExceptionObject(new \InvalidArgumentException('dismissed_logbook_hints holds 1000 vehicles at most'));
		$this->service()->write(self::USER, ['dismissed_logbook_hints' => $many]);
	}

	/**
	 * One request is one answer: a payload the route refuses changes nothing at all, or the screen
	 * would be told nothing was stored while a preference had already moved.
	 */
	public function testARefusedRequestStoresNoneOfItsOtherPreferences(): void {
		try {
			$this->service()->write(self::USER, ['jurisdiction' => 'generic', 'dismissed_hints' => ['nope']]);
			$this->fail('a refused request was written anyway');
		} catch (\InvalidArgumentException) {
			$this->assertSame([], $this->stored);
		}
	}

	/** Gross figures and the last twelve months are where everybody starts (docs/ui.md). */
	public function testItReadsGrossAndTheLastTwelveMonthsUntilSomebodyChooses(): void {
		$preferences = $this->service()->forUser(self::USER)['preferences'];

		$this->assertFalse($preferences['reclaim_vat']);
		$this->assertSame('last-12', $preferences['kpi_period']);
	}

	/** Nobody has an inbox until they choose its folder; the Inbox screen stays hidden until then. */
	public function testItReadsNoInboxFolderUntilSomebodyChoosesOne(): void {
		$this->assertNull($this->service()->forUser(self::USER)['preferences']['inbox_folder']);
	}

	public function testItWritesThatThisUserReclaimsVatAndTakesItBack(): void {
		$this->assertTrue($this->service()->write(self::USER, ['reclaim_vat' => true])['preferences']['reclaim_vat']);
		$this->assertTrue($this->service()->forUser(self::USER)['preferences']['reclaim_vat']);

		$this->assertFalse($this->service()->write(self::USER, ['reclaim_vat' => false])['preferences']['reclaim_vat']);
		$this->assertFalse($this->service()->forUser(self::USER)['preferences']['reclaim_vat']);
	}

	public function testItWritesThePeriodTheHeaderShows(): void {
		$this->service()->write(self::USER, ['kpi_period' => 'last-year']);

		$this->assertSame('last-year', $this->service()->forUser(self::USER)['preferences']['kpi_period']);
	}

	/**
	 * A period the header does not offer would leave it showing nothing selected, and a loose
	 * boolean would be some answer nobody gave.
	 *
	 * @dataProvider notAPreference
	 */
	public function testItRefusesAnAnswerThePreferenceDoesNotTake(string $key, mixed $sent): void {
		try {
			$this->service()->write(self::USER, [$key => $sent]);
			$this->fail("$key took an answer it does not offer");
		} catch (\InvalidArgumentException) {
			$this->assertSame([], $this->stored);
		}
	}

	/** @return iterable<string, array{string, mixed}> */
	public static function notAPreference(): iterable {
		yield 'VAT as a word' => ['reclaim_vat', 'yes'];
		yield 'VAT as a number' => ['reclaim_vat', 1];
		yield 'a period nobody offers' => ['kpi_period', 'last-week'];
		yield 'a period as a number' => ['kpi_period', 12];
		yield 'a grid factor as a word' => ['grid_factor', '120'];
		yield 'a grid factor with a fraction' => ['grid_factor', 120.5];
		yield 'a grid factor below nothing' => ['grid_factor', -1];
		yield 'a grid factor no grid has' => ['grid_factor', 5001];
		yield 'an inbox folder as a path' => ['inbox_folder', 'Belege'];
		yield 'an inbox folder as a word' => ['inbox_folder', '42'];
	}

	/**
	 * The electricity a person charges is theirs to state: a green tariff is not the country's
	 * average. No answer is the country's figure, and the screen shows it beside the field.
	 */
	public function testItWritesThisUsersGridFactorAndClearsIt(): void {
		$this->assertNull($this->service()->forUser(self::USER)['preferences']['grid_factor']);

		$this->service()->write(self::USER, ['grid_factor' => 120]);
		$this->assertSame(120, $this->service()->forUser(self::USER)['preferences']['grid_factor']);

		// Zero is a stated answer - a tariff from nothing but wind - and not a cleared one.
		$this->service()->write(self::USER, ['grid_factor' => 0]);
		$this->assertSame(0, $this->service()->forUser(self::USER)['preferences']['grid_factor']);

		$this->service()->write(self::USER, ['grid_factor' => null]);
		$this->assertNull($this->service()->forUser(self::USER)['preferences']['grid_factor']);
	}

	/** Each country's average travels with it, so the screen can say what an empty field means. */
	public function testEachJurisdictionStatesItsGridAverage(): void {
		$grids = array_column($this->service()->forUser(self::USER)['jurisdictions'], 'grid_factor', 'key');

		$this->assertSame(344, $grids['de']['grams'] ?? null);
		$this->assertNull($grids['generic']);
	}

	public function testAPeriodALaterReleaseDroppedReadsAsTheDefault(): void {
		$this->stored['kpi_period'] = 'last-week';

		$this->assertSame('last-12', $this->service()->forUser(self::USER)['preferences']['kpi_period']);
	}

	/** @return iterable<string, array{mixed}> */
	public static function notAListOfVehicles(): iterable {
		yield 'a single uuid rather than a list' => [self::VEHICLE];
		yield 'a plate' => [['B-XY 123']];
		yield 'a number' => [[42]];
		yield 'a uuid with a character too many' => [[self::VEHICLE . 'a']];
	}

	/**
	 * Nextcloud merges its own routing parameters into every request: fields this app does not
	 * know are noise, not a bad request.
	 */
	public function testItIgnoresWhatIsNotAPreference(): void {
		$this->service()->write(self::USER, ['_route' => 'nextfleet.preferences.update', 'plate' => 'B-XY 123']);

		$this->assertSame([], $this->stored);
	}
}
