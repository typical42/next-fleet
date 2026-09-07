<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Country;

use OCA\NextFleet\Jurisdiction\Jurisdictions;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * What makes the kit a promise rather than a directory: a profile registered in `Jurisdictions`
 * and written up in no case here is the "merely present" jurisdiction
 * docs/contributing.md#what-a-country-owes-us refuses.
 *
 * Only that direction. A case covering a profile `lib/` does not ship is a test fixture, which
 * is exactly what a UK one is meant to be (docs/adr/0002-uk-is-a-test-jurisdiction.md).
 */
class KitCoverageTest extends TestCase {
	public function testEveryRegisteredProfileTakesTheKit(): void {
		$covered = [];
		foreach ($this->cases() as $case) {
			$covered[] = $case::profile()->key();
		}
		$this->assertNotEmpty($covered, 'no country case was discovered - has the layout changed?');

		foreach ($this->jurisdictions()->all() as $profile) {
			$this->assertContains(
				$profile->key(),
				$covered,
				$profile->key() . ' is registered and takes no case in ' . basename(__DIR__) . '/',
			);
		}
	}

	/**
	 * One directory per country, PSR-4 all the way down, as `De/` and `Generic/` are - the
	 * layout docs/contributing.md#still-one-directory-per-country asks a jurisdiction for.
	 *
	 * @return list<class-string<JurisdictionTestCase>>
	 */
	private function cases(): array {
		$cases = [];
		foreach (glob(__DIR__ . '/*/*Test.php') ?: [] as $file) {
			$class = __NAMESPACE__ . '\\'
				. str_replace('/', '\\', substr($file, strlen(__DIR__) + 1, -strlen('.php')));

			if (is_subclass_of($class, JurisdictionTestCase::class)) {
				/** @var class-string<JurisdictionTestCase> $class */
				$cases[] = $class;
			}
		}

		return $cases;
	}

	/** The app's container builds what it is asked for; profiles ask it for nothing yet. */
	private function jurisdictions(): Jurisdictions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			/** @param class-string $id */
			static fn (string $id): object => new $id(),
		);

		return new Jurisdictions($container);
	}
}
