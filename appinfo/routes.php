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
		// Undo, which is the only way back out of the trash in v1 (docs/ui.md). Its own verb and
		// path because it is not a write to the vehicle - it is a write to whether there is one.
		['name' => 'vehicle#restore', 'url' => '/api/vehicles/{uuid}/restore', 'verb' => 'POST'],

		// A Reading belongs to a vehicle and is reached through it, so one access check covers
		// both (docs/adr/0001-own-access-table.md).
		['name' => 'odometer#index', 'url' => '/api/vehicles/{uuid}/readings', 'verb' => 'GET'],
		['name' => 'odometer#create', 'url' => '/api/vehicles/{uuid}/readings', 'verb' => 'POST'],

		// A Trip is reached the same way, and writes the one Reading it left on the counter
		// (docs/architecture.md#odometer-rules).
		['name' => 'trip#create', 'url' => '/api/vehicles/{uuid}/trips', 'verb' => 'POST'],

		// The session user's own settings - no identity in the URL, because there is only ever
		// one set of them to reach (lib/Service/PreferencesService.php).
		['name' => 'preferences#index', 'url' => '/api/preferences', 'verb' => 'GET'],
		['name' => 'preferences#update', 'url' => '/api/preferences', 'verb' => 'PUT'],
	],
];
