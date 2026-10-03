<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Api;

/**
 * What a client built against one OpenAPI document loses when the server offers another.
 *
 * A request is checked for what it may no longer send, an answer for what it may no longer find.
 * So a field may join an answer, and leave a request, without breaking anyone.
 */
final class Contract {
	private const METHODS = ['get', 'put', 'post', 'delete', 'patch'];

	/** @var list<string> */
	private array $breaks = [];

	/**
	 * @param array<string, mixed> $promised
	 * @param array<string, mixed> $offered
	 */
	private function __construct(
		private array $promised,
		private array $offered,
	) {
	}

	/**
	 * @param array<string, mixed> $promised the document clients were built against
	 * @param array<string, mixed> $offered the document the server answers now
	 * @return list<string> one line per broken promise, empty when every one is kept
	 */
	public static function breaks(array $promised, array $offered): array {
		$contract = new self($promised, $offered);
		$contract->paths();

		return $contract->breaks;
	}

	private function paths(): void {
		foreach ((array)($this->promised['paths'] ?? []) as $path => $operations) {
			foreach (self::METHODS as $method) {
				if (!isset($operations[$method])) {
					continue;
				}
				$where = strtoupper($method) . ' ' . $path;
				$now = $this->offered['paths'][$path][$method] ?? null;
				if (!is_array($now)) {
					$this->breaks[] = "$where: gone";
					continue;
				}
				$this->request($where, (array)$operations[$method], $now);
				$this->responses($where, (array)$operations[$method], $now);
			}
		}
	}

	/**
	 * @param array<array-key, mixed> $was
	 * @param array<array-key, mixed> $is
	 */
	private function request(string $where, array $was, array $is): void {
		$asked = [];
		foreach ((array)($was['parameters'] ?? []) as $parameter) {
			$asked["{$parameter['in']} {$parameter['name']}"] = $parameter;
		}
		foreach ((array)($is['parameters'] ?? []) as $parameter) {
			$named = "{$parameter['in']} {$parameter['name']}";
			if (self::on($parameter, 'required') && !self::on($asked[$named] ?? null, 'required')) {
				$this->breaks[] = "$where $named: newly required";
			}
			if (isset($asked[$named])) {
				$this->ask("$where $named", '', $asked[$named]['schema'] ?? [], $parameter['schema'] ?? [], []);
			}
		}

		if (self::on($is['requestBody'] ?? null, 'required') && !self::on($was['requestBody'] ?? null, 'required')) {
			$this->breaks[] = "$where body: newly required";
		}
		foreach ((array)($was['requestBody']['content'] ?? []) as $type => $body) {
			if (!isset($is['requestBody']['content'][$type])) {
				$this->breaks[] = "$where body $type: gone";
				continue;
			}
			$this->ask("$where body", '', $body['schema'] ?? [], $is['requestBody']['content'][$type]['schema'] ?? [], []);
		}
	}

	/**
	 * What a client sends must still pass.
	 *
	 * @param list<string> $seen as for answer()
	 */
	private function ask(string $where, string $at, mixed $was, mixed $is, array $seen): void {
		[$was, $wasName] = $this->resolve($this->promised, $was);
		[$is, $isName] = $this->resolve($this->offered, $is);
		if (!self::unseen($seen, $wasName, $isName)) {
			return;
		}
		// A field that now takes anything takes what the client sends.
		if (self::type($is) !== 'any' && !$this->sameType($where, $at, $was, $is)) {
			return;
		}
		if (self::on($was, 'nullable') && !self::on($is, 'nullable')) {
			$this->breaks[] = self::point($where, $at) . ' no longer takes null';
		}

		$required = (array)($was['required'] ?? []);
		foreach ((array)($is['required'] ?? []) as $name) {
			if (!in_array($name, $required, true)) {
				$this->breaks[] = self::point($where, self::field($at, (string)$name)) . ' newly required';
			}
		}
		foreach ((array)($was['properties'] ?? []) as $name => $field) {
			if (isset($is['properties'][$name])) {
				$this->ask($where, self::field($at, (string)$name), $field, $is['properties'][$name], $seen);
			}
		}
		if (isset($was['items'], $is['items'])) {
			$this->ask($where, "{$at}[]", $was['items'], $is['items'], $seen);
		}
	}

	/**
	 * A refusal that can no longer happen breaks nobody; a success that is no longer answered
	 * leaves a client without the answer it reads.
	 *
	 * @param array<array-key, mixed> $was
	 * @param array<array-key, mixed> $is
	 */
	private function responses(string $where, array $was, array $is): void {
		foreach ((array)($was['responses'] ?? []) as $status => $answer) {
			$now = $is['responses'][$status] ?? null;
			if (!is_array($now)) {
				if ((int)$status >= 200 && (int)$status < 300) {
					$this->breaks[] = "$where $status: gone";
				}
				continue;
			}
			foreach ((array)($answer['content'] ?? []) as $type => $body) {
				if (!isset($now['content'][$type])) {
					$this->breaks[] = "$where $status $type: gone";
					continue;
				}
				$this->answer("$where $status", '', $body['schema'] ?? [], $now['content'][$type]['schema'] ?? [], []);
			}
		}
	}

	/**
	 * What a client reads must still be there, and be what it was.
	 *
	 * @param list<string> $seen the pairs of named schemas above this one, so a cycle ends
	 */
	private function answer(string $where, string $at, mixed $was, mixed $is, array $seen): void {
		[$was, $wasName] = $this->resolve($this->promised, $was);
		[$is, $isName] = $this->resolve($this->offered, $is);
		if (!self::unseen($seen, $wasName, $isName) || !$this->sameType($where, $at, $was, $is)) {
			return;
		}
		if (!self::on($was, 'nullable') && self::on($is, 'nullable')) {
			$this->breaks[] = self::point($where, $at) . ' may now be null';
		}

		$required = (array)($is['required'] ?? []);
		foreach ((array)($was['properties'] ?? []) as $name => $field) {
			$there = self::field($at, (string)$name);
			if (!isset($is['properties'][$name])) {
				$this->breaks[] = self::point($where, $there) . ' gone';
				continue;
			}
			if (in_array($name, (array)($was['required'] ?? []), true) && !in_array($name, $required, true)) {
				$this->breaks[] = self::point($where, $there) . ' may now be missing';
			}
			$this->answer($where, $there, $field, $is['properties'][$name], $seen);
		}
		if (isset($was['items'], $is['items'])) {
			$this->answer($where, "{$at}[]", $was['items'], $is['items'], $seen);
		}
	}

	/**
	 * @param array<array-key, mixed> $was
	 * @param array<array-key, mixed> $is
	 */
	private function sameType(string $where, string $at, array $was, array $is): bool {
		if (self::type($was) === self::type($is)) {
			return true;
		}
		$this->breaks[] = self::point($where, $at) . ' was ' . self::type($was) . ', is ' . self::type($is);

		return false;
	}

	/**
	 * The type as a client decodes it: an int32 and an int64 are different integers to Kotlin.
	 *
	 * @param array<array-key, mixed> $schema
	 */
	private static function type(array $schema): string {
		$type = is_string($schema['type'] ?? null) ? $schema['type'] : 'any';

		return is_string($schema['format'] ?? null) ? "$type/{$schema['format']}" : $type;
	}

	/**
	 * A schema with its `$ref` and `allOf` followed.
	 *
	 * @param array<string, mixed> $document the document the schema is part of
	 * @return array{0: array<array-key, mixed>, 1: ?string} the schema, and its name if it has one:
	 *                                                       an `allOf` is named by its parts
	 */
	private function resolve(array $document, mixed $schema): array {
		$schema = is_array($schema) ? $schema : [];
		$name = null;
		if (is_string($schema['$ref'] ?? null)) {
			$name = substr($schema['$ref'], strlen('#/components/schemas/'));
			$schema = (array)($document['components']['schemas'][$name] ?? []);
		}
		if (is_array($schema['allOf'] ?? null)) {
			$typed = [];
			$properties = [];
			$required = [];
			$nullable = self::on($schema, 'nullable');
			$names = [];
			foreach ($schema['allOf'] as $part) {
				[$part, $names[]] = $this->resolve($document, $part);
				$typed += array_intersect_key($part, ['type' => true, 'format' => true]);
				$properties += (array)($part['properties'] ?? []);
				$required = [...$required, ...(array)($part['required'] ?? [])];
				$nullable = $nullable || self::on($part, 'nullable');
			}
			$schema = $typed + ['properties' => $properties, 'required' => $required, 'nullable' => $nullable];
			$name = in_array(null, $names, true) ? null : implode('&', $names);
		}

		return [$schema, $name];
	}

	/**
	 * Whether this pair of named schemas is new on the way down, noting it if so.
	 *
	 * @param list<string> $seen
	 */
	private static function unseen(array &$seen, ?string $was, ?string $is): bool {
		if ($was === null || $is === null) {
			return true;
		}
		if (in_array("$was $is", $seen, true)) {
			return false;
		}
		$seen[] = "$was $is";

		return true;
	}

	private static function on(mixed $node, string $flag): bool {
		return is_array($node) && ($node[$flag] ?? null) === true;
	}

	private static function field(string $at, string $name): string {
		return $at === '' ? $name : "$at.$name";
	}

	private static function point(string $where, string $at): string {
		return $at === '' ? "$where:" : "$where: $at";
	}
}
