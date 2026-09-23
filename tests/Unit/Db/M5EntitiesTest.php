<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Document;
use PHPUnit\Framework\TestCase;

/**
 * What M5's columns mean when they come back from the database, before any service reads them.
 */
class M5EntitiesTest extends TestCase {
	public function testADocumentReadsItsIdsAsIntegers(): void {
		$document = Document::fromRow([
			'vehicle_id' => '4', 'file_id' => '812', 'kind' => Document::RECEIPT,
			'linked_type' => Document::MAINTENANCE, 'linked_id' => '17',
		]);

		$this->assertSame(4, $document->getVehicleId());
		$this->assertSame(812, $document->getFileId());
		$this->assertSame('receipt', $document->getKind());
		$this->assertSame('maintenance', $document->getLinkedType());
		$this->assertSame(17, $document->getLinkedId());
	}

	public function testAnUnlinkedDocumentBelongsToTheVehicleAlone(): void {
		$document = Document::fromRow(['vehicle_id' => '4', 'file_id' => '9', 'kind' => Document::REGISTRATION]);

		$this->assertNull($document->getLinkedType());
		$this->assertNull($document->getLinkedId());
	}

	public function testTheKindsAndLinksAreTheDataModels(): void {
		$this->assertSame(['registration', 'insurance', 'manual', 'receipt', 'photo'], Document::KINDS);
		$this->assertSame(['energy', 'maintenance', 'expense'], Document::LINKABLE);
	}
}
