<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// The OCS surface arrives with the Android client — see docs/adr/0006-one-api-surface-in-v1.md.
return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// A vehicle is reached by its uuid; the plate is a label (CONTEXT.md).
		['name' => 'vehicle#index', 'url' => '/api/vehicles', 'verb' => 'GET'],
		['name' => 'vehicle#create', 'url' => '/api/vehicles', 'verb' => 'POST'],
		['name' => 'vehicle#show', 'url' => '/api/vehicles/{uuid}', 'verb' => 'GET'],
		['name' => 'vehicle#update', 'url' => '/api/vehicles/{uuid}', 'verb' => 'PUT'],
		['name' => 'vehicle#delete', 'url' => '/api/vehicles/{uuid}', 'verb' => 'DELETE'],

		// A Reading belongs to a vehicle and is reached through it, so one access check covers
		// both (docs/adr/0001-own-access-table.md).
		['name' => 'odometer#index', 'url' => '/api/vehicles/{uuid}/readings', 'verb' => 'GET'],
		['name' => 'odometer#create', 'url' => '/api/vehicles/{uuid}/readings', 'verb' => 'POST'],
	],
];
