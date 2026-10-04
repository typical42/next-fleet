<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\UnreadableCellException;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\Field;
use OCA\NextFleet\Service\MaintenanceService;
use OCP\IL10N;

/**
 * Spritmonitor's CSV export, fuel or costs. Sources, read 2026-10-03:
 * `LukasMaly/PySpritmonitor`, `pyspritmonitor/fuelings.py`, `entries.py` and
 * `resources/formats.json`. They say: `;`, a decimal comma, the day first, `\` as the escape
 * character; English or German headers; the fuel and the cost type as codes.
 *
 * The costs export's amount and counter headers have no source: they are the fuel export's. Nor
 * has the English costs export's title header, so none is matched and the column report names it.
 */
final class SpritmonitorImporter implements IImporter {
	/**
	 * Per record type, each column the export writes and the field it becomes. Anything else is
	 * ignored: distance and consumption are worked out here; tyres, route and driving style, like a
	 * fill-up's note, have no field; the `BC-*` columns are the on-board computer's own figures.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PLACES = [
		'fuel' => [
			'Datum' => 'filled_at',
			'Km-Stand' => 'odo',
			'Spritmenge' => 'amount',
			'Kosten' => 'total',
			'Währung' => 'currency',
			'Tankart' => 'full_tank',
			'Kraftstoff' => 'energy',
		],
		// A cost becomes an expense or a maintenance record by its category, so its date is neither's.
		// Only a maintenance record keeps the counter and the title; an expense notes the title.
		'costs' => [
			'Datum' => 'date',
			'Km-Stand' => 'odo',
			'Kostenart' => 'category',
			'Bezeichnung' => 'title',
			'Kosten' => 'amount',
			'Währung' => 'currency',
			'Bemerkung' => 'notes',
		],
	];

	/**
	 * The other names a column goes by: the English export's, then older German ones. `Total
	 * price` and `Odometer` name a cost's columns only by analogy (see the class).
	 */
	private const ALIASES = [
		'date' => 'Datum',
		'odometer' => 'Km-Stand',
		'quantity' => 'Spritmenge',
		'total price' => 'Kosten',
		'currency' => 'Währung',
		'type' => 'Tankart',
		'fuel' => 'Kraftstoff',
		'cost type' => 'Kostenart',
		'note' => 'Bemerkung',
		'tachostand' => 'Km-Stand',
		'menge' => 'Spritmenge',
		'gesamtkosten' => 'Kosten',
	];

	/** A `Kraftstoff` code and the energy it is. A code not listed leaves it to the user. */
	private const FUEL_CODES = [
		1 => 'diesel', 2 => 'diesel', 3 => 'diesel', 4 => 'diesel',
		6 => 'petrol', 7 => 'petrol', 8 => 'petrol', 9 => 'petrol', 15 => 'petrol', 16 => 'petrol',
		18 => 'petrol', 20 => 'petrol', 22 => 'petrol',
		12 => 'lpg', 13 => 'cng', 14 => 'cng', 19 => 'electric',
	];

	/** `Kraftstoff` codes of what no fill-up here records, by the reason the row is unreadable. */
	private const NO_ENERGY = [21 => 'adblue', 23 => 'hydrogen'];

	/**
	 * Words in a `Kraftstoff` cell that name one energy, for a file written in words. Petrol has
	 * many grades and names and is left to the user's answer.
	 */
	private const FUELS = [
		'diesel' => 'diesel',
		'autogas' => 'lpg',
		'lpg' => 'lpg',
		'erdgas' => 'cng',
		'cng' => 'cng',
		'strom' => 'electric',
		'elektr' => 'electric',
		'electric' => 'electric',
	];

	/**
	 * A `Kostenart` code and what it becomes unless the user answers otherwise. Change oil is a
	 * service, spare parts a repair, the supervisory board the statutory inspection.
	 */
	private const COST_CODES = [
		1 => 'maintenance.service', 2 => 'maintenance.repair', 3 => 'maintenance.tyres',
		4 => 'maintenance.service', 5 => 'expense.insurance', 6 => 'expense.tax',
		7 => 'maintenance.inspection', 8 => 'maintenance.upgrade', 9 => 'expense.other',
		11 => 'expense.other', 12 => 'expense.other', 13 => 'expense.other', 14 => 'expense.other',
		15 => 'expense.other', 17 => 'expense.fine', 18 => 'expense.parking', 19 => 'expense.toll',
		20 => 'maintenance.repair',
	];

	/**
	 * `Kostenart` codes no answer imports, by the reason: a purchase price is no running cost, and a
	 * refund would be a negative one.
	 */
	private const NO_COST = [10 => 'purchase', 16 => 'refund'];

	/** A maintenance record's title column (MaintenanceService). */
	private const TITLE_LENGTH = 255;

	public function __construct(
		private IL10N $l,
	) {
	}

	public function key(): string {
		return 'spritmonitor';
	}

	public function label(): string {
		return $this->l->t('CSV (Spritmonitor format)');
	}

	public function recordTypes(): array {
		return array_keys(self::PLACES);
	}

	public function escape(): ?string {
		return '\\';
	}

	public function questions(string $recordType): array {
		self::places($recordType);

		// Not `energy`: `Kraftstoff` names it, and only a row that does not raises it (propose()).
		return ['units'];
	}

	public function columns(string $recordType, array $header): array {
		$places = self::places($recordType);

		return Cells::report($header, Cells::positions($header, Cells::names(array_keys($places), self::ALIASES)), $places);
	}

	public function propose(string $recordType, array $header, iterable $rows, array $answers): Proposals {
		$places = self::places($recordType);
		$positions = Cells::positions($header, Cells::names(array_keys($places), self::ALIASES));
		$columns = Cells::located($header, $positions, array_keys($places));
		$rows = $rows instanceof \Traversable ? iterator_to_array($rows) : $rows;
		$column = static fn (string $value): array => Cells::column($rows, $positions[$value] ?? null);

		// The export writes the day first and a decimal comma; a column's own evidence still wins.
		$order = Answers::dateOrder($answers, $column('Datum')) ?? 'dmy';
		$marks = [
			'Km-Stand' => Values::decimalMark($column('Km-Stand')) ?? ',',
			'Spritmenge' => Values::decimalMark($column('Spritmenge')) ?? ',',
			'Kosten' => Values::decimalMark(array_map(static fn (string $cell): string => Values::money($cell)[0], $column('Kosten'))) ?? ',',
		];

		$distance = Answers::unit($answers, 'distance');
		$open = [];
		$categories = [];
		$defaults = [];
		if ($recordType === 'fuel') {
			$named = array_map(self::energy(...), $column('Kraftstoff') ?: ['']);
			// Asked of every file, so a vehicle with no energy type refuses one that names each fuel.
			// Only a row that names no fuel takes the user's; a file that names every one asks nothing.
			[$answered, $asked] = Answers::energy($answers);
			$open = in_array(null, $named, true) ? $asked : [];
			$read = self::fuel($answers, $distance, $answered, $named);
		} else {
			$categories = array_values(array_unique(array_filter(
				array_map('trim', $column('Kostenart')),
				static fn (string $text): bool => $text !== '' && !isset(self::NO_COST[self::code($text) ?? 0]),
			)));
			foreach ($categories as $text) {
				$code = self::code($text);
				if ($code !== null && isset(self::COST_CODES[$code])) {
					$defaults[$code] = self::COST_CODES[$code];
				}
			}
			$map = self::categoryMap($answers) + $defaults;
			$unmapped = array_values(array_filter($categories, static fn (string $text): bool => !isset($map[$text])));
			if ($unmapped !== []) {
				$open['category_map'] = $unmapped;
			}
			$read = $this->costs($map, $distance);
		}

		$zone = Field::zone('tz', $answers['tz'] ?? null);
		$currency = Answers::currency($answers);
		$all = [];
		foreach ($rows as $number => $row) {
			$cells = new Cells($row, $columns, $marks, $order, $zone, $currency);
			try {
				$cells->fits($header);
				[$kind, $fields] = $read($cells);
				$all[] = new Proposal($kind, array_filter($fields, static fn (mixed $field): bool => $field !== null), $number);
			} catch (UnreadableCellException $e) {
				// A cost that cannot be read cannot say what it would have become.
				$kind = $recordType === 'fuel' ? Proposal::ENERGY : Proposal::EXPENSE;
				$all[] = new Proposal($kind, [], $number, Proposal::UNREADABLE, $e->reason, $e->column);
			}
		}

		return new Proposals($all, $open, $categories, $defaults);
	}

	/**
	 * @return array<string, string>
	 * @throws \InvalidArgumentException
	 */
	private static function places(string $recordType): array {
		return self::PLACES[$recordType] ?? throw new \InvalidArgumentException('Spritmonitor has no record type ' . $recordType);
	}

	/**
	 * The energy a `Kraftstoff` cell names by code or unambiguous word; for a code no fill-up
	 * records, the reason the row is unreadable (NO_ENERGY); else null.
	 */
	private static function energy(string $cell): ?string {
		$cell = mb_strtolower(trim($cell));
		$code = self::code($cell);
		if ($code !== null) {
			return self::FUEL_CODES[$code] ?? self::NO_ENERGY[$code] ?? null;
		}
		foreach (self::FUELS as $word => $energy) {
			if (str_contains($cell, $word)) {
				return $energy;
			}
		}

		return null;
	}

	/** A trimmed cell that holds a code, as its number; null for anything else, `07` included. */
	private static function code(string $text): ?int {
		return preg_match('/^[1-9]\d{0,2}$/', $text) === 1 ? (int)$text : null;
	}

	/**
	 * The user's answer for each category text: `skip`, `expense.<category>` or
	 * `maintenance.<type>`.
	 *
	 * @param array<string, mixed> $answers
	 * @return array<array-key, string>
	 * @throws \InvalidArgumentException
	 */
	private static function categoryMap(array $answers): array {
		$map = $answers['category_map'] ?? [];
		$targets = [
			'skip',
			...array_map(static fn (string $category): string => 'expense.' . $category, ExpenseService::CATEGORIES),
			...array_map(static fn (string $type): string => 'maintenance.' . $type, MaintenanceService::TYPES),
		];
		if (!is_array($map)) {
			throw new \InvalidArgumentException('category_map maps each category text to what it becomes');
		}
		foreach ($map as $target) {
			if (!in_array($target, $targets, true)) {
				throw new \InvalidArgumentException('category_map takes ' . implode(', ', $targets));
			}
		}

		return $map;
	}

	/**
	 * @param array<string, mixed> $answers
	 * @param string $answered the energy of a row that names none
	 * @param list<?string> $named what each `Kraftstoff` cell names (energy())
	 * @return \Closure(Cells): array{string, array<string, int|string|bool|null>}
	 * @throws \InvalidArgumentException
	 */
	private static function fuel(array $answers, string $distance, string $answered, array $named): \Closure {
		$energies = array_map(static fn (?string $energy): string => $energy ?? $answered, $named);
		// A file of charging sessions states no litres; one with any fuel in it does.
		$volume = array_diff($energies, ['electric', ...self::NO_ENERGY]) === [] ? '' : Answers::unit($answers, 'volume');

		return static function (Cells $cells) use ($answered, $distance, $volume): array {
			$at = $cells->must('Datum', $cells->moment('Datum'));
			$cells->sameCurrency('Währung');
			$energy = self::energy($cells->text('Kraftstoff') ?? '') ?? $answered;
			if (in_array($energy, self::NO_ENERGY, true)) {
				$cells->refuse('Kraftstoff', $energy);
			}
			// `Tankart` 1 full, 2 partial, 3 first: full, with no fill-up before it to measure from.
			// An empty cell is full, as in the entry sheet (docs/ui.md).
			[$full, $missed] = match ($cells->text('Tankart')) {
				null, '1' => [true, false],
				'2' => [false, false],
				'3' => [true, true],
				default => $cells->refuse('Tankart', 'code'),
			};

			return [Proposal::ENERGY, [
				'filled_at' => $at['at'],
				'filled_at_off' => $at['off'],
				'energy' => $energy,
				'odo' => $cells->count('Km-Stand', 'distance', $distance),
				'amount' => $cells->must('Spritmenge', $energy === 'electric'
					? $cells->count('Spritmenge', 'energy', 'kwh')
					: $cells->count('Spritmenge', 'volume', $volume)),
				'total' => $cells->money('Kosten'),
				'full_tank' => $full,
				'missed_previous' => $missed,
			]];
		};
	}

	/**
	 * @param array<array-key, string> $map the user's answers over the defaults
	 * @return \Closure(Cells): array{string, array<string, int|string|bool|null>}
	 */
	private function costs(array $map, string $distance): \Closure {
		$titles = $this->titles();

		return static function (Cells $cells) use ($map, $distance, $titles): array {
			// First: a row never imported needs no other fault named.
			$text = $cells->must('Kostenart', $cells->text('Kostenart'));
			$code = self::code($text) ?? 0;
			if (isset(self::NO_COST[$code])) {
				$cells->refuse('Kostenart', self::NO_COST[$code]);
			}
			$at = $cells->must('Datum', $cells->moment('Datum'));
			$cells->sameCurrency('Währung');
			$target = $map[$text] ?? $cells->refuse('Kostenart', 'category');
			if ($target === 'skip') {
				$cells->refuse('Kostenart', 'skipped');
			}
			[$kind, $what] = explode('.', $target, 2);
			$amount = $cells->money('Kosten');
			$notes = $cells->text('Bemerkung');

			if ($kind === 'maintenance') {
				return [Proposal::MAINTENANCE, [
					'done_at' => $at['at'],
					'done_at_off' => $at['off'],
					'type' => $what,
					// A record needs a title: the code's name while it means what it does by default,
					// else the category text.
					'title' => $cells->text('Bezeichnung', self::TITLE_LENGTH)
						?? ($target === (self::COST_CODES[$code] ?? null) ? $titles[$code] ?? null : null)
						?? (mb_strlen($text) <= self::TITLE_LENGTH ? $text : $cells->refuse('Kostenart', 'too_long')),
					'odo' => $cells->count('Km-Stand', 'distance', $distance),
					'cost' => $amount,
					'notes' => $cells->notes('Bemerkung', $notes),
				]];
			}

			return [Proposal::EXPENSE, [
				'spent_at' => $at['at'],
				'spent_at_off' => $at['off'],
				'category' => $what,
				'amount' => $cells->must('Kosten', $amount),
				// An expense has no title.
				'notes' => $cells->notes('Bemerkung', $cells->text('Bezeichnung'), $notes),
			]];
		};
	}

	/**
	 * What each code a maintenance record may come from is called, for a row without a title. A
	 * code the user maps to maintenance otherwise keeps its number.
	 *
	 * @return array<int, string>
	 */
	private function titles(): array {
		return [
			1 => $this->l->t('Maintenance'),
			2 => $this->l->t('Repair'),
			3 => $this->l->t('Tyres'),
			4 => $this->l->t('Oil change'),
			7 => $this->l->t('Inspection'),
			8 => $this->l->t('Upgrade'),
			20 => $this->l->t('Spare parts'),
		];
	}
}
