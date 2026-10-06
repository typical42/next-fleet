<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Jurisdiction;

use OCA\NextFleet\Jurisdiction\PrintedPage;
use PHPUnit\Framework\TestCase;

class PrintedPageTest extends TestCase {
	public function testTextIsEscapedForAnAttributeToo(): void {
		$this->assertSame('&lt;b&gt; &amp; &quot;x&quot; &apos;y&apos;', PrintedPage::text('<b> & "x" \'y\''));
	}

	/** A broken byte from an import still prints, as U+FFFD, rather than emptying the cell. */
	public function testInvalidUtf8IsReplacedNotDropped(): void {
		$this->assertSame("a\u{FFFD}b", PrintedPage::text("a\xC3b"));
	}

	public function testNothingIsAnEmptyCell(): void {
		$this->assertSame('', PrintedPage::text(null));
	}

	public function testARowNamesOnlyTheClassesItHas(): void {
		$this->assertSame(
			'<tr><td>a</td><td class="number">1</td></tr>',
			PrintedPage::row(false, [['', 'a'], ['number', '1']]),
		);
	}

	public function testAVoidedRowSaysSo(): void {
		$this->assertSame('<tr class="voided"><td>a</td></tr>', PrintedPage::row(true, [['', 'a']]));
	}

	/** The page loads nothing (docs/security.md#hostile-content). */
	public function testTheLogbookSheetLoadsNothing(): void {
		$this->assertStringContainsString('A4 landscape', PrintedPage::LOGBOOK_STYLE);
		$this->assertStringNotContainsString('url(', PrintedPage::LOGBOOK_STYLE);
		$this->assertStringNotContainsString('@import', PrintedPage::LOGBOOK_STYLE);
	}
}
