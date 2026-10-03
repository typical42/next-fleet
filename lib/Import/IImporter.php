<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

/**
 * Another tool's export in, proposals out — internal seam, not a public API
 * (docs/contributing.md). An importer reads what CsvReader hands it and writes nothing: the
 * entry services create what the user accepts.
 */
interface IImporter {
	/** Stable, for the API and `occ`: `lubelogger`, `spritmonitor`. */
	public function key(): string;

	/** Nominative only (docs/legal.md): "CSV (LubeLogger format)", never the product's name alone. */
	public function label(): string;

	/**
	 * The kinds of export this format has, one file each: `fuel`, `service`, `costs`, …
	 *
	 * @return list<string>
	 */
	public function recordTypes(): array;

	/** The byte this format's writer escapes with (CsvReader::open()), null for RFC 4180. */
	public function escape(): ?string;

	/**
	 * What a file of this record type does not say and the user must: `units`, `energy`, … Not
	 * the questions only reading the rows raises, such as an ambiguous date order. An `energy`
	 * the vehicle has only one of answers itself (Answers::energy()).
	 *
	 * @return list<string>
	 */
	public function questions(string $recordType): array;

	/**
	 * Which header became which field. A header it cannot place is named, never guessed into a
	 * field: the formats are evidence, not promises.
	 *
	 * @param list<string> $header
	 * @return array{placed: array<string, string>, ignored: list<string>} placed is header => field
	 */
	public function columns(string $recordType, array $header): array;

	/**
	 * Reads every row before proposing any: a decimal mark or a date order is the whole column's
	 * evidence. The file is capped (CsvReader), so holding it is bounded.
	 *
	 * @param list<string> $header
	 * @param iterable<int, list<string>> $rows keyed by spreadsheet row, as CsvReader::rows()
	 * @param array<string, mixed> $answers to questions() and to Proposals::$open, keyed by
	 *                                      question, plus what the vehicle says and the user is
	 *                                      not asked: `tz`, the zone a date is local to,
	 *                                      `currency`, its ISO 4217 code or null, and
	 *                                      `energies`, the energy types it takes
	 * @throws \InvalidArgumentException for an answer missing or not one the question takes
	 * @throws \OCA\NextFleet\Exception\ImportRefusedException from the rows, midway
	 */
	public function propose(string $recordType, array $header, iterable $rows, array $answers): Proposals;
}
