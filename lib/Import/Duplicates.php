<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Db\Energy;
use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\Maintenance;
use OCA\NextFleet\Db\OdoReading;

/**
 * The vehicle's live entries, as an import asks of them: is this row already here? A duplicate
 * is an entry of the same kind on the same local day with the same counter or, where either has
 * no counter, the same amount. A row with neither is never one: there is nothing to match on.
 *
 * Only the main counter is compared. An import writes no other, and an energy entry's `second_odo`
 * is the vehicle's second unit, which neither format knows of.
 */
final class Duplicates {
	/**
	 * Per kind, the fields that hold the moment, its offset, the counter and the amount. The
	 * entities' jsonSerialize() and a proposal name them alike, so one table reads both.
	 *
	 * @var array<string, array{string, string, ?string, ?string}>
	 */
	private const KEYS = [
		Proposal::ENERGY => ['filled_at', 'filled_at_off', 'odo', 'amount'],
		Proposal::MAINTENANCE => ['done_at', 'done_at_off', 'odo', 'cost'],
		Proposal::EXPENSE => ['spent_at', 'spent_at_off', null, 'amount'],
		Proposal::ODOMETER => ['read_at', 'read_at_off', 'value', null],
	];

	/** @var array<string, true> */
	private array $seen = [];

	/**
	 * @param iterable<Energy|Maintenance|Expense|OdoReading> $live the vehicle's entries; Readings
	 *                                                              other Entries wrote are passed over
	 */
	public static function of(iterable $live): self {
		$duplicates = new self();
		foreach ($live as $entry) {
			$kind = match (true) {
				$entry instanceof Energy => Proposal::ENERGY,
				$entry instanceof Maintenance => Proposal::MAINTENANCE,
				$entry instanceof Expense => Proposal::EXPENSE,
				$entry->getSourceType() === OdoReading::MANUAL => Proposal::ODOMETER,
				default => null,
			};
			if ($kind === null || $entry->getDeletedAt() !== null) {
				continue;
			}
			[$day, $counter, $amount] = self::read($kind, $entry->jsonSerialize());
			if ($counter !== null) {
				$duplicates->seen["$day counter $counter"] = true;
			}
			if ($amount !== null) {
				$duplicates->seen["$day amount $amount"] = true;
				if ($counter === null) {
					$duplicates->seen["$day uncounted $amount"] = true;
				}
			}
		}

		return $duplicates;
	}

	/**
	 * Per kind, the instants `[from, to)` a live entry must fall in to share a local day with one
	 * of these: two days either side, which any two offsets fit in.
	 *
	 * @param iterable<Proposal> $proposals
	 * @return array<string, array{int, int}> kind => [from, to)
	 */
	public static function span(iterable $proposals): array {
		$spans = [];
		foreach ($proposals as $proposal) {
			if ($proposal->outcome === Proposal::UNREADABLE) {
				continue;
			}
			$at = (int)$proposal->fields[self::KEYS[$proposal->kind][0]];
			[$from, $to] = $spans[$proposal->kind] ?? [$at, $at];
			$spans[$proposal->kind] = [min($from, $at), max($to, $at)];
		}

		return array_map(static fn (array $span): array => [$span[0] - 2 * 86400, $span[1] + 2 * 86400 + 1], $spans);
	}

	public function mark(Proposal $proposal): Proposal {
		if ($proposal->outcome === Proposal::UNREADABLE) {
			return $proposal;
		}
		[$day, $counter, $amount] = self::read($proposal->kind, $proposal->fields);
		$found = $counter !== null
			? isset($this->seen["$day counter $counter"]) || ($amount !== null && isset($this->seen["$day uncounted $amount"]))
			: $amount !== null && isset($this->seen["$day amount $amount"]);

		return $found ? $proposal->asDuplicate() : $proposal;
	}

	/**
	 * @param array<string, mixed> $fields
	 * @return array{string, ?string, ?string} where it falls, its counter, its amount
	 */
	private static function read(string $kind, array $fields): array {
		[$at, $off, $counter, $amount] = self::KEYS[$kind];
		$day = $kind . ' ' . gmdate('Y-m-d', (int)$fields[$at] + (int)$fields[$off] * 60);
		$day .= match ($kind) {
			// A plug-in hybrid fuels and charges at the same counter on one day.
			Proposal::ENERGY => ' ' . (string)($fields['energy'] ?? ''),
			// The same number on the other chain is another counter (OdometerService::counterOf()).
			Proposal::ODOMETER => ' ' . (string)($fields['counter'] ?? OdoReading::MAIN),
			default => '',
		};

		return [
			$day,
			$counter === null || !isset($fields[$counter]) ? null : (string)$fields[$counter],
			$amount === null || !isset($fields[$amount]) ? null : (string)$fields[$amount],
		];
	}
}
