<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Stub;

use OCA\NextFleet\Jurisdiction\Generic;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use Psr\Container\ContainerInterface;

/**
 * The real registration list for a test with no server: every profile it names, built as the app's
 * container builds it. The generic profile takes the reader's `IL10N`, here an English one.
 */
final class RegisteredProfiles implements ContainerInterface {
	public static function jurisdictions(): Jurisdictions {
		return new Jurisdictions(new self());
	}

	public function get(string $id): object {
		if ($id === Generic\Profile::class) {
			return new Generic\Profile(new Untranslated());
		}
		/** @var class-string $id the registration list names only classes */
		return new $id();
	}

	public function has(string $id): bool {
		return class_exists($id);
	}
}
