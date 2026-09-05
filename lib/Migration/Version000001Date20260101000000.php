<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\NextFleet\Migration;

use OCP\Migration\SimpleMigrationStep;

// TODO: first schema — vehicles, odo readings, shares.
//
// A no-op until then, and not an empty file: Nextcloud reads this directory when the app is
// enabled and rejects the whole app — "Not a valid migration" — over a class that is not a
// migration step.
class Version000001Date20260101000000 extends SimpleMigrationStep {
}
