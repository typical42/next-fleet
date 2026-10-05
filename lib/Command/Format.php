<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `--output` for every admin command: the values core's own commands take, read here because
 * `OC\Core\Command\Base` is private.
 */
final class Format {
	private const PLAIN = 'plain';
	private const JSON = 'json';
	private const JSON_PRETTY = 'json_pretty';
	/**
	 * What a terminal acts on rather than shows: C0 but the newline, DEL and C1, and the bidi
	 * overrides and isolates, which reorder what follows them on the line.
	 */
	private const CONTROLS = '\x{00}-\x{09}\x{0B}-\x{1F}\x{7F}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}';

	private function __construct(
		private string $name,
	) {
	}

	public static function configure(Command $command): void {
		$command->addOption('output', null, InputOption::VALUE_REQUIRED, 'plain, json or json_pretty', self::PLAIN);
	}

	/**
	 * @throws \InvalidArgumentException an unknown format, which is bad input (Command::INVALID)
	 */
	public static function of(InputInterface $input): self {
		$name = (string)$input->getOption('output');
		if (!in_array($name, [self::PLAIN, self::JSON, self::JSON_PRETTY], true)) {
			throw new \InvalidArgumentException('--output takes plain, json or json_pretty, not ' . $name);
		}

		return new self($name);
	}

	/**
	 * A refusal, on stderr where there is one: stdout is the one JSON document a script reads.
	 */
	public static function error(OutputInterface $output, string $message): void {
		($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output)
			->writeln('<error>' . self::safe($message) . '</error>');
	}

	/**
	 * Text that may hold what a user typed - a plate, a title, a CSV header - made harmless for the
	 * admin's terminal. A control character would drive it: an OSC 52 sequence in a plate rewrites
	 * the clipboard, a cursor move hides a row. A `<…>` would be read as a console style or link.
	 * A newline stays, since audit breaks its fields over lines on purpose.
	 */
	public static function safe(string $text): string {
		// A field takes bytes as sent, so invalid UTF-8 can carry an encoded C1 past a /u pattern,
		// which refuses the whole string. Scrubbed first, every control is a character /u sees.
		$clean = (string)preg_replace('/[' . self::CONTROLS . ']/u', "\u{FFFD}", mb_scrub($text, 'UTF-8'));

		return OutputFormatter::escape($clean);
	}

	/** One line of plain output, through safe(). */
	public static function line(OutputInterface $output, string $text): void {
		$output->writeln(self::safe($text));
	}

	public function isJson(): bool {
		return $this->name !== self::PLAIN;
	}

	/**
	 * Rows of one shape: a table with a column per key, or a JSON array of objects.
	 *
	 * @param list<array<string, scalar|null>> $rows
	 * @param string $empty the line plain prints instead of a table with no rows
	 */
	public function rows(OutputInterface $output, array $rows, string $empty): void {
		if ($this->isJson()) {
			$this->json($output, $rows);
		} elseif ($rows === []) {
			$output->writeln($empty);
		} else {
			$table = new Table($output);
			$table->setHeaders(array_keys($rows[0]));
			$table->setRows(array_map(static fn (array $row): array => array_map(
				static fn (mixed $value): string => match (true) {
					$value === null => '',
					is_bool($value) => $value ? 'yes' : 'no',
					default => self::safe((string)$value),
				},
				array_values($row),
			), $rows));
			$table->render();
		}
	}

	/** A stored instant as every command prints it: ISO 8601 in UTC, the zone the database keeps. */
	public static function instant(?int $at): ?string {
		return $at === null ? null : gmdate('Y-m-d\TH:i:s\Z', $at);
	}

	/**
	 * One JSON document, for a command whose JSON is not a list of rows.
	 */
	public function json(OutputInterface $output, mixed $document): void {
		$flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ($this->name === self::JSON_PRETTY) {
			$flags |= JSON_PRETTY_PRINT;
		}
		// JSON escapes C0 itself. Unescaped Unicode keeps a plate as typed, so the rest of CONTROLS
		// is escaped here; they occur only inside strings, where \uXXXX means the same.
		$json = (string)preg_replace_callback('/[\x{7F}-\x{9F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u',
			static fn (array $m): string => sprintf('\\u%04x', mb_ord($m[0], 'UTF-8')),
			json_encode($document, $flags));
		$output->writeln($json, OutputInterface::OUTPUT_RAW);
	}
}
