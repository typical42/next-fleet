<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country;

use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\Generic\ServiceTemplates;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use OCA\NextFleet\Jurisdiction\MileageClaim;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\TestCase;

/**
 * The shared kit docs/contributing.md#what-a-country-owes-us requires: the same suite for every
 * jurisdiction, so a merged one is provably wired up rather than merely present.
 *
 * A country subclasses this in its own directory and names its profile. Everything asked here is
 * asked through `IJurisdiction` - what a country answers is its own business, that it answers at
 * all is ours.
 */
abstract class JurisdictionTestCase extends TestCase {
	/**
	 * The profile under test. Static so the coverage guard can ask a case which country it
	 * takes without running it.
	 */
	abstract public static function profile(): IJurisdiction;

	/**
	 * The kit's premise. A profile that throws "not implemented here" instead of answering
	 * fails against the interface, before a screen meets it - and so does one that stays
	 * silent when `IJurisdiction` grows a question.
	 */
	public function testItAnswersEveryQuestionTheInterfaceAsks(): void {
		$profile = static::profile();
		// What a jurisdiction states about itself, which is all this kit can ask blind. A
		// question that takes an argument - a plate to format, a date to price - needs a
		// fixture only the country has, so it is asked in the country's own case.
		$questions = array_filter(
			(new \ReflectionClass(IJurisdiction::class))->getMethods(),
			static fn (\ReflectionMethod $question): bool => $question->getNumberOfParameters() === 0,
		);
		$this->assertNotEmpty($questions);

		foreach ($questions as $question) {
			// Asked of the profile, not of the interface: an interface method is abstract and
			// reflection refuses to invoke it. The call is the assertion - an exception here
			// is the failure.
			$answer = (new \ReflectionMethod($profile, $question->getName()))->invoke($profile);

			if ($question->getReturnType()?->allowsNull() !== true) {
				$this->assertNotNull($answer, $question->getName() . '() answered nothing');
			}
		}
	}

	/**
	 * A key is written into `fleet_vehicles.jurisdiction`, which is `string(8)`, and read back
	 * out of the user's personal setting - compared literally both times. A key the column
	 * truncates is a vehicle that opens under a country nobody registered.
	 */
	public function testItsKeyIsSomethingTheColumnCanHold(): void {
		$key = static::profile()->key();

		$this->assertLessThanOrEqual(8, mb_strlen($key), $key . ' does not fit its column');
		$this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]*$/', $key);
	}

	/**
	 * Translated where it is shown and not here (docs/ui.md#languages), so whatever this
	 * answers reaches a dropdown as it is - the whitespace of a hand-edited file included.
	 */
	public function testItHasAName(): void {
		$name = static::profile()->displayName();

		$this->assertNotSame('', $name);
		$this->assertSame(trim($name), $name);
	}

	/**
	 * `km` or `h`, the two `VehicleService::WRITABLE['odo_unit']` takes - repeated here because
	 * that constant is private. Miles are a rendering of kilometres
	 * (docs/contributing.md#rules-that-keep-the-seam-honest), so a profile answering one would
	 * be refused by the very create it defaults.
	 */
	public function testItCountsInAUnitTheCoreAccepts(): void {
		$this->assertContains(static::profile()->odoUnit(), ['km', 'h']);
	}

	/**
	 * ISO 4217, or null where the jurisdiction has none. Null is the one way a profile says "I
	 * don't know" and the one thing callers test for: `''` or `'---'` is a gap wearing an
	 * answer's clothes, and it reaches the vehicle's currency column intact.
	 */
	public function testItsCurrencyIsAnIsoCodeOrAnHonestNull(): void {
		$currency = static::profile()->currency();

		$this->assertTrue(
			$currency === null || preg_match('/^[A-Z]{3}$/', $currency) === 1,
			var_export($currency, true) . ' is neither an ISO 4217 code nor an honest null',
		);
	}

	/**
	 * A jurisdiction either has a logbook ruleset that answers everything `ILogbookRules` asks or
	 * has none at all - a half-answered ruleset would flag trips for a requirement nobody stated.
	 * Two of the answers are read further, and by name: a later question that also returns a
	 * string is not thereby a URL.
	 */
	public function testItsLogbookRulesetIsCompleteOrHonestlyAbsent(): void {
		$rules = static::profile()->logbookRules();
		$questions = array_filter(
			(new \ReflectionClass(ILogbookRules::class))->getMethods(),
			static fn (\ReflectionMethod $question): bool => $question->getNumberOfParameters() === 0,
		);
		$this->assertNotEmpty($questions, 'the ruleset asks nothing - has the interface changed?');

		foreach ($rules === null ? [] : $questions as $question) {
			// The call is the assertion, as it is for the profile above: a ruleset answering
			// "not implemented here" fails here, before a trip meets it.
			$name = $question->getName();
			$answer = (new \ReflectionMethod($rules, $name))->invoke($rules);
			$this->assertNotNull($answer, $name . '() answered nothing');

			if ($name === 'lockDelayDays') {
				$this->assertGreaterThanOrEqual(0, $answer, 'a delay cannot run backwards');
			}
			if ($name === 'retentionMonths') {
				$this->assertGreaterThan(0, $answer, 'a record kept for no time is no retention period');
			}
			if ($name === 'sourceUrl') {
				// Cited by URL and never quoted (docs/contributing.md), and read long after this
				// release: the export prints it.
				$this->assertMatchesRegularExpression('#^https://\S+$#', $answer, 'the source is not a URL');
			}
		}
	}

	/**
	 * A jurisdiction either states its rates or has none - the generic profile's answer, under which
	 * a figure needing a rate is unavailable rather than zero (docs/contributing.md). A VAT rate is
	 * basis points or null for "not stated", never a fraction (docs/architecture.md#data-model).
	 */
	/**
	 * A jurisdiction either states how every vehicle type is inspected or has no scheme at all -
	 * the generic profile's answer, under which no vehicle is asked for an inspection date. Asked
	 * of every type the core writes: a scheme silent about a trailer leaves a trailer undue.
	 */
	public function testItsInspectionSchemeIsSoundOrHonestlyAbsent(): void {
		$scheme = static::profile()->inspectionScheme();
		if ($scheme === null) {
			$this->assertNull($scheme, 'a country without an inspection scheme states none');
			return;
		}

		// A template key names the translation of every reminder made from it, so one the
		// service templates already use would retitle an oil change as an inspection.
		$taken = array_map(static fn (ReminderTemplate $template): string => $template->key, (new ServiceTemplates())->all());
		foreach (VehicleService::VEHICLE_TYPES as $type) {
			$template = $scheme->template($type);

			$this->assertMatchesRegularExpression('/^[a-z][a-z0-9_]{0,31}$/', $template->key, 'the key does not fit template_key');
			$this->assertNotContains($template->key, $taken, $template->key . ' is a service template already');
			// Due on the sticker's month, never by the counter.
			$this->assertSame(Reminder::DATE, $template->mode, $type . ' is inspected by date');
			$this->assertGreaterThan(0, $template->recurMonths, $type . ' recurs at no interval');
			$this->assertNull($template->recurOdo);
			$this->assertNull($template->leadOdo);
			$this->assertGreaterThanOrEqual($template->recurMonths, $scheme->firstDueMonths($type), $type . ' is first due sooner than it recurs');
		}
	}

	public function testItsRatesAreSoundOrHonestlyAbsent(): void {
		$rates = static::profile()->rates();
		if ($rates === null) {
			$this->assertNull($rates, 'a country without rates states none');
			return;
		}

		$this->assertMatchesRegularExpression('#^https://\S+$#', $rates->vatSourceUrl(), 'the VAT source is not a URL');
		foreach (['2020-01-01', '2026-01-01'] as $day) {
			$rate = $rates->vatRateAt(new \DateTimeImmutable($day . 'T12:00:00Z'));
			$this->assertTrue(
				$rate === null || ($rate >= 0 && $rate < 10000),
				var_export($rate, true) . ' on ' . $day . ' is not a rate in basis points',
			);
		}
		foreach ($rates->vatFreeCategories() as $category) {
			$this->assertContains($category, ExpenseService::CATEGORIES, $category . ' is no expense category');
		}

		// Grams per litre, or null for "not stated"; electricity is the grid's, never a fuel's.
		$this->assertMatchesRegularExpression('#^https://\S+$#', $rates->emissionSourceUrl(), 'the emission source is not a URL');
		foreach (VehicleService::ENERGIES as $energy) {
			$factor = $rates->emissionFactorAt($energy, new \DateTimeImmutable('2026-01-01T12:00:00Z'));
			$this->assertTrue($factor === null || $factor > 0, $energy . ' burns at no factor');
		}
		$this->assertNull($rates->emissionFactorAt('electric', new \DateTimeImmutable('2026-01-01T12:00:00Z')));
		// Tenths of a cent per kilometre, or null for a type the country pays nothing stated for.
		$this->assertMatchesRegularExpression('#^https://\S+$#', $rates->mileageSourceUrl(), 'the mileage source is not a URL');
		foreach (VehicleService::VEHICLE_TYPES as $type) {
			$rate = $rates->mileageRateAt($type, new \DateTimeImmutable('2026-01-01T12:00:00Z'));
			$this->assertTrue($rate === null || $rate > 0, $type . ' is paid no rate');
		}
		$grid = $rates->gridFactor();
		if ($grid !== null) {
			$this->assertGreaterThanOrEqual(0, $grid['grams']);
			$this->assertMatchesRegularExpression('/^\d{4}$/', (string)$grid['year']);
			$this->assertMatchesRegularExpression('#^https://\S+$#', $grid['source'], 'the grid source is not a URL');
		}
	}

	/**
	 * A required field is a name the core can look up on the trip it is judging: a key of the
	 * wire form, or `plate`, the one required fact that lives on the vehicle. A name outside that
	 * would flag every trip forever with nothing on screen able to say what is missing.
	 */
	public function testItRequiresOnlyFieldsATripCanAnswerFor(): void {
		$rules = static::profile()->logbookRules();
		$vocabulary = [...array_keys((new Trip())->jsonSerialize()), 'plate'];

		foreach ([Trip::BUSINESS, Trip::PRIVATE, Trip::COMMUTE] as $category) {
			$fields = $rules?->mandatoryFields($category) ?? [];

			$this->assertSame(array_values(array_unique($fields)), $fields, $category . ' asks twice');
			foreach ($fields as $field) {
				$this->assertContains($field, $vocabulary, $category . ' requires ' . $field . ', which no trip states');
			}
		}

		// A category this release never writes is nobody's to rule on, and a trip carrying one
		// must still be saveable.
		$this->assertSame([], $rules?->mandatoryFields('a category nobody has written') ?? []);
	}

	/**
	 * Whatever a country prints, it prints every value as text and loads nothing: the page is
	 * the browser's to print (docs/adr/0005-no-pdf-library.md), and a renderer that reaches for a
	 * stylesheet, a font or a script leaks the logbook to wherever it points
	 * (docs/security.md#hostile-content). Every value came from a person.
	 */
	public function testItsLogbookPrintsWhatPeopleWroteAsTextAndLoadsNothing(): void {
		$renderer = static::profile()->logbookRenderer();
		if ($renderer === null) {
			$this->assertNull($renderer, 'a country without a logbook export prints nothing');
			return;
		}

		// Nothing in it that a reach check below looks for, so an escaped copy cannot trip one.
		$hostile = '"><b onmouseover="alert(1)">x</b><script>alert(2)</script>';
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => $hostile, 'model' => $hostile, 'jurisdiction' => static::profile()->key()]);
		$trip = Trip::fromRow([
			'id' => 1,
			'uuid' => '0195e2f1-0000-4000-8000-000000000011',
			'vehicle_id' => 7,
			'started_at' => 1767261600,
			'ended_at' => 1767265200,
			'end_odo' => 120450,
			'from_label' => $hostile,
			'to_label' => $hostile,
			'purpose' => $hostile,
			'partner' => $hostile,
			'category' => Trip::BUSINESS,
			'created_at' => 1767265300,
			'created_by' => 'alice',
		]);
		$html = $renderer->render(new LogbookReport(
			$vehicle,
			2026,
			[['trip' => $trip, 'missing' => [$hostile], 'late' => [['change' => 'edited', 'at' => 1767265400, 'fields' => [
				'purpose' => [$hostile, $hostile],
				$hostile => [$hostile, null],
			], 'offsets' => ['started_at_off' => 0, 'ended_at_off' => 0]]]]],
			[['from' => 1767225600, 'to' => null]],
			'https://example.org/"><script>alert(3)</script>',
		));

		$this->assertPrintsSafely($html);
	}

	/**
	 * A mileage claim is held to what a logbook is, and is printed only where the country also
	 * states a rate to value a trip at.
	 */
	public function testItsMileageClaimPrintsWhatPeopleWroteAsTextAndLoadsNothing(): void {
		$renderer = static::profile()->claimRenderer();
		if ($renderer === null) {
			$this->assertNull($renderer, 'a country without a claim prints none');
			return;
		}
		$this->assertNotNull(static::profile()->rates(), 'a claim with no rate to value a trip at');

		$hostile = '"><b onmouseover="alert(1)">x</b><script>alert(2)</script>';
		$vehicle = Vehicle::fromRow(['id' => 7, 'plate' => $hostile, 'model' => $hostile, 'jurisdiction' => static::profile()->key()]);
		$trip = Trip::fromRow([
			'id' => 1,
			'uuid' => '0195e2f1-0000-4000-8000-000000000011',
			'vehicle_id' => 7,
			'started_at' => 1767261600,
			'ended_at' => 1767265200,
			'distance' => 120,
			'from_label' => $hostile,
			'to_label' => $hostile,
			'purpose' => $hostile,
			'partner' => $hostile,
			'category' => Trip::BUSINESS,
		]);
		$html = $renderer->render(new MileageClaim(
			$vehicle,
			2026,
			[['trip' => $trip, 'kilometres' => 120, 'rate' => 300, 'amount' => 3600], ['trip' => $trip, 'kilometres' => null, 'rate' => null, 'amount' => null]],
			3600,
			120,
			'https://example.org/"><script>alert(3)</script>',
		));

		$this->assertPrintsSafely($html);
	}

	/** Nothing in `$html` that runs, and nothing it would load. */
	private function assertPrintsSafely(string $html): void {
		$this->assertStringStartsWith('<!doctype html>', strtolower($html));
		$this->assertStringNotContainsStringIgnoringCase('<script', $html);
		$this->assertStringNotContainsStringIgnoringCase('<b onmouseover', $html);
		foreach (['<link', '<iframe', '<object', '<embed', ' src=', 'url(', '@import'] as $reach) {
			$this->assertStringNotContainsStringIgnoringCase($reach, $html, 'the page reaches out with ' . $reach);
		}
	}
}
