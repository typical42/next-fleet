<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Import;

use OCA\NextFleet\Import\Proposal;
use PHPUnit\Framework\TestCase;

/**
 * What the preview shows of a row and whether the import writes it.
 */
class ProposalTest extends TestCase {
	public function testADuplicateIsCreatedOnlyWhenTheRequestIncludesDuplicates(): void {
		$new = new Proposal(Proposal::EXPENSE, ['amount' => 14000], 2);
		$duplicate = new Proposal(Proposal::EXPENSE, ['amount' => 14000], 3, Proposal::DUPLICATE);
		$unreadable = new Proposal(Proposal::EXPENSE, [], 4, Proposal::UNREADABLE, 'number', 'Cost');

		$this->assertTrue($new->creates(false));
		$this->assertFalse($duplicate->creates(false));
		$this->assertTrue($duplicate->creates(true));
		$this->assertFalse($unreadable->creates(true));
	}

	public function testTheWireFormNamesTheRowItsOutcomeAndTheCellToBlame(): void {
		$unreadable = new Proposal(Proposal::ENERGY, ['odo' => 52000], 9, Proposal::UNREADABLE, 'date', 'Date');

		$this->assertSame([
			'row' => 9,
			'kind' => 'energy',
			'fields' => ['odo' => 52000],
			'outcome' => 'unreadable',
			'reason' => 'date',
			'column' => 'Date',
		], $unreadable->wire());
	}

	/** `fields` is an object in the OpenAPI document, so a row with none must not leave as `[]`. */
	public function testNoFieldsLeaveAsAnEmptyObject(): void {
		$unreadable = new Proposal(Proposal::ENERGY, [], 4, Proposal::UNREADABLE, 'date', 'Date');

		$this->assertSame('{"row":4,"kind":"energy","fields":{},"outcome":"unreadable","reason":"date","column":"Date"}', json_encode($unreadable->wire()));
	}
}
