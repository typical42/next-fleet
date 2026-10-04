<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\BaseEntity;
use OCA\NextFleet\Db\BaseMapper;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;

/**
 * A create a client may send again under its `client_uuid` (docs/api.md#retried-creates): the
 * row that uuid names is answered instead of a second one written. When it is looked for, and
 * why, is in docs/architecture.md#concurrency.
 *
 * @template T of BaseEntity
 */
final class Once {
	private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * @param BaseMapper<T> $rows
	 * @param \Closure(T): bool $ours whether the row is one this create would have written
	 * @param \Closure(T): mixed $answer what the create returns for it
	 */
	private function __construct(
		public readonly ?string $uuid,
		private BaseMapper $rows,
		private \Closure $ours,
		private \Closure $answer,
	) {
	}

	/**
	 * @template E of BaseEntity
	 * @param array<string, mixed> $fields the create's request, `client_uuid` optional
	 * @param BaseMapper<E> $rows the table the create writes
	 * @param \Closure(E): bool $ours
	 * @param \Closure(E): mixed $answer
	 * @return self<E>
	 * @throws \InvalidArgumentException if `client_uuid` is not a uuid
	 */
	public static function of(array $fields, BaseMapper $rows, \Closure $ours, \Closure $answer): self {
		$uuid = $fields['client_uuid'] ?? null;
		if ($uuid !== null && (!is_string($uuid) || preg_match(self::UUID, $uuid) !== 1)) {
			throw new \InvalidArgumentException('client_uuid is a uuid');
		}

		// Stored in lowercase, as the server's own are: the column compares as each database
		// collates, and an uppercase twin would not be found on all of them.
		return new self($uuid === null ? null : strtolower($uuid), $rows, $ours, $answer);
	}

	/** Gives the row about to be inserted the client's uuid, when it sent one. */
	public function stamp(BaseEntity $row): void {
		if ($this->uuid !== null) {
			$row->setUuid($this->uuid);
		}
	}

	/**
	 * @throws AlreadyCreatedException if the uuid names this create's row already
	 * @throws \InvalidArgumentException if it names a row this create would not have written
	 * @throws \OCP\DB\Exception
	 */
	public function check(): void {
		if ($this->uuid === null) {
			return;
		}
		try {
			$row = $this->rows->findAnyByUuid($this->uuid);
		} catch (DoesNotExistException) {
			return;
		}
		// One answer for every other row, so the refusal tells nothing about whose it is.
		if (!($this->ours)($row)) {
			throw new \InvalidArgumentException('client_uuid is taken');
		}

		throw new AlreadyCreatedException(($this->answer)($row));
	}

	/**
	 * The create, checked first, and answered as check() answers when the uuid's unique index
	 * refused its insert.
	 *
	 * @template R
	 * @param \Closure(): R $create
	 * @return R
	 * @throws AlreadyCreatedException
	 * @throws \InvalidArgumentException
	 * @throws \OCP\DB\Exception
	 */
	public function run(\Closure $create): mixed {
		$this->check();
		try {
			return $create();
		} catch (Exception $e) {
			if ($this->uuid === null || $e->getReason() !== Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$this->check();
			// Another unique index than the uuid's.
			throw $e;
		}
	}
}
