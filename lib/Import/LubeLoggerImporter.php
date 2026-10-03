<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\UnreadableCellException;
use OCA\NextFleet\Service\Field;
use OCP\IL10N;

/**
 * LubeLogger's CSV export: one file per record type, headed by its export models' property names
 * (read 2026-10-03 from `hargata/lubelog`, `Models/Shared/ImportModel.cs`). The file states no
 * unit, and service, repair and upgrade share their headers, so the user says both.
 *
 * How it writes the values (read 2026-10-03 from `Controllers/Vehicle/ImportController.cs`):
 * CsvHelper with the invariant culture, so `,` separates and RFC 4180 quotes; but each value is `ToString()` in the
 * server's culture. A fuel date carries midnight, `1/15/2024 12:00:00 AM` or `15.01.2024 00:00:00`;
 * the other dates are short dates. A service-type cost is the currency format, `$1,234.56`,
 * `1.234,56 €`, `($5.00)`; a fuel cost is a plain number; a flag is `True` or `False`.
 */
final class LubeLoggerImporter implements IImporter {
	private const MAINTENANCE = [
		'Date' => 'done_at',
		'Odometer' => 'odo',
		'Description' => 'title',
		'Cost' => 'cost',
		'Notes' => 'notes',
		'Tags' => 'notes',
	];

	/**
	 * Per record type, each property as the export writes it and the field it becomes. Anything
	 * else is ignored: an energy entry and an odometer entry have no notes, and FuelEconomy, the
	 * charge levels, ExtraFields and Files have no field here.
	 *
	 * @var array<string, array<string, string>>
	 */
	private const PLACES = [
		'fuel' => [
			'Date' => 'filled_at',
			'Odometer' => 'odo',
			'FuelConsumed' => 'amount',
			'Cost' => 'total',
			'IsFillToFull' => 'full_tank',
			// Never a header of its own: the property the alias partial_fuelup names.
			'PartialFuelUp' => 'full_tank',
			'MissedFuelUp' => 'missed_previous',
		],
		'service' => self::MAINTENANCE,
		'repair' => self::MAINTENANCE,
		'upgrade' => self::MAINTENANCE,
		'tax' => [
			'Date' => 'spent_at',
			'Description' => 'notes',
			'Cost' => 'amount',
			'Notes' => 'notes',
			'Tags' => 'notes',
		],
		'supplies' => [
			'Date' => 'spent_at',
			'PartNumber' => 'notes',
			'PartSupplier' => 'notes',
			'PartQuantity' => 'notes',
			'Description' => 'notes',
			'Cost' => 'amount',
			'Notes' => 'notes',
			'Tags' => 'notes',
		],
		'odometer' => [
			'Date' => 'read_at',
			'Odometer' => 'value',
		],
	];

	/**
	 * The other headers LubeLogger's own importer accepts, by the property they fill. One places
	 * only where its property belongs: `qty` is fuel consumed, never a part's quantity.
	 */
	private const ALIASES = [
		'fuelup_date' => 'Date',
		'odo' => 'Odometer',
		'gallons' => 'FuelConsumed',
		'liters' => 'FuelConsumed',
		'litres' => 'FuelConsumed',
		'quantity' => 'FuelConsumed',
		'qty' => 'FuelConsumed',
		'total cost' => 'Cost',
		'totalcost' => 'Cost',
		'filled up' => 'IsFillToFull',
		'partial_fuelup' => 'PartialFuelUp',
		'missed_fuelup' => 'MissedFuelUp',
		'note' => 'Notes',
	];

	/** A maintenance record's title column (MaintenanceService). */
	private const TITLE_LENGTH = 255;

	public function __construct(
		private IL10N $l,
	) {
	}

	public function key(): string {
		return 'lubelogger';
	}

	public function label(): string {
		return $this->l->t('CSV (LubeLogger format)');
	}

	public function recordTypes(): array {
		return array_keys(self::PLACES);
	}

	public function escape(): ?string {
		return null;
	}

	public function questions(string $recordType): array {
		$places = self::places($recordType);

		return array_values(array_filter([
			isset($places['Odometer']) ? 'units' : null,
			$recordType === 'fuel' ? 'energy' : null,
		]));
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
		$date = $positions['Date'] ?? null;
		if ($date !== null) {
			$rows = array_map(static fn (array $row): array => self::day($row, $date), $rows);
		}

		$order = Answers::dateOrder($answers, Cells::column($rows, $positions['Date'] ?? null));
		// The file names no energy, so a vehicle of several leaves it to the user.
		$open = ($recordType === 'fuel' ? Answers::energy($answers)[1] : [])
			+ ($order === null ? ['date_order' => ['dmy', 'mdy']] : []);
		$marks = [
			'Odometer' => Values::decimalMark(Cells::column($rows, $positions['Odometer'] ?? null)),
			'FuelConsumed' => Values::decimalMark(Cells::column($rows, $positions['FuelConsumed'] ?? null)),
			// LubeLogger writes a cost in the server's currency format, `$42.50`.
			'Cost' => Values::decimalMark(array_map(
				static fn (string $cell): string => Values::money($cell)[0],
				Cells::column($rows, $positions['Cost'] ?? null),
			)),
		];

		$read = $this->reader($recordType, $answers);
		$zone = Field::zone('tz', $answers['tz'] ?? null);
		$currency = Answers::currency($answers);
		$all = [];
		foreach ($rows as $number => $row) {
			$cells = new Cells($row, $columns, $marks, $order ?? 'dmy', $zone, $currency);
			try {
				$fields = array_filter($read($cells), static fn (mixed $field): bool => $field !== null);
				$all[] = new Proposal(self::kind($recordType), $fields, $number);
			} catch (UnreadableCellException $e) {
				$all[] = new Proposal(self::kind($recordType), [], $number, Proposal::UNREADABLE, $e->reason, $e->column);
			}
		}

		return new Proposals($all, $open);
	}

	/**
	 * @return array<string, string>
	 * @throws \InvalidArgumentException
	 */
	private static function places(string $recordType): array {
		return self::PLACES[$recordType] ?? throw new \InvalidArgumentException('LubeLogger has no record type ' . $recordType);
	}

	/**
	 * LubeLogger keeps days. A fuel date is written with `ToString()`, which adds midnight in the
	 * server's culture; without it the day reads as noon, as every date alone does (Values::moment).
	 * Any other time is kept: it would be one the user gave.
	 *
	 * @param list<string> $row
	 * @return list<string>
	 */
	private static function day(array $row, int $date): array {
		if (isset($row[$date])) {
			$row[$date] = preg_replace('/^(\S+)\s+(?:12:00(?::00)?[\s\x{00A0}\x{202F}]*AM|0?0:00(?::00)?)$/iu', '$1', $row[$date]) ?? $row[$date];
		}

		return $row;
	}

	private static function kind(string $recordType): string {
		return match ($recordType) {
			'fuel' => Proposal::ENERGY,
			'tax', 'supplies' => Proposal::EXPENSE,
			'odometer' => Proposal::ODOMETER,
			default => Proposal::MAINTENANCE,
		};
	}

	/**
	 * How one row becomes an entry's fields, with the answers checked once, before any row.
	 *
	 * @param array<string, mixed> $answers
	 * @return \Closure(Cells): array<string, int|string|bool|null>
	 * @throws \InvalidArgumentException
	 */
	private function reader(string $recordType, array $answers): \Closure {
		$distance = in_array('units', $this->questions($recordType), true) ? Answers::unit($answers, 'distance') : '';
		$join = Cells::lines(...);

		return match ($recordType) {
			'fuel' => $this->fuel($answers, $distance),
			'tax' => static function (Cells $cells) use ($join): array {
				$at = $cells->must('Date', $cells->moment('Date'));

				return [
					'spent_at' => $at['at'],
					'spent_at_off' => $at['off'],
					'category' => 'tax',
					'amount' => $cells->must('Cost', $cells->money('Cost')),
					'notes' => $join($cells->text('Description'), $cells->text('Notes'), $cells->text('Tags')),
				];
			},
			'supplies' => function (Cells $cells) use ($join): array {
				$at = $cells->must('Date', $cells->moment('Date'));
				$part = $cells->text('PartNumber');
				$supplier = $cells->text('PartSupplier');
				$quantity = $cells->text('PartQuantity');

				return [
					'spent_at' => $at['at'],
					'spent_at_off' => $at['off'],
					'category' => 'other',
					'amount' => $cells->must('Cost', $cells->money('Cost')),
					'notes' => $join(
						$cells->text('Description'),
						// TRANSLATORS: a line in an imported expense's notes; %s is the part number
						$part === null ? null : $this->l->t('Part number: %s', [$part]),
						// TRANSLATORS: a line in an imported expense's notes; %s is who sold the part
						$supplier === null ? null : $this->l->t('Supplier: %s', [$supplier]),
						// TRANSLATORS: a line in an imported expense's notes; %s is how many were bought
						$quantity === null ? null : $this->l->t('Quantity: %s', [$quantity]),
						$cells->text('Notes'),
						$cells->text('Tags'),
					),
				];
			},
			'odometer' => static function (Cells $cells) use ($distance): array {
				$at = $cells->must('Date', $cells->moment('Date'));

				return [
					'read_at' => $at['at'],
					'read_at_off' => $at['off'],
					'value' => $cells->must('Odometer', $cells->count('Odometer', 'distance', $distance)),
				];
			},
			default => static function (Cells $cells) use ($recordType, $distance, $join): array {
				$at = $cells->must('Date', $cells->moment('Date'));

				return [
					'done_at' => $at['at'],
					'done_at_off' => $at['off'],
					'type' => $recordType,
					'title' => $cells->must('Description', $cells->text('Description', self::TITLE_LENGTH)),
					'odo' => $cells->count('Odometer', 'distance', $distance),
					'cost' => $cells->money('Cost'),
					'notes' => $join($cells->text('Notes'), $cells->text('Tags')),
				];
			},
		};
	}

	/**
	 * @param array<string, mixed> $answers
	 * @return \Closure(Cells): array<string, int|string|bool|null>
	 * @throws \InvalidArgumentException
	 */
	private function fuel(array $answers, string $distance): \Closure {
		[$energy] = Answers::energy($answers);
		// A charging session is kWh, whatever the file's volume unit: LubeLogger keeps both in one column.
		[$dimension, $unit] = $energy === 'electric' ? ['energy', 'kwh'] : ['volume', Answers::unit($answers, 'volume')];

		return static function (Cells $cells) use ($energy, $distance, $dimension, $unit): array {
			$at = $cells->must('Date', $cells->moment('Date'));
			$partial = $cells->flag('PartialFuelUp');

			return [
				'filled_at' => $at['at'],
				'filled_at_off' => $at['off'],
				'energy' => $energy,
				'odo' => $cells->count('Odometer', 'distance', $distance),
				'amount' => $cells->must('FuelConsumed', $cells->count('FuelConsumed', $dimension, $unit)),
				'total' => $cells->money('Cost'),
				// A fill-up is full unless the file says otherwise, as in the entry sheet (docs/ui.md).
				'full_tank' => $cells->flag('IsFillToFull') ?? ($partial === null || !$partial),
				'missed_previous' => $cells->flag('MissedFuelUp') ?? false,
			];
		};
	}
}
