<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Jurisdiction;

use OCA\NextFleet\Jurisdiction\De;
use OCA\NextFleet\Jurisdiction\Generic;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The registration list docs/contributing.md#still-one-directory-per-country promises: one place
 * that knows which profiles exist, and an answer for every key a vehicle can carry - including
 * the keys of jurisdictions this release has never heard of.
 */
class JurisdictionsTest extends TestCase {
	/**
	 * A container as the app's own is: it builds what it is asked for. Profiles take no
	 * dependencies today, which is why the seam resolves through a container rather than
	 * `new` - the first one that does needs no caller to change.
	 */
	private function jurisdictions(): Jurisdictions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			/** @param class-string $id */
			static fn (string $id): object => new $id(),
		);

		return new Jurisdictions($container);
	}

	public function testAKnownKeyResolvesToItsProfile(): void {
		$this->assertInstanceOf(De\Profile::class, $this->jurisdictions()->get('de'));
		$this->assertInstanceOf(Generic\Profile::class, $this->jurisdictions()->get('generic'));
	}

	/**
	 * A vehicle whose country left a later release must still open, so an unknown key is the
	 * generic profile and never an exception.
	 */
	public function testAnUnknownKeyResolvesToTheGenericProfile(): void {
		$this->assertInstanceOf(Generic\Profile::class, $this->jurisdictions()->get('uk'));
		$this->assertInstanceOf(Generic\Profile::class, $this->jurisdictions()->get(''));
	}

	/** A profile that names itself one thing and is registered under another is unreachable. */
	public function testEveryProfileAnswersUnderTheKeyItIsRegisteredWith(): void {
		$jurisdictions = $this->jurisdictions();

		foreach ($jurisdictions->all() as $profile) {
			$resolved = $jurisdictions->get($profile->key());
			$this->assertInstanceOf($profile::class, $resolved);
			$this->assertSame($profile->key(), $resolved->key());
		}
	}

	public function testTheRegistrationListHoldsBothProfilesAndTheDefaultIsOneOfThem(): void {
		$keys = array_map(
			static fn (IJurisdiction $profile): string => $profile->key(),
			$this->jurisdictions()->all(),
		);

		$this->assertSame(['de', 'generic'], $keys);
		$this->assertContains(Jurisdictions::DEFAULT, $keys);
		$this->assertContains(Jurisdictions::FALLBACK, $keys);
	}

	/**
	 * A key is written into `fleet_vehicles.jurisdiction`, which is `string(8)`
	 * (tests/Unit/Migration/SchemaTest.php). A longer one would be refused by the create it
	 * defaults, and read back as something else from the personal setting.
	 */
	public function testNoKeyIsLongerThanItsColumn(): void {
		foreach ($this->jurisdictions()->all() as $profile) {
			$this->assertLessThanOrEqual(8, mb_strlen($profile->key()), $profile->key() . ' does not fit its column');
		}
	}

	/** What a new vehicle takes when the create sheet does not ask (docs/ui.md). */
	public function testGermanyStatesEurosAndKilometres(): void {
		$de = $this->jurisdictions()->get('de');

		$this->assertSame('EUR', $de->currency());
		$this->assertSame('km', $de->odoUnit());
		$this->assertNotSame('', $de->displayName());
	}

	/**
	 * "I don't know" is an answer (docs/contributing.md): under the generic profile a vehicle
	 * states its own currency, and nothing about that is an error.
	 */
	public function testTheGenericProfileHasNoCurrencyAndStillAnswersEverythingElse(): void {
		$generic = $this->jurisdictions()->get('generic');

		$this->assertNull($generic->currency());
		$this->assertSame('km', $generic->odoUnit());
		$this->assertNotSame('', $generic->displayName());
	}
}
