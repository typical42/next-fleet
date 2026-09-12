<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
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
}
