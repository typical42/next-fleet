<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

/*
 * Grants one account a role on one vehicle, as the sharing UI will (M6):
 *
 *   php custom_apps/nextfleet/tests/e2e/grant.php <vehicle uuid> <uid> <role>
 *
 * Until then nothing writes `fleet_access`, and a download that follows the vehicle's grant needs
 * one to be tested. m5-slice.spec.js runs this inside the container (tests/e2e/server.js).
 */

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\VehicleMapper;

if ($argc !== 4) {
	fwrite(STDERR, "usage: php grant.php <vehicle uuid> <uid> <role>\n");
	exit(2);
}

// tests/e2e/ → nextfleet → custom_apps → the server root, which exists only in the container.
/** @psalm-suppress MissingFile */
require_once __DIR__ . '/../../../../lib/base.php';

$container = (new Application())->getContainer();
$vehicle = $container->get(VehicleMapper::class)->findByUuid($argv[1]);

$grant = new Access();
$grant->setVehicleId((int)$vehicle->getId());
$grant->setGrantee($argv[2]);
$grant->setGranteeType(Access::USER);
$grant->setRole($argv[3]);
$grant->setCreatedBy($vehicle->getUserId());
$container->get(AccessMapper::class)->insert($grant);
echo "{$argv[2]} is {$argv[3]} on {$argv[1]}\n";
