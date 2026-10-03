<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

/** One HTTP answer as it arrived. */
final class Answer {
	/** @param array<string, string> $headers by lower-case name */
	public function __construct(
		public readonly int $status,
		public readonly array $headers,
		public readonly string $body,
	) {
	}

	public function header(string $name): ?string {
		return $this->headers[strtolower($name)] ?? null;
	}

	/** @return array<array-key, mixed> */
	private function json(): array {
		$json = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($json)) {
			throw new \UnexpectedValueException("Not a JSON object: $this->body");
		}

		return $json;
	}

	/**
	 * The OCS envelope's `data` (docs/api.md#answers).
	 *
	 * @return array<array-key, mixed>
	 */
	public function data(): array {
		$data = $this->json()['ocs']['data'] ?? null;
		if (!is_array($data)) {
			throw new \UnexpectedValueException("Not an OCS answer: $this->body");
		}

		return $data;
	}
}
