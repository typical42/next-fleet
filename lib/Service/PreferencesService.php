<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Jurisdiction\IJurisdiction;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCP\IConfig;

/**
 * One user's own choices, as the personal settings screen reads and writes them. Nothing here is
 * per instance: a jurisdiction belongs to a person and to the vehicles they create afterwards,
 * never to the server (CONTEXT.md).
 */
class PreferencesService {
	/**
	 * The keys this app keeps in a user's config. Spelled out because the screen is not their
	 * only reader - `VehicleService::jurisdictionOf()` reads `jurisdiction` under the same app id
	 * every time a vehicle is created, which is what makes the setting take effect at all.
	 */
	private const JURISDICTION = 'jurisdiction';

	public function __construct(
		private IConfig $config,
		private Jurisdictions $jurisdictions,
	) {
	}

	/**
	 * What the settings screen shows: this user's choices, and the vocabulary each one is picked
	 * from. One answer rather than two routes, because a value without its options is a dropdown
	 * with nothing in it (docs/adr/0006-one-api-surface-in-v1.md).
	 *
	 * @return array{preferences: array{jurisdiction: string}, jurisdictions: list<array{key: string, name: string}>}
	 */
	public function forUser(string $userId): array {
		return [
			'preferences' => [
				// As it stands, not as the list would correct it: a key the offered countries no
				// longer hold is still what the next vehicle is written under, and a dropdown
				// showing nothing selected says that more honestly than a silent default would.
				self::JURISDICTION => $this->config->getUserValue(
					$userId,
					Application::APP_ID,
					self::JURISDICTION,
					Jurisdictions::DEFAULT,
				),
			],
			'jurisdictions' => array_map(
				// The name is English and reaches no catalogue here; the screen translates it
				// (docs/ui.md#languages) and falls back to this for a country it has no word for.
				static fn (IJurisdiction $profile): array => [
					'key' => $profile->key(),
					'name' => $profile->displayName(),
				],
				$this->jurisdictions->all(),
			),
		];
	}

	/**
	 * Store what the screen chose and answer with the screen's whole state, so one round trip
	 * leaves the client holding the truth. A field this app keeps no preference for is passed
	 * over rather than refused: Nextcloud merges its own routing parameters into every request.
	 *
	 * @param array<string, mixed> $fields
	 * @return array{preferences: array{jurisdiction: string}, jurisdictions: list<array{key: string, name: string}>}
	 * @throws \InvalidArgumentException if a preference is not one of the answers it may take
	 */
	public function write(string $userId, array $fields): array {
		if (array_key_exists(self::JURISDICTION, $fields)) {
			$this->config->setUserValue(
				$userId,
				Application::APP_ID,
				self::JURISDICTION,
				$this->registeredKey($fields[self::JURISDICTION]),
			);
		}

		return $this->forUser($userId);
	}

	/**
	 * The screen offers the registration list, so the list is also what it may send back. An
	 * unknown key would not throw on the way out - `Jurisdictions::get()` answers generically for
	 * anything - and would quietly leave every later vehicle without a currency.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function registeredKey(mixed $value): string {
		$keys = array_map(
			static fn (IJurisdiction $profile): string => $profile->key(),
			$this->jurisdictions->all(),
		);

		if (!is_string($value) || !in_array($value, $keys, true)) {
			throw new \InvalidArgumentException(self::JURISDICTION . ' is one of ' . implode(', ', $keys));
		}

		return $value;
	}
}
