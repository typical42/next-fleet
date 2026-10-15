<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * The EU type codes of a registration certificate, as codes only: their words live in
 * src/utils/registration.js, and its spec holds both lists equal. The vehicle type stays the
 * coarse class the rules read; these are data beside it.
 */
final class Registration {
	/**
	 * Field J: every category of Reg. (EU) 2018/858, 168/2013 and 167/2013. A parent L code stays
	 * one of them, as a vehicle approved before 168/2013 carries nothing finer; a T, C, R or S code
	 * always carries its speed letter.
	 */
	public const CATEGORIES = [
		'M1', 'M2', 'M3',
		'N1', 'N2', 'N3',
		'O1', 'O2', 'O3', 'O4',
		'L1e', 'L1e-A', 'L1e-B',
		'L2e', 'L2e-P', 'L2e-U',
		'L3e', 'L3e-A1', 'L3e-A1E', 'L3e-A1T', 'L3e-A2', 'L3e-A2E', 'L3e-A2T', 'L3e-A3', 'L3e-A3E', 'L3e-A3T',
		'L4e',
		'L5e', 'L5e-A', 'L5e-B',
		'L6e', 'L6e-A', 'L6e-B', 'L6e-BU', 'L6e-BP',
		'L7e', 'L7e-A', 'L7e-A1', 'L7e-A2', 'L7e-B', 'L7e-B1', 'L7e-B2', 'L7e-C', 'L7e-CU', 'L7e-CP',
		'T1a', 'T1b', 'T2a', 'T2b', 'T3a', 'T3b', 'T4.1a', 'T4.1b', 'T4.2a', 'T4.2b', 'T4.3a', 'T4.3b',
		'C1a', 'C1b', 'C2a', 'C2b', 'C3a', 'C3b', 'C4.1a', 'C4.1b', 'C4.2a', 'C4.2b', 'C4.3a', 'C4.3b',
		'R1a', 'R1b', 'R2a', 'R2b', 'R3a', 'R3b', 'R4a', 'R4b',
		'S1a', 'S1b', 'S2a', 'S2b',
	];

	/** The categories an off-road vehicle may be, stored with `G` appended (`N3G`). */
	public const OFF_ROAD = ['M1', 'M2', 'M3', 'N1', 'N2', 'N3'];

	/**
	 * Field 4: every body code of Reg. (EU) 2018/858 Annex I Part C, each with the categories it
	 * belongs to, space-separated rather than nested so the spec can read this as it stands. A
	 * category listed bare takes its off-road variant too; SM is for off-road vehicles only.
	 */
	public const BODY_TYPES = [
		'AA' => 'M1', 'AB' => 'M1', 'AC' => 'M1', 'AD' => 'M1', 'AE' => 'M1', 'AF' => 'M1', 'AG' => 'M1',
		'CA' => 'M2 M3', 'CB' => 'M2 M3', 'CC' => 'M2 M3', 'CD' => 'M2 M3', 'CE' => 'M2 M3', 'CF' => 'M2 M3',
		'CG' => 'M2 M3', 'CH' => 'M2 M3', 'CI' => 'M2 M3', 'CJ' => 'M2 M3', 'CX' => 'M2 M3',
		'BA' => 'N1 N2 N3', 'BB' => 'N1 N2 N3', 'BC' => 'N1 N2 N3', 'BD' => 'N1 N2 N3', 'BE' => 'N1', 'BX' => 'N1 N2 N3',
		'DA' => 'O1 O2 O3 O4', 'DB' => 'O1 O2 O3 O4', 'DC' => 'O1 O2 O3 O4', 'DE' => 'O1 O2 O3 O4',
		'DF' => 'O1 O2 O3 O4', 'DG' => 'O1 O2 O3 O4',
		'SA' => 'M1 M2 M3', 'SB' => 'M1 M2 M3 N1 N2 N3 O1 O2 O3 O4', 'SC' => 'M1 M2 M3', 'SD' => 'M1 M2 M3',
		'SE' => 'O1 O2 O3 O4', 'SF' => 'N3', 'SG' => 'M1 M2 M3 N1 N2 N3 O1 O2 O3 O4', 'SH' => 'M1',
		'SJ' => 'O1 O2 O3 O4', 'SK' => 'O4', 'SL' => 'N3', 'SM' => 'N1G N2G N3G',
	];

	/** The body codes a two-digit add-on may follow (2018/858 Annex I Part C, Appendix 2). */
	public const WITH_ADD_ON = ['BA', 'BX', 'BC', 'BD', 'DA', 'DB', 'DC', 'DE', 'DF', 'DG'];

	/** The add-ons, 32 added by Reg. (EU) 2022/2236; 99 is bodywork none of them names. */
	public const ADD_ONS = [
		'01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16',
		'17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32',
		'99',
	];

	/**
	 * One field J code, exactly as the certificate prints it: `m1` is no code there either.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function category(string $column, mixed $value): string {
		$known = is_string($value) && (in_array($value, self::CATEGORIES, true)
			|| (str_ends_with($value, 'G') && in_array(substr($value, 0, -1), self::OFF_ROAD, true)));
		if (!$known) {
			throw new \InvalidArgumentException($column . ' is not an EU vehicle category');
		}

		return $value;
	}

	/**
	 * One field 4 code with its add-on, as KBA writes it: `BA03`, `SL` rather than `BCSL`.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function body(string $column, mixed $value): string {
		$code = is_string($value) ? substr($value, 0, 2) : '';
		$addOn = is_string($value) ? substr($value, 2) : '';
		$known = array_key_exists($code, self::BODY_TYPES)
			&& ($addOn === '' || (in_array($code, self::WITH_ADD_ON, true) && in_array($addOn, self::ADD_ONS, true)));
		if (!$known) {
			throw new \InvalidArgumentException($column . ' is not an EU body code');
		}

		return (string)$value;
	}

	/**
	 * Whether a body code belongs to a category, both as stored. No category takes none: the
	 * L, T, C, R and S categories have no body codes.
	 *
	 * @param string $body a code body() took
	 */
	public static function fits(?string $category, string $body): bool {
		if ($category === null) {
			return false;
		}
		$takes = explode(' ', self::BODY_TYPES[substr($body, 0, 2)] ?? '');
		$offRoad = str_ends_with($category, 'G') && in_array(substr($category, 0, -1), self::OFF_ROAD, true);

		return in_array($category, $takes, true) || ($offRoad && in_array(substr($category, 0, -1), $takes, true));
	}
}
