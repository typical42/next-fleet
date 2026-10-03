<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

/**
 * One source row as the entry it would become. Never a trip: neither format keeps a logbook a
 * ruleset would accept (docs/features.md#logbook-mode).
 */
final class Proposal {
	public const ENERGY = 'energy';
	public const MAINTENANCE = 'maintenance';
	public const EXPENSE = 'expense';
	public const ODOMETER = 'odometer';
	public const KINDS = [self::ENERGY, self::MAINTENANCE, self::EXPENSE, self::ODOMETER];

	public const DUPLICATE = 'duplicate';
	public const UNREADABLE = 'unreadable';

	public function __construct(
		/** One of the kinds above. */
		public readonly string $kind,
		/** @var array<string, int|string|bool|null> named and in the units the entry service takes */
		public readonly array $fields,
		public readonly int $row,
		/** null to create, or one of the outcomes above. */
		public readonly ?string $outcome = null,
		/** Why it is unreadable: a key the screen translates, such as `date`. */
		public readonly ?string $reason = null,
		/** The header of the cell to blame. */
		public readonly ?string $column = null,
	) {
	}

	public function asDuplicate(): self {
		return new self($this->kind, $this->fields, $this->row, self::DUPLICATE);
	}

	/** Whether the import writes this row: a duplicate only when the request asks for them. */
	public function creates(bool $includeDuplicates): bool {
		return $this->outcome === null || ($this->outcome === self::DUPLICATE && $includeDuplicates);
	}

	/**
	 * @return array{row: int, kind: string, fields: array<string, int|string|bool|null>|\stdClass, outcome: ?string, reason: ?string, column: ?string}
	 */
	public function wire(): array {
		return [
			'row' => $this->row,
			'kind' => $this->kind,
			// An empty array would encode as a JSON list, and the OpenAPI document promises an object.
			'fields' => $this->fields === [] ? new \stdClass() : $this->fields,
			'outcome' => $this->outcome,
			'reason' => $this->reason,
			'column' => $this->column,
		];
	}
}
