<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Audit;
use OCA\NextFleet\Db\AuditMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The audit trail is append-only (docs/features.md#logbook-mode), and the mapper is where that
 * stops being a convention: the base class hands every table a checked update, a soft delete and
 * a restore, and a trail that can be edited is not evidence.
 */
class AuditMapperTest extends TestCase {
	/**
	 * The connection is a mock with no query builder, so a method that reached the database at
	 * all would fail this test rather than pass it.
	 */
	private function mapper(): AuditMapper {
		return new AuditMapper(
			$this->createMock(IDBConnection::class),
			$this->createMock(ITimeFactory::class),
			$this->createMock(ISecureRandom::class),
		);
	}

	/**
	 * @dataProvider writesTheBaseMapperOffers
	 */
	public function testAnAuditRowCannotBeChangedOnceItIsWritten(string $method): void {
		$row = new Audit();
		$row->setId(7);

		$this->expectException(\BadMethodCallException::class);

		$this->mapper()->$method($row, 1);
	}

	public static function writesTheBaseMapperOffers(): array {
		return [['updateChecked'], ['softDelete'], ['restoreChecked']];
	}
}
