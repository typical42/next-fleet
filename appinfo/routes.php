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
		// An Odometer Entry is edited, deleted and undone like any Entry. A Reading another Entry
		// wrote is not one: it follows that Entry (docs/architecture.md#odometer-rules, rule 5).
		['name' => 'odometer#update', 'url' => '/api/vehicles/{uuid}/readings/{reading}', 'verb' => 'PUT'],
		['name' => 'odometer#delete', 'url' => '/api/vehicles/{uuid}/readings/{reading}', 'verb' => 'DELETE'],
		['name' => 'odometer#restore', 'url' => '/api/vehicles/{uuid}/readings/{reading}/restore', 'verb' => 'POST'],

		// A Trip is reached the same way, and writes the one Reading it left on the counter
		// (docs/architecture.md#odometer-rules).
		['name' => 'trip#create', 'url' => '/api/vehicles/{uuid}/trips', 'verb' => 'POST'],
		// What the sheet completes route, purpose and partner from (docs/ui.md).
		['name' => 'trip#prefill', 'url' => '/api/vehicles/{uuid}/trips/prefill', 'verb' => 'GET'],
		['name' => 'trip#update', 'url' => '/api/vehicles/{uuid}/trips/{trip}', 'verb' => 'PUT'],
		// A delete voids under Logbook Mode (docs/features.md#logbook-mode), and undo is its own
		// verb and path for the reason a vehicle's is: it is not a write to the trip, it is a
		// write to whether there is one.
		['name' => 'trip#delete', 'url' => '/api/vehicles/{uuid}/trips/{trip}', 'verb' => 'DELETE'],
		['name' => 'trip#restore', 'url' => '/api/vehicles/{uuid}/trips/{trip}/restore', 'verb' => 'POST'],

		// A fill-up is reached the same way, and writes a Reading per counter it was given
		// (docs/architecture.md#odometer-rules).
		['name' => 'energy#create', 'url' => '/api/vehicles/{uuid}/energy', 'verb' => 'POST'],
		// What the sheet prefills a fill-up with: the VAT rate on its day, and this vehicle's
		// stations with their last price (docs/ui.md).
		['name' => 'energy#prefill', 'url' => '/api/vehicles/{uuid}/energy/prefill', 'verb' => 'GET'],
		// Edit, delete and undo as a trip's, with its Readings following it - but a delete is a
		// soft delete under Logbook Mode too, since the mode covers trips only. Not `{energy}`:
		// that is a field of the body, and the body's value would win (tests/Unit/RoutesTest.php).
		['name' => 'energy#update', 'url' => '/api/vehicles/{uuid}/energy/{fillUp}', 'verb' => 'PUT'],
		['name' => 'energy#delete', 'url' => '/api/vehicles/{uuid}/energy/{fillUp}', 'verb' => 'DELETE'],
		['name' => 'energy#restore', 'url' => '/api/vehicles/{uuid}/energy/{fillUp}/restore', 'verb' => 'POST'],
		// A Maintenance Record, with the counter rules of a fill-up; its prefill is the VAT rate
		// and the vendors this vehicle has used.
		['name' => 'maintenance#create', 'url' => '/api/vehicles/{uuid}/maintenance', 'verb' => 'POST'],
		['name' => 'maintenance#prefill', 'url' => '/api/vehicles/{uuid}/maintenance/prefill', 'verb' => 'GET'],
		['name' => 'maintenance#update', 'url' => '/api/vehicles/{uuid}/maintenance/{record}', 'verb' => 'PUT'],
		['name' => 'maintenance#delete', 'url' => '/api/vehicles/{uuid}/maintenance/{record}', 'verb' => 'DELETE'],
		['name' => 'maintenance#restore', 'url' => '/api/vehicles/{uuid}/maintenance/{record}/restore', 'verb' => 'POST'],
		// An Expense knows no counter, so it writes no Reading; its prefill is the VAT rate alone.
		['name' => 'expense#create', 'url' => '/api/vehicles/{uuid}/expenses', 'verb' => 'POST'],
		['name' => 'expense#prefill', 'url' => '/api/vehicles/{uuid}/expenses/prefill', 'verb' => 'GET'],
		['name' => 'expense#update', 'url' => '/api/vehicles/{uuid}/expenses/{expense}', 'verb' => 'PUT'],
		['name' => 'expense#delete', 'url' => '/api/vehicles/{uuid}/expenses/{expense}', 'verb' => 'DELETE'],
		['name' => 'expense#restore', 'url' => '/api/vehicles/{uuid}/expenses/{expense}/restore', 'verb' => 'POST'],
		// A Reminder is not an Entry - nothing happened yet - but it is reached the same way. Its
		// state is the engine's (docs/architecture.md#reminder-engine), so an edit cannot set it.
		['name' => 'reminder#index', 'url' => '/api/vehicles/{uuid}/reminders', 'verb' => 'GET'],
		// The overview's: every visible vehicle's in one read, rather than one read per vehicle.
		['name' => 'reminder#fleet', 'url' => '/api/reminders', 'verb' => 'GET'],
		['name' => 'reminder#create', 'url' => '/api/vehicles/{uuid}/reminders', 'verb' => 'POST'],
		// Beside the collection rather than below it, so no reminder uuid can ever read as a word.
		['name' => 'reminder#templates', 'url' => '/api/vehicles/{uuid}/reminder-templates', 'verb' => 'GET'],
		['name' => 'reminder#update', 'url' => '/api/vehicles/{uuid}/reminders/{reminder}', 'verb' => 'PUT'],
		['name' => 'reminder#delete', 'url' => '/api/vehicles/{uuid}/reminders/{reminder}', 'verb' => 'DELETE'],
		['name' => 'reminder#restore', 'url' => '/api/vehicles/{uuid}/reminders/{reminder}/restore', 'verb' => 'POST'],
		['name' => 'reminder#snooze', 'url' => '/api/vehicles/{uuid}/reminders/{reminder}/snooze', 'verb' => 'POST'],
		['name' => 'reminder#dismiss', 'url' => '/api/vehicles/{uuid}/reminders/{reminder}/dismiss', 'verb' => 'POST'],
		// Who the reminders go to. A recipient is named by their account, which is what the list
		// holds; not `{user_id}`, the field an add sends (tests/Unit/RoutesTest.php).
		['name' => 'recipient#index', 'url' => '/api/vehicles/{uuid}/recipients', 'verb' => 'GET'],
		['name' => 'recipient#create', 'url' => '/api/vehicles/{uuid}/recipients', 'verb' => 'POST'],
		['name' => 'recipient#delete', 'url' => '/api/vehicles/{uuid}/recipients/{recipient}', 'verb' => 'DELETE'],

		// A vehicle's papers. Attaching names a file the user picked in Files, so there is no upload;
		// not `{file_id}`, the field an attach sends (tests/Unit/RoutesTest.php).
		['name' => 'document#index', 'url' => '/api/vehicles/{uuid}/documents', 'verb' => 'GET'],
		['name' => 'document#create', 'url' => '/api/vehicles/{uuid}/documents', 'verb' => 'POST'],
		['name' => 'document#delete', 'url' => '/api/vehicles/{uuid}/documents/{document}', 'verb' => 'DELETE'],

		// What happened to the vehicle, every table at once and a page at a time
		// (docs/architecture.md#the-timeline). `odometer#index` stays where it is: the counter on
		// its own is a different question from the vehicle's history.
		['name' => 'timeline#index', 'url' => '/api/vehicles/{uuid}/timeline', 'verb' => 'GET'],
		// Unpaged, beside it: a month header states the whole month's Gaps, not the page's.
		['name' => 'timeline#gaps', 'url' => '/api/vehicles/{uuid}/gaps', 'verb' => 'GET'],
		// One row of it, read back when an edit lost a race: the sheet writes what is on screen
		// under the token this answers with (docs/ui.md, "Save anyway").
		['name' => 'timeline#show', 'url' => '/api/vehicles/{uuid}/timeline/{type}/{entry}', 'verb' => 'GET'],
		// The header's figures for one period the client names, since where a year starts is the
		// person's midnight, not the server's (docs/ui.md).
		['name' => 'kpi#index', 'url' => '/api/vehicles/{uuid}/kpis', 'verb' => 'GET'],
		// The Costs screen's: those figures for one year, and each month's cost. The client names
		// the zone rather than twelve periods, since a month is cut at its midnight.
		['name' => 'kpi#year', 'url' => '/api/vehicles/{uuid}/costs/{year}', 'verb' => 'GET'],
		// A Gap is named by the trip whose claim opened it, and closing one writes a trip - so it is
		// the trips' controller that answers (docs/features.md#logbook-mode).
		['name' => 'trip#reconcile', 'url' => '/api/vehicles/{uuid}/gaps/{trip}/close', 'verb' => 'POST'],

		// A page the browser prints, not an answer the app reads, so it sits outside `/api`
		// (docs/adr/0005-no-pdf-library.md).
		['name' => 'report#logbook', 'url' => '/vehicles/{uuid}/logbook/{year}', 'verb' => 'GET'],
		['name' => 'report#mileage', 'url' => '/vehicles/{uuid}/mileage/{year}', 'verb' => 'GET'],
		// A file the browser saves, beside the page for the same reason: a link, not a read.
		['name' => 'report#csv', 'url' => '/vehicles/{uuid}/csv/{year}/{table}', 'verb' => 'GET'],

		// The session user's own settings - no identity in the URL, because there is only ever
		// one set of them to reach (lib/Service/PreferencesService.php).
		['name' => 'preferences#index', 'url' => '/api/preferences', 'verb' => 'GET'],
		['name' => 'preferences#update', 'url' => '/api/preferences', 'verb' => 'PUT'],
	],
];
