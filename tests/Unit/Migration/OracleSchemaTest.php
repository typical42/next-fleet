<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Migration;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\OraclePlatform;
use OCP\IDBConnection;

/**
 * The same steps and expectations, as the steps see Oracle: the one database whose schema
 * differs, since its columns count bytes.
 */
class OracleSchemaTest extends SchemaTest {
	protected function platform(): AbstractPlatform {
		return new OraclePlatform();
	}

	protected function database(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_ORACLE);

		return $db;
	}

	protected function countsBytes(): bool {
		return true;
	}
}
