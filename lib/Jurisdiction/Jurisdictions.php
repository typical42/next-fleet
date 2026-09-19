<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

use Psr\Container\ContainerInterface;

/**
 * The one registration list docs/contributing.md promises: adding a country is a directory and a
 * line here, and nothing else in the core learns its name.
 */
class Jurisdictions {
	/**
	 * What a vehicle takes when the user has stated no preference (plan.md) - a statement about
	 * this release, not about Germany: naming another country here leaves `de` registered and
	 * every vehicle already carrying it untouched.
	 */
	public const DEFAULT = 'de';

	/** The profile every key nobody has written resolves to. */
	public const FALLBACK = 'generic';

	/**
	 * One line per country (docs/contributing.md), keys spelled out: a registration is not the
	 * place to learn which of them this release defaults to.
	 *
	 * @var array<string, class-string<IJurisdiction>>
	 */
	private const PROFILES = [
		'de' => De\Profile::class,
		'generic' => Generic\Profile::class,
	];

	/**
	 * Profiles are built by the container rather than by `new`, so the first one that needs an
	 * `IL10N`, a clock or a rate table takes it in its constructor and no caller changes.
	 */
	public function __construct(
		private ContainerInterface $container,
	) {
	}

	/**
	 * The profile a vehicle is read under. A key this release has never heard of - a country
	 * removed since the row was written, or a typo in the user's config - is the generic
	 * profile, never an exception: a vehicle must open whatever its column says.
	 */
	public function get(string $key): IJurisdiction {
		/** @var IJurisdiction */
		return $this->container->get(self::PROFILES[$key] ?? self::PROFILES[self::FALLBACK]);
	}

	/**
	 * The VAT rate a cost is prefilled with, in basis points, or null where the profile states
	 * none. Read on the day the moment falls on at the offset it was entered at: a rate changes on
	 * a day, and the day is the person's, not UTC's (docs/architecture.md#time).
	 */
	public function vatRateAt(string $key, int $at, int $off): ?int {
		$zone = sprintf('%s%02d:%02d', $off < 0 ? '-' : '+', intdiv(abs($off), 60), abs($off) % 60);

		return $this->get($key)->rates()?->vatRateAt((new \DateTimeImmutable('@' . $at))->setTimezone(new \DateTimeZone($zone)));
	}

	/**
	 * Every profile this release ships, in registration order - what a screen offers and what
	 * the country test kit runs against.
	 *
	 * @return list<IJurisdiction>
	 */
	public function all(): array {
		return array_map(fn (string $key): IJurisdiction => $this->get($key), array_keys(self::PROFILES));
	}
}
