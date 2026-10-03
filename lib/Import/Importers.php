<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

/**
 * The formats an import reads, by the key a request names one with (IImporter::key()).
 */
class Importers {
	/** @var array<string, IImporter> */
	private array $byKey = [];

	public function __construct(LubeLoggerImporter $lubeLogger, SpritmonitorImporter $spritmonitor) {
		foreach ([$lubeLogger, $spritmonitor] as $importer) {
			$this->byKey[$importer->key()] = $importer;
		}
	}

	/** @throws \InvalidArgumentException */
	public function get(mixed $key): IImporter {
		if (!is_string($key) || !isset($this->byKey[$key])) {
			throw new \InvalidArgumentException('importer is one of ' . implode(', ', array_keys($this->byKey)));
		}

		return $this->byKey[$key];
	}
}
