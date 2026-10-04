<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Every `IN` built from a list goes through here: Oracle refuses one of more than 1 000 items
 * (ORA-01795). Core checks each bound list against that limit, not the statement, so an `OR` of
 * short lists passes.
 */
final class InList {
	public const MAX = 1000;

	/**
	 * `$column IN (…)` over the whole list, in one statement. For a list whose length the caller
	 * bounds - a user's vehicles or groups, a page's rows: each item is a parameter, and the
	 * databases cap a statement's parameters at about 65 000.
	 *
	 * @param non-empty-list<int>|non-empty-list<string> $values
	 * @param IQueryBuilder::PARAM_INT_ARRAY|IQueryBuilder::PARAM_STR_ARRAY $type
	 */
	public static function in(IQueryBuilder $qb, string $column, array $values, int $type): string|ICompositeExpression {
		return self::joined($qb, 'in', 'orX', $column, $values, $type);
	}

	/**
	 * `$column NOT IN (…)`, bounded as in() is.
	 *
	 * @param non-empty-list<int>|non-empty-list<string> $values
	 * @param IQueryBuilder::PARAM_INT_ARRAY|IQueryBuilder::PARAM_STR_ARRAY $type
	 */
	public static function notIn(IQueryBuilder $qb, string $column, array $values, int $type): string|ICompositeExpression {
		return self::joined($qb, 'notIn', 'andX', $column, $values, $type);
	}

	/**
	 * The list in pieces Oracle takes, for a caller that runs one query per piece because the list
	 * has no bound. Each piece still goes through in(), which then builds a single `IN`, so that
	 * no `IN` is built anywhere else (InListTest).
	 *
	 * @template T of int|string
	 * @param list<T> $values
	 * @return list<non-empty-list<T>>
	 */
	public static function chunks(array $values): array {
		return array_chunk($values, self::MAX);
	}

	/**
	 * @param 'in'|'notIn' $compare
	 * @param 'orX'|'andX' $join
	 * @param non-empty-list<int>|non-empty-list<string> $values
	 */
	private static function joined(IQueryBuilder $qb, string $compare, string $join, string $column, array $values, int $type): string|ICompositeExpression {
		$parts = array_map(
			static fn (array $chunk): string => $qb->expr()->$compare($column, $qb->createNamedParameter($chunk, $type)),
			self::chunks($values),
		);

		return count($parts) === 1 ? $parts[0] : $qb->expr()->$join(...$parts);
	}
}
