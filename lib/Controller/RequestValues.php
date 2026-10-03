<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

/**
 * The few values a controller reads from the request itself rather than handing them to a service
 * whole. Shared by both doors, internal and OCS, so the two read a request alike and refuse it
 * alike: each refusal is an `\InvalidArgumentException`, which both answer as a 400 (docs/api.md).
 *
 * Wants `$request` (Controller's) on the class that uses it.
 */
trait RequestValues {
	/**
	 * The `updated_at` the client read, which the write is checked against
	 * (docs/architecture.md#concurrency). A DELETE has no body, so it travels in the query
	 * string; a PUT carries it in the body beside the fields. Both arrive as request parameters.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function token(): int {
		return $this->whole('updated_at') ?? throw new \InvalidArgumentException('updated_at is missing, so this write cannot be checked');
	}

	/**
	 * The Gap a reconcile closes, as the driver confirmed it: its kilometres and its two moments.
	 *
	 * @return array{int, int, int} distance, from_at, to_at
	 * @throws \InvalidArgumentException
	 */
	private function gap(): array {
		$gap = [$this->whole('distance'), $this->whole('from_at'), $this->whole('to_at')];
		if (in_array(null, $gap, true)) {
			throw new \InvalidArgumentException('distance, from_at and to_at name the gap being closed');
		}

		/** @var array{int, int, int} $gap */
		return $gap;
	}

	/** One whole number from the request, or null when it is absent or is not one. */
	private function whole(string $name): ?int {
		$number = filter_var($this->request->getParams()[$name] ?? null, FILTER_VALIDATE_INT);

		return $number === false ? null : $number;
	}

	/**
	 * One query-string value as a service takes it. The framework casts a controller's int,
	 * float and bool parameters and nothing else, so `?type[]=trip` arrives as an array - a
	 * `?string` parameter would make that a 500, when it is a request no route handed out like
	 * any other.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function word(string $name, mixed $value): ?string {
		if ($value !== null && !is_string($value)) {
			throw new \InvalidArgumentException($name . ' is a word or nothing at all');
		}

		return $value;
	}
}
