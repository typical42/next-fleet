<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * `nextcloud/ocp` is pinned to the oldest supported major and feeds Psalm, not a server. Our
 * autoloader knows those stubs and PHPUnit registered it first, so what saves an integration
 * test from measuring them instead of the server is only the order `lib/base.php` leaves
 * behind - a fact about the server, not about anything this repo controls.
 */
class AutoloadingTest extends TestCase {
	/**
	 * @return iterable<string, array{class-string}>
	 */
	public static function ocpClasses(): iterable {
		yield 'IDBConnection' => [IDBConnection::class];
		// The one this suite has already been bitten by: it grew a method in NC 33.
		yield 'ISchemaWrapper' => [ISchemaWrapper::class];
		yield 'QBMapper' => [QBMapper::class];
	}

	/**
	 * @param class-string $class
	 * @dataProvider ocpClasses
	 */
	public function testOcpComesFromTheServerAndNotFromTheStubs(string $class): void {
		$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';

		$this->assertStringStartsWith(
			$root . '/lib/public/',
			(string)(new ReflectionClass($class))->getFileName(),
			$class . ' was loaded from somewhere other than the server',
		);
	}
}
