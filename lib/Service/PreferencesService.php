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

	/**
	 * The vehicles whose "complete this vehicle" hint this user has answered (docs/ui.md). A
	 * preference rather than browser state, so the hint stays gone on the next machine - and per
	 * vehicle, because the question is about one vehicle's missing fields and not about hints.
	 */
	private const DISMISSED = 'dismissed_hints';

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
	 * @return array{preferences: array{jurisdiction: string, dismissed_hints: list<string>}, jurisdictions: list<array{key: string, name: string}>}
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
				self::DISMISSED => $this->dismissed($userId),
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
	 * @return array{preferences: array{jurisdiction: string, dismissed_hints: list<string>}, jurisdictions: list<array{key: string, name: string}>}
	 * @throws \InvalidArgumentException if a preference is not one of the answers it may take
	 */
	public function write(string $userId, array $fields): array {
		// Read whole, then stored: one request is one answer, and a payload the second preference
		// makes a bad request must not leave the first one changed behind a 400.
		$values = [];
		if (array_key_exists(self::JURISDICTION, $fields)) {
			$values[self::JURISDICTION] = $this->registeredKey($fields[self::JURISDICTION]);
		}
		if (array_key_exists(self::DISMISSED, $fields)) {
			$values[self::DISMISSED] = json_encode($this->uuids($fields[self::DISMISSED]), JSON_THROW_ON_ERROR);
		}

		foreach ($values as $key => $value) {
			$this->config->setUserValue($userId, Application::APP_ID, $key, $value);
		}

		return $this->forUser($userId);
	}

	/**
	 * The vehicles a dismissal names. The screen sends the whole list back, so this is a replace
	 * and not an append - and everything in it has to look like the identity of a vehicle
	 * (CONTEXT.md), because a preference is not a place to keep whatever a client sends.
	 *
	 * @return list<string>
	 * @throws \InvalidArgumentException
	 */
	private function uuids(mixed $value): array {
		if (!is_array($value)) {
			throw new \InvalidArgumentException(self::DISMISSED . ' is a list of vehicle uuids');
		}

		$uuids = [];
		foreach ($value as $uuid) {
			if (!is_string($uuid) || preg_match('/^[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $uuid) !== 1) {
				throw new \InvalidArgumentException(self::DISMISSED . ' holds something that is no vehicle uuid');
			}

			$uuids[] = $uuid;
		}

		return array_values(array_unique($uuids));
	}

	/**
	 * The dismissed hints as they are stored: one JSON array in one config value, because they are
	 * read and written as a whole and a key per vehicle would be a row per vehicle forever.
	 * Anything else in there is read as nothing - a value nobody can parse is not a dismissal.
	 *
	 * @return list<string>
	 */
	private function dismissed(string $userId): array {
		$stored = json_decode(
			$this->config->getUserValue($userId, Application::APP_ID, self::DISMISSED, '[]'),
			true,
		);

		return is_array($stored) ? array_values(array_filter($stored, is_string(...))) : [];
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
