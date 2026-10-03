<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

/**
 * Where a client's sync stands (docs/api.md#sync), handed out opaque and read back as it was.
 *
 * Vehicles are held by id and listed by uuid. An id is only ever intersected with what the caller
 * reaches now, so a forged one widens nothing; a listed uuid is answered back as `unreachable`, so
 * it has to be one the client already knew.
 */
final class SyncCursor {
	/**
	 * The tables a sync carries, in the order that breaks a tie between rows of one `updated_at`.
	 * A table added later goes at the end: moving one would re-order a run in progress.
	 */
	public const TABLES = ['readings', 'trips', 'energy', 'maintenance', 'expenses', 'reminders', 'documents', 'bookings', 'grants'];

	private const FORGED = 'cursor is not one this route hands out';

	/**
	 * @param string $epoch the erasure it was handed out after (SyncService::EPOCH)
	 * @param int $since rows on a held vehicle count when changed after this
	 * @param list<int> $held the vehicles whose rows the client has through `since`
	 * @param list<string> $listed the vehicles the last answer listed
	 * @param ?int $mark the `since` a run in progress ends at
	 * @param list<int> $run the vehicles a run in progress covers
	 * @param ?array{int, string, int} $at the last row a run in progress answered: `updated_at`, table, `id`
	 */
	private function __construct(
		public readonly string $epoch,
		public readonly int $since,
		public readonly array $held,
		public readonly array $listed,
		public readonly ?int $mark = null,
		public readonly array $run = [],
		private readonly ?array $at = null,
	) {
	}

	/** A client that holds nothing yet. */
	public static function first(string $epoch): self {
		return new self($epoch, 0, [], []);
	}

	/**
	 * A cursor from before an erasure, in the epoch after it: nothing held, since pseudonymised rows
	 * kept their `updated_at`, and what it listed kept so a vehicle gone since is still named.
	 */
	public function anew(string $epoch): self {
		return new self($epoch, 0, [], $this->listed);
	}

	/**
	 * Mid-run: more rows wait after `$at`, and what is held does not change until the run ends.
	 *
	 * @param list<string> $listed
	 * @param list<int> $run
	 * @param array{int, string, int} $at
	 */
	public function paged(array $listed, array $run, int $mark, array $at): self {
		return new self($this->epoch, $this->since, $this->held, $listed, $mark, $run, $at);
	}

	/**
	 * A run's last page: the client now has every vehicle it covered, through the mark it started at.
	 *
	 * @param list<string> $listed
	 * @param list<int> $run
	 */
	public function finished(array $listed, array $run, int $mark): self {
		return new self($this->epoch, $mark, $run, $listed);
	}

	public function inRun(): bool {
		return $this->mark !== null;
	}

	/**
	 * Where `$table` resumes: rows whose `updated_at` is past the instant, or at it with an `id`
	 * past the second value - none at it when that is null. Paging is by (`updated_at`, table,
	 * `id`), so a table before the last row's resumes after its instant, a table after it at it.
	 *
	 * @return array{int, ?int}
	 */
	public function after(string $table): array {
		if ($this->at === null) {
			return [-1, null];
		}
		[$updatedAt, $lastTable, $id] = $this->at;

		return [$updatedAt, match (array_search($table, self::TABLES, true) <=> array_search($lastTable, self::TABLES, true)) {
			-1 => null,
			0 => $id,
			1 => 0,
		}];
	}

	public function encode(): string {
		$state = ['e' => $this->epoch, 's' => $this->since, 'h' => $this->held, 'l' => $this->listed];
		if ($this->at !== null) {
			$state += ['m' => $this->mark, 'r' => $this->run, 'p' => $this->at];
		}

		return rtrim(strtr(base64_encode(json_encode($state, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
	}

	/**
	 * @throws \InvalidArgumentException if it is not a cursor this class encoded
	 */
	public static function decode(string $raw): self {
		$json = base64_decode(strtr($raw, '-_', '+/'), true);
		$state = $json === false ? null : json_decode($json, true);
		if (!is_array($state)
			|| !is_string($state['e'] ?? null)
			|| !is_int($state['s'] ?? null)
			|| !self::isListOf('is_int', $state['h'] ?? null)
			|| !self::isListOf('is_string', $state['l'] ?? null)) {
			throw new \InvalidArgumentException(self::FORGED);
		}
		$cursor = new self($state['e'], $state['s'], $state['h'], $state['l']);
		if (!isset($state['m']) && !isset($state['r']) && !isset($state['p'])) {
			return $cursor;
		}

		$at = $state['p'] ?? null;
		if (!is_int($state['m'] ?? null)
			|| !self::isListOf('is_int', $state['r'] ?? null)
			|| !is_array($at) || !array_is_list($at) || count($at) !== 3
			|| !is_int($at[0]) || !in_array($at[1], self::TABLES, true) || !is_int($at[2])) {
			throw new \InvalidArgumentException(self::FORGED);
		}

		return $cursor->paged($cursor->listed, $state['r'], $state['m'], $at);
	}

	/**
	 * @param callable(mixed): bool $is
	 * @psalm-assert-if-true list $value
	 */
	private static function isListOf(callable $is, mixed $value): bool {
		return is_array($value) && array_is_list($value) && array_filter($value, $is) === $value;
	}
}
