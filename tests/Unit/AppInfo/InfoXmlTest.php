<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\AppInfo;

use DOMDocument;
use LibXMLError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;

class InfoXmlTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';

	/**
	 * The app store rejects an app whose info.xml does not validate, and so does
	 * `occ app:enable`. tests/schema/info.xsd is the store's own schema, vendored so
	 * this runs offline.
	 */
	public function testValidatesAgainstTheAppStoreSchema(): void {
		$doc = new DOMDocument();
		$this->assertTrue($doc->load(self::ROOT . '/appinfo/info.xml'), 'appinfo/info.xml is not well-formed');

		$previous = libxml_use_internal_errors(true);
		$valid = $doc->schemaValidate(self::ROOT . '/tests/schema/info.xsd');
		$messages = array_map(
			static fn (LibXMLError $error): string => trim($error->message),
			libxml_get_errors(),
		);
		libxml_clear_errors();
		libxml_use_internal_errors($previous);

		$this->assertTrue($valid, implode("\n", $messages));
	}

	/**
	 * The schema accepts any non-empty string, so it cannot tell a description from a
	 * placeholder. The app store reviewer can.
	 */
	public function testCarriesNoPlaceholders(): void {
		$xml = (string)file_get_contents(self::ROOT . '/appinfo/info.xml');

		$this->assertStringNotContainsString('TODO', $xml);
	}

	/**
	 * docs/legal.md: the licence has to read the same everywhere, and the store's
	 * `agpl` shorthand is deprecated.
	 */
	public function testLicenceMatchesTheRestOfTheRepository(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$this->assertSame('AGPL-3.0-or-later', (string)$info->licence);
		$this->assertSame('AGPL-3.0-or-later', $this->licenceOf('/composer.json'));
		$this->assertSame('AGPL-3.0-or-later', $this->licenceOf('/package.json'));
	}

	/**
	 * `occ` learns a command from this file and from nowhere else, so a command class that is
	 * not listed here is a command nobody can run - and the class is loadable, so nothing tells
	 * anyone it exists.
	 */
	public function testEveryConsoleCommandIsRegistered(): void {
		$info = simplexml_load_file(self::ROOT . '/appinfo/info.xml');
		$this->assertNotFalse($info);

		$registered = array_map('strval', $info->xpath('/info/commands/command') ?: []);

		// Order is the file's own business - what matters is that the two lists hold the same
		// classes.
		$this->assertEqualsCanonicalizing($this->commandClasses(), $registered);
	}

	/**
	 * Every class under lib/Command/ that `occ` could run. A stub that is not a Command yet is
	 * not one of them.
	 *
	 * @return list<string>
	 */
	private function commandClasses(): array {
		$classes = [];
		foreach (glob(self::ROOT . '/lib/Command/*.php') ?: [] as $file) {
			$class = 'OCA\\NextFleet\\Command\\' . basename($file, '.php');
			if (is_subclass_of($class, Command::class)) {
				$classes[] = $class;
			}
		}

		return $classes;
	}

	private function licenceOf(string $path): string {
		$manifest = json_decode((string)file_get_contents(self::ROOT . $path), true);
		$this->assertIsArray($manifest, $path . ' is not readable JSON');

		return (string)($manifest['license'] ?? '');
	}
}
