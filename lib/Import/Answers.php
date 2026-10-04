<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Exception\RefusedException;
use OCA\NextFleet\Service\VehicleService;

/**
 * The answers every importer reads (IImporter::propose()), each checked before any row: a wrong
 * one is the request's fault, not a row's.
 */
final class Answers {
	/**
	 * The refusal the import sheet words itself (RefusedException), in the words the entry sheet
	 * shows beside its disabled *Energy*. The message says the same, for `occ` and other clients.
	 */
	public const NO_ENERGY = 'no_energy';

	/**
	 * @param array<string, mixed> $answers
	 * @param string $dimension a key of Values::UNITS
	 * @return string the unit of that dimension the user named
	 * @throws \InvalidArgumentException
	 */
	public static function unit(array $answers, string $dimension): string {
		$unit = $answers['units'][$dimension] ?? null;
		if (!is_string($unit) || !isset(Values::UNITS[$dimension][$unit])) {
			throw new \InvalidArgumentException('units.' . $dimension . ' is one of ' . implode(', ', array_keys(Values::UNITS[$dimension])));
		}

		return $unit;
	}

	/**
	 * The energy of a fill-up whose row does not name one: the user's answer, else the vehicle's
	 * only one. Of several, the first is read meanwhile and the question stays open, as an open
	 * date order is read day first.
	 *
	 * @param array<string, mixed> $answers `energy`, and `energies`, the vehicle's; any when left out
	 * @return array{string, array<string, list<string>>} the energy, and `energy` => its choices while open
	 * @throws \InvalidArgumentException
	 */
	public static function energy(array $answers): array {
		$energies = array_key_exists('energies', $answers) ? $answers['energies'] : VehicleService::ENERGIES;
		if (!is_array($energies) || $energies === [] || array_diff($energies, VehicleService::ENERGIES) !== []) {
			throw new RefusedException('Choose the energy this vehicle takes under Edit vehicle first.', self::NO_ENERGY);
		}
		$energies = array_values($energies);
		$energy = $answers['energy'] ?? null;
		if ($energy !== null && (!is_string($energy) || !in_array($energy, $energies, true))) {
			throw new \InvalidArgumentException('energy is one of ' . implode(', ', $energies));
		}

		return match (true) {
			$energy !== null => [$energy, []],
			count($energies) === 1 => [$energies[0], []],
			default => [$energies[0], ['energy' => $energies]],
		};
	}

	/**
	 * The vehicle's currency, upper-cased. One that is still no code is not refused here: it
	 * costs the rows with money (Cells::money()), not a file that has none.
	 *
	 * @param array<string, mixed> $answers
	 * @return ?string null when the vehicle states none
	 * @throws \InvalidArgumentException
	 */
	public static function currency(array $answers): ?string {
		$currency = $answers['currency'] ?? null;
		if ($currency !== null && !is_string($currency)) {
			throw new \InvalidArgumentException('currency is the vehicle\'s');
		}

		return $currency === null ? null : strtoupper(trim($currency));
	}

	/**
	 * The order of slash dates: the user's, else what the column shows.
	 *
	 * @param array<string, mixed> $answers
	 * @param list<string> $cells the date column
	 * @return ?string `dmy` or `mdy`; null when only the user can say (Values::dateOrder())
	 * @throws \InvalidArgumentException
	 */
	public static function dateOrder(array $answers, array $cells): ?string {
		$order = $answers['date_order'] ?? Values::dateOrder($cells);
		if ($order !== null && !in_array($order, ['dmy', 'mdy'], true)) {
			throw new \InvalidArgumentException('date_order is dmy or mdy');
		}

		return $order;
	}
}
