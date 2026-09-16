<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\ILogbookRules;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\Completeness;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Which of the fields a ruleset requires a trip leaves unstated. The ruleset here is a stub: what
 * Germany requires is tests/Country/De's, and the core measuring it must not know
 * (docs/contributing.md#rules-that-keep-the-seam-honest).
 */
class CompletenessTest extends TestCase {
	/** Every name a ruleset may require, so each case below can leave out the one it is about. */
	private const REQUIRED = ['plate', 'started_at', 'start_odo', 'end_odo', 'to_label', 'purpose', 'partner'];

	/**
	 * @param ?ILogbookRules $rules what every jurisdiction answers, null for one with no logbook
	 */
	private function completeness(?ILogbookRules $rules): Completeness {
		$profile = $this->createMock(IJurisdiction::class);
		$profile->method('logbookRules')->willReturn($rules);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($profile);

		return new Completeness(new Jurisdictions($container));
	}

	/** A ruleset that requires REQUIRED of a business trip and nothing of any other. */
	private function rules(): ILogbookRules {
		$rules = $this->createMock(ILogbookRules::class);
		$rules->method('mandatoryFields')->willReturnCallback(
			static fn (string $category): array => $category === Trip::BUSINESS ? self::REQUIRED : [],
		);

		return $rules;
	}

	private function vehicle(?string $plate = 'M-AB 1234'): Vehicle {
		// Any key: the container above hands every jurisdiction the same stub.
		return Vehicle::fromRow(['id' => 7, 'plate' => $plate, 'jurisdiction' => 'somewhere']);
	}

	/** A business trip that states everything, over which a case unsets what it is about. */
	private function trip(string $category = Trip::BUSINESS): Trip {
		$trip = new Trip();
		$trip->setStartedAt(1750000000);
		$trip->setStartOdo(120000);
		$trip->setEndOdo(120082);
		$trip->setToLabel('Augsburg');
		$trip->setPurpose('Kundentermin');
		$trip->setPartner('Muster GmbH');
		$trip->setCategory($category);

		return $trip;
	}

	public function testATripThatStatesEverythingMissesNothing(): void {
		$this->assertSame([], $this->completeness($this->rules())->missing($this->vehicle(), $this->trip()));
	}

	/** In the order the ruleset names them, which is the order the question asks them in. */
	public function testATripMissesWhatItLeftUnstated(): void {
		$trip = $this->trip();
		$trip->setPurpose(null);
		$trip->setToLabel(null);

		$this->assertSame(
			['to_label', 'purpose'],
			$this->completeness($this->rules())->missing($this->vehicle(), $trip),
		);
	}

	/**
	 * A distance trip has no counters by construction (docs/architecture.md#odometer-rules, rule
	 * 6), so a ruleset that asks for both finds both missing - the question is then to add them.
	 */
	public function testADistanceTripMissesBothCounters(): void {
		$trip = $this->trip();
		$trip->setStartOdo(null);
		$trip->setEndOdo(null);
		$trip->setDistance(82);

		$this->assertSame(
			['start_odo', 'end_odo'],
			$this->completeness($this->rules())->missing($this->vehicle(), $trip),
		);
	}

	/** The plate lives on the vehicle and is free text there, so an empty one is no plate. */
	public function testThePlateIsReadOffTheVehicle(): void {
		$rules = $this->rules();

		$this->assertSame(['plate'], $this->completeness($rules)->missing($this->vehicle(null), $this->trip()));
		$this->assertSame(['plate'], $this->completeness($rules)->missing($this->vehicle(''), $this->trip()));
	}

	/** What the ruleset asks of the category is what is measured - here, nothing. */
	public function testACategoryThatRequiresNothingMissesNothing(): void {
		$trip = $this->trip(Trip::PRIVATE);
		$trip->setPartner(null);
		$trip->setPurpose(null);

		$this->assertSame([], $this->completeness($this->rules())->missing($this->vehicle(null), $trip));
	}

	/** A jurisdiction with no logbook ruleset requires nothing of any trip. */
	public function testAJurisdictionWithNoRulesetMissesNothing(): void {
		$trip = $this->trip();
		$trip->setPartner(null);

		$this->assertSame([], $this->completeness(null)->missing($this->vehicle(), $trip));
	}
}
