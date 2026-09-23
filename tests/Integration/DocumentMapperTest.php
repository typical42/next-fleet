<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Document;
use OCA\NextFleet\Db\DocumentMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * M5's documents through their mapper and back. No route writes the table yet, so this is the
 * only proof its columns and reads hold.
 *
 * It writes to the instance it runs against (docs/development.md#testing).
 */
class DocumentMapperTest extends TestCase {
	private const OWNER = 'nextfleet-test-documents';
	/** Far above any id the dev fleet reaches, so the reads meet only this test's rows. */
	private const VEHICLE = 990001;
	private const OTHER_VEHICLE = 990002;

	private DocumentMapper $documents;

	protected function setUp(): void {
		$this->forgetTestRows();
		$this->documents = (new Application())->getContainer()->get(DocumentMapper::class);
	}

	protected function tearDown(): void {
		$this->forgetTestRows();
	}

	private function forgetTestRows(): void {
		$qb = \OCP\Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('fleet_documents')->where($qb->expr()->eq('created_by', $qb->createNamedParameter(self::OWNER)));
		$qb->executeStatement();
	}

	private function attach(int $vehicleId, int $fileId, string $kind, ?string $linkedType = null, ?int $linkedId = null): Document {
		$document = new Document();
		$document->setVehicleId($vehicleId);
		$document->setFileId($fileId);
		$document->setKind($kind);
		$document->setLinkedType($linkedType);
		$document->setLinkedId($linkedId);
		$document->setCreatedBy(self::OWNER);

		return $this->documents->insert($document);
	}

	public function testADocumentRoundTrips(): void {
		$written = $this->attach(self::VEHICLE, 812, Document::RECEIPT, Document::MAINTENANCE, 17);

		$read = $this->documents->findByUuid($written->getUuid());

		$this->assertSame(self::VEHICLE, $read->getVehicleId());
		$this->assertSame(812, $read->getFileId());
		$this->assertSame('receipt', $read->getKind());
		$this->assertSame('maintenance', $read->getLinkedType());
		$this->assertSame(17, $read->getLinkedId());
	}

	public function testAVehicleListsItsOwnLiveDocumentsInTheOrderTheyCame(): void {
		$registration = $this->attach(self::VEHICLE, 1, Document::REGISTRATION);
		$receipt = $this->attach(self::VEHICLE, 2, Document::RECEIPT, Document::EXPENSE, 5);
		$this->attach(self::OTHER_VEHICLE, 3, Document::MANUAL);
		$removed = $this->attach(self::VEHICLE, 4, Document::PHOTO);
		$this->documents->softDelete($removed, $removed->getUpdatedAt());

		$uuids = array_map(static fn (Document $d): string => $d->getUuid(), $this->documents->findByVehicle(self::VEHICLE));

		$this->assertSame([$registration->getUuid(), $receipt->getUuid()], $uuids);
	}

	public function testAnEntryFindsTheDocumentsLinkedToIt(): void {
		$invoice = $this->attach(self::VEHICLE, 1, Document::RECEIPT, Document::MAINTENANCE, 17);
		$this->attach(self::VEHICLE, 2, Document::RECEIPT, Document::EXPENSE, 17);
		$this->attach(self::VEHICLE, 3, Document::REGISTRATION);
		// The same entry id on another vehicle is another vehicle's row.
		$this->attach(self::OTHER_VEHICLE, 4, Document::RECEIPT, Document::MAINTENANCE, 17);

		$uuids = array_map(
			static fn (Document $d): string => $d->getUuid(),
			$this->documents->findLinked(self::VEHICLE, Document::MAINTENANCE, 17),
		);

		$this->assertSame([$invoice->getUuid()], $uuids);
	}
}
