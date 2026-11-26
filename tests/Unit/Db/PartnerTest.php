<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\Trip;
use PHPUnit\Framework\TestCase;

/**
 * Which trip texts name the same record (ADR 0013): equal after trimming and Unicode NFC, case
 * kept, so a variant spelling stays its own record until the owner merges it.
 */
class PartnerTest extends TestCase {
	public function testTrimsTheText(): void {
		$this->assertSame('Müller GmbH', Partner::nameOf(" \tMüller GmbH \n"));
	}

	public function testComposedAndDecomposedAreOneName(): void {
		$decomposed = "Mu\u{0308}ller";

		$this->assertSame("M\u{00FC}ller", Partner::nameOf($decomposed));
	}

	public function testKeepsTheCase(): void {
		$this->assertSame('müller', Partner::nameOf('müller'));
		$this->assertNotSame(Partner::nameOf('Müller'), Partner::nameOf('müller'));
	}

	/** @dataProvider nobody */
	public function testNamesNobodyWhereTheTextIsEmpty(?string $text): void {
		$this->assertNull(Partner::nameOf($text));
	}

	/** @return iterable<string, array{?string}> */
	public static function nobody(): iterable {
		yield 'null' => [null];
		yield 'empty' => [''];
		yield 'blank' => [" \t "];
	}

	public function testATripNamesTheRecordItLinks(): void {
		$trip = new Trip();
		$trip->linkPartner(Partner::fromRow(['id' => 4, 'uuid' => 'p-4']));

		$this->assertSame([4, 'p-4'], [$trip->getPartnerId(), $trip->jsonSerialize()['partner_uuid']]);
	}

	/** The id stays as a mark of what was deleted; no answer names the record. */
	public function testATripKeepsTheIdOfADeletedRecordButNamesNone(): void {
		$trip = new Trip();
		$trip->linkPartner(Partner::fromRow(['id' => 4, 'uuid' => 'p-4', 'deleted_at' => 1750000000]));

		$this->assertSame([4, null], [$trip->getPartnerId(), $trip->jsonSerialize()['partner_uuid']]);
	}
}
