<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

use OCA\NextFleet\Service\VehicleService;

/**
 * The answers every importer reads (IImporter::propose()), each checked before any row: a wrong
 * one is the request's fault, not a row's.
 */
final class Answers {
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
			throw new \InvalidArgumentException('The vehicle takes no energy yet; set its energy types first');
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
	 * @param array<string, mixed> $answers
	 * @return ?string the vehicle's ISO 4217 code, null when it states none
	 * @throws \InvalidArgumentException
	 */
	public static function currency(array $answers): ?string {
		$currency = $answers['currency'] ?? null;
		if ($currency !== null && (!is_string($currency) || preg_match('/^[A-Z]{3}$/', $currency) !== 1)) {
			throw new \InvalidArgumentException('currency is an ISO 4217 code');
		}

		return $currency;
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
