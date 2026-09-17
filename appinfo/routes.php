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
		['name' => 'trip#update', 'url' => '/api/vehicles/{uuid}/trips/{trip}', 'verb' => 'PUT'],
		// A delete voids under Logbook Mode (docs/features.md#logbook-mode), and undo is its own
		// verb and path for the reason a vehicle's is: it is not a write to the trip, it is a
		// write to whether there is one.
		['name' => 'trip#delete', 'url' => '/api/vehicles/{uuid}/trips/{trip}', 'verb' => 'DELETE'],
		['name' => 'trip#restore', 'url' => '/api/vehicles/{uuid}/trips/{trip}/restore', 'verb' => 'POST'],

		// What happened to the vehicle, every table at once and a page at a time
		// (docs/architecture.md#the-timeline). `odometer#index` stays where it is: the counter on
		// its own is a different question from the vehicle's history.
		['name' => 'timeline#index', 'url' => '/api/vehicles/{uuid}/timeline', 'verb' => 'GET'],
		// Unpaged, beside it: a month header states the whole month's Gaps, not the page's.
		['name' => 'timeline#gaps', 'url' => '/api/vehicles/{uuid}/gaps', 'verb' => 'GET'],
		// A Gap is named by the trip whose claim opened it, and closing one writes a trip - so it is
		// the trips' controller that answers (docs/features.md#logbook-mode).
		['name' => 'trip#reconcile', 'url' => '/api/vehicles/{uuid}/gaps/{trip}/close', 'verb' => 'POST'],

		// A page the browser prints, not an answer the app reads, so it sits outside `/api`
		// (docs/adr/0005-no-pdf-library.md).
		['name' => 'report#logbook', 'url' => '/vehicles/{uuid}/logbook/{year}', 'verb' => 'GET'],

		// The session user's own settings - no identity in the URL, because there is only ever
		// one set of them to reach (lib/Service/PreferencesService.php).
		['name' => 'preferences#index', 'url' => '/api/preferences', 'verb' => 'GET'],
		['name' => 'preferences#update', 'url' => '/api/preferences', 'verb' => 'PUT'],
	],
];
