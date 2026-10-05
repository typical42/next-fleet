<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\Format;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `--output` as every admin command reads it: a plain table by default, or one JSON document.
 */
class FormatTest extends TestCase {
	private const ROWS = [
		['uuid' => 'a1', 'plate' => 'NF 1', 'deleted_at' => null],
		['uuid' => 'b2', 'plate' => 'NF 2', 'deleted_at' => '2026-10-04T12:00:00+00:00'],
	];

	public function testPlainIsATableWithAColumnPerKeyAndNullAsEmpty(): void {
		$tester = $this->format([]);

		$this->assertSame(0, $tester->getStatusCode());
		$lines = array_map('trim', explode("\n", trim($tester->getDisplay())));
		$this->assertMatchesRegularExpression('/^\|\s*uuid\s*\|\s*plate\s*\|\s*deleted_at\s*\|$/', $lines[1]);
		$this->assertMatchesRegularExpression('/^\|\s*a1\s*\|\s*NF 1\s*\|\s*\|$/', $lines[3]);
		$this->assertStringContainsString('2026-10-04T12:00:00+00:00', $lines[4]);
	}

	public function testPlainSaysSoWhenThereIsNothing(): void {
		$tester = $this->format([], []);

		$this->assertSame("Nothing here.\n", $tester->getDisplay());
	}

	public function testJsonIsOneDocumentOnOneLine(): void {
		$tester = $this->format(['--output' => 'json']);

		$this->assertSame(json_encode(self::ROWS) . "\n", $tester->getDisplay());
	}

	public function testJsonPrettyIsTheSameDocumentIndented(): void {
		$tester = $this->format(['--output' => 'json_pretty']);

		$this->assertSame(self::ROWS, json_decode($tester->getDisplay(), true));
		$this->assertStringContainsString("\n    {\n", $tester->getDisplay());
	}

	public function testAnEmptyListIsAnEmptyJsonArray(): void {
		$tester = $this->format(['--output' => 'json'], []);

		$this->assertSame("[]\n", $tester->getDisplay());
	}

	/** Slashes and non-ASCII stay as typed: a plate is read by a person, not a parser alone. */
	public function testJsonLeavesSlashesAndUmlautsAlone(): void {
		$tester = $this->format(['--output' => 'json'], [['plate' => 'MÜ/AB 1']]);

		$this->assertSame('[{"plate":"MÜ/AB 1"}]' . "\n", $tester->getDisplay());
	}

	/**
	 * A plate is whatever a user typed. Raw, an OSC 52 sequence would rewrite the admin's clipboard
	 * and a console tag would restyle or relink the table.
	 */
	public function testATableShowsNoControlCharacterAndNoConsoleTag(): void {
		$tester = $this->format([], [['plate' => "\e]52;c;cm0gLXJmIH4K\x07<href=https://evil.example>NF 1</>\u{9B}2J"]]);

		$display = $tester->getDisplay(true);
		$this->assertDoesNotMatchRegularExpression('/[\x{00}-\x{09}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u', $display);
		$this->assertStringContainsString('<href=https://evil.example>NF 1</>', $display);
	}

	public function testSafeKeepsNewlinesAndTextAsTyped(): void {
		$this->assertSame("MÜ/AB 1\nzwei", Format::safe("MÜ/AB 1\nzwei"));
		$this->assertSame("a\u{FFFD}b\u{FFFD}c", Format::safe("a\x1Bb\u{85}c"));
		$this->assertSame('<comment>x</>', (new OutputFormatter(true))->format(Format::safe('<comment>x</>')));
	}

	/** C1 and the bidi controls are escaped in JSON too, which leaves C0 to json_encode. */
	public function testJsonEscapesWhatATerminalWouldActOn(): void {
		$tester = $this->format(['--output' => 'json'], [['plate' => "M\u{9B}2J\u{202E}Ü\x7F"]]);

		$this->assertSame('[{"plate":"M\\u009b2J\\u202eÜ\\u007f"}]' . "\n", $tester->getDisplay());
		$this->assertSame([['plate' => "M\u{9B}2J\u{202E}Ü\x7F"]], json_decode($tester->getDisplay(), true));
	}

	public function testSafeReplacesBidiOverrides(): void {
		$this->assertSame("a\u{FFFD}b\u{FFFD}c", Format::safe("a\u{202E}b\u{2066}c"));
	}

	/** Not UTF-8 at all: an encoded C1 and a bidi override beside a stray byte still go. */
	public function testSafeCleansBytesThatAreNotUtf8(): void {
		$safe = Format::safe("\xFF ab\x1B \xC2\x9D52;c;Zm9v\xC2\x9C \u{202E}x");

		$this->assertTrue(mb_check_encoding($safe, 'UTF-8'));
		$this->assertDoesNotMatchRegularExpression('/[\x{00}-\x{09}\x{0B}-\x{1F}\x{7F}-\x{9F}\x{202A}-\x{202E}]/u', $safe);
	}

	/** On stderr, so a script reading JSON from stdout reads no error text as JSON. */
	public function testAnUnknownFormatIsBadInputOnStderr(): void {
		$tester = $this->format(['--output' => 'yaml'], separately: true);

		$this->assertSame(Command::INVALID, $tester->getStatusCode());
		$this->assertSame('', $tester->getDisplay());
		$this->assertStringContainsString('--output takes plain, json or json_pretty, not yaml', $tester->getErrorOutput());
	}

	/** Where there is no stderr, the one output there is. */
	public function testAnErrorWithoutStderrGoesToTheOutput(): void {
		$tester = $this->format(['--output' => 'yaml']);

		$this->assertStringContainsString('not yaml', $tester->getDisplay());
	}

	/** UTC whatever the server's zone, and empty for a moment that has not come, such as no `deleted_at`. */
	public function testAnInstantIsIsoUtc(): void {
		$zone = date_default_timezone_get();
		date_default_timezone_set('Europe/Berlin');
		try {
			$this->assertSame('2025-06-15T15:06:40Z', Format::instant(1750000000));
			$this->assertNull(Format::instant(null));
		} finally {
			date_default_timezone_set($zone);
		}
	}

	/**
	 * @param array<string, string> $options
	 * @param list<array<string, scalar|null>> $rows
	 */
	private function format(array $options, array $rows = self::ROWS, bool $separately = false): CommandTester {
		$command = new class($rows) extends Command {
			/** @param list<array<string, scalar|null>> $rows */
			public function __construct(
				private array $rows,
			) {
				parent::__construct('nextfleet:format-test');
				Format::configure($this);
			}

			protected function execute(InputInterface $input, OutputInterface $output): int {
				try {
					$format = Format::of($input);
				} catch (\InvalidArgumentException $e) {
					Format::error($output, $e->getMessage());

					return self::INVALID;
				}
				$format->rows($output, $this->rows, 'Nothing here.');

				return self::SUCCESS;
			}
		};
		$tester = new CommandTester($command);
		$tester->execute($options, ['capture_stderr_separately' => $separately]);

		return $tester;
	}
}
