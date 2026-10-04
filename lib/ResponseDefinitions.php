<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet;

/**
 * The wire forms the OCS API answers with, by name. The entities and services that build them
 * declare these as their return types, so Psalm holds the code to what the API promises and the
 * OpenAPI document is read from the same place (docs/api.md). Keys are column names, as on the
 * internal routes.
 *
 * A row's `uuid` is its identity and `updated_at` the token its next write carries back
 * (docs/architecture.md#concurrency). Instants are unix seconds, each user-facing one with its
 * `<name>_off` in minutes; money, distance and volume are integers.
 *
 * Every shape is declared here, never imported: the OpenAPI extractor reads this class alone. A
 * service that builds one imports it back under its own name.
 *
 * @psalm-type NextFleetVehicle = array{
 *     uuid: string,
 *     user_id: string,
 *     plate: ?string,
 *     manufacturer: ?string,
 *     model: ?string,
 *     vehicle_type: string,
 *     engine: ?string,
 *     energy_types: ?list<string>,
 *     tank_ml: ?int,
 *     battery_wh: ?int,
 *     first_reg: ?string,
 *     disposed_at: ?string,
 *     vin: ?string,
 *     odo_value: ?int,
 *     odo_unit: string,
 *     purchase_price: ?int,
 *     residual_est: ?int,
 *     currency: ?string,
 *     jurisdiction: string,
 *     logbook_mode: ?bool,
 *     lifecycle: string,
 *     folder_file_id: ?int,
 *     retention_months: ?int,
 *     color: ?string,
 *     notes: ?string,
 *     second_unit: ?string,
 *     second_value: ?int,
 *     reminder_mail: string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 *     may: list<string>,
 *     owned_by: ?string,
 *     out_with: ?array{user_id: string, user_name: string, ends_at: int, ends_at_off: int},
 *     my_next_booking: ?array{uuid: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int},
 *     ever_granted: bool,
 * }
 *
 * @psalm-type NextFleetReading = array{
 *     uuid: string,
 *     read_at: int,
 *     read_at_off: int,
 *     value: int,
 *     kind: string,
 *     origin: string,
 *     flagged: bool,
 *     source_type: string,
 *     source_id: ?int,
 *     source_uuid: ?string,
 *     counter: string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * @psalm-type NextFleetTrip = array{
 *     uuid: string,
 *     started_at: int,
 *     started_at_off: int,
 *     ended_at: int,
 *     ended_at_off: int,
 *     start_odo: ?int,
 *     end_odo: ?int,
 *     distance: ?int,
 *     from_label: ?string,
 *     to_label: ?string,
 *     purpose: ?string,
 *     partner: ?string,
 *     category: string,
 *     reconciled: bool,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * A fill-up as a timeline row holds it; the row carries its `flags` beside it.
 *
 * @psalm-type NextFleetEnergyEntry = array{
 *     uuid: string,
 *     filled_at: int,
 *     filled_at_off: int,
 *     odo: ?int,
 *     second_odo: ?int,
 *     energy: string,
 *     amount: int,
 *     unit_price: ?int,
 *     total: ?int,
 *     vat_rate: ?int,
 *     full_tank: bool,
 *     missed_previous: bool,
 *     station: ?string,
 *     is_dc: bool,
 *     location_kind: ?string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * A fill-up as a write answers it: the entry and what it is flagged for.
 *
 * @psalm-type NextFleetEnergy = array{
 *     uuid: string,
 *     filled_at: int,
 *     filled_at_off: int,
 *     odo: ?int,
 *     second_odo: ?int,
 *     energy: string,
 *     amount: int,
 *     unit_price: ?int,
 *     total: ?int,
 *     vat_rate: ?int,
 *     full_tank: bool,
 *     missed_previous: bool,
 *     station: ?string,
 *     is_dc: bool,
 *     location_kind: ?string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 *     flags: list<string>,
 * }
 *
 * A Maintenance Record as a timeline row holds it; the row carries `closes` beside it.
 *
 * @psalm-type NextFleetMaintenanceEntry = array{
 *     uuid: string,
 *     type: ?string,
 *     done_at: int,
 *     done_at_off: int,
 *     odo: ?int,
 *     second_odo: ?int,
 *     title: string,
 *     vendor: ?string,
 *     cost: ?int,
 *     vat_rate: ?int,
 *     notes: ?string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * A Maintenance Record as a write answers it: the entry and the reminder it closes, by uuid.
 *
 * @psalm-type NextFleetMaintenance = array{
 *     uuid: string,
 *     type: ?string,
 *     done_at: int,
 *     done_at_off: int,
 *     odo: ?int,
 *     second_odo: ?int,
 *     title: string,
 *     vendor: ?string,
 *     cost: ?int,
 *     vat_rate: ?int,
 *     notes: ?string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 *     closes: ?string,
 * }
 *
 * @psalm-type NextFleetExpense = array{
 *     uuid: string,
 *     spent_at: int,
 *     spent_at_off: int,
 *     category: ?string,
 *     amount: int,
 *     vat_rate: ?int,
 *     notes: ?string,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * The consumption one fill-up closes, full tank to full tank.
 *
 * @psalm-type NextFleetSegment = array{energy: string, closes: string, filled_at: int, amount: int, distance: int, per: string, value: float}
 *
 * One timeline row: the Entry under the key its `type` names, and what that kind carries beside
 * it (docs/architecture.md#the-timeline).
 *
 * @psalm-type NextFleetTimelineRow = array{
 *     type: string,
 *     occurred_at: int,
 *     occurred_at_off: int,
 *     odometer?: NextFleetReading,
 *     trip?: NextFleetTrip,
 *     energy?: NextFleetEnergyEntry,
 *     maintenance?: NextFleetMaintenanceEntry,
 *     expense?: NextFleetExpense,
 *     reading?: ?NextFleetReading,
 *     readings?: list<NextFleetReading>,
 *     missing?: list<string>,
 *     flags?: list<string>,
 *     consumption?: ?NextFleetSegment,
 *     closes?: ?string,
 *     may: list<string>,
 *     entered_by: ?string,
 * }
 *
 * @psalm-type NextFleetTimelinePage = array{rows: list<NextFleetTimelineRow>, next: ?string}
 *
 * @psalm-type NextFleetGap = array{trip: string, distance: int, from_at: int, from_at_off: int, to_at: int, to_at_off: int}
 *
 * @psalm-type NextFleetTripPrefill = array{places: list<string>, purposes: list<string>, partners: list<string>, category: ?string, last: ?array{end_odo: ?int, ended_at: int, ended_at_off: int}}
 *
 * @psalm-type NextFleetEnergyPrefill = array{vat_rate: ?int, stations: list<array{station: string, energy: string, unit_price: ?int}>}
 *
 * @psalm-type NextFleetMaintenancePrefill = array{vat_rate: ?int, vendors: list<string>}
 *
 * @psalm-type NextFleetExpensePrefill = array{vat_rate: ?int}
 *
 * A period's consumption per energy, and the wall's side of a charge over the last year.
 *
 * @psalm-type NextFleetPeriod = array{energy: string, amount: int, distance: int, per: string, value: float}
 * @psalm-type NextFleetRolling = array{amount: int, distance: int, per: string, value: float}
 *
 * What a period cost, and the expenses by category.
 *
 * @psalm-type NextFleetItemised = array{category: ?string, total: int}
 * @psalm-type NextFleetCost = array{currency: ?string, net: bool, per: ?string, distance: ?int, total: ?int, energy: ?int, maintenance: ?int, expenses: ?list<NextFleetItemised>, value: ?float, energy_value: ?float, tco: ?float, incomplete: bool, unstated: bool}
 *
 * The CO₂ a period gave off, always an estimate, and the grid factor it used for charging. `source`
 * and `year` cite the fuel factors; `year` is null where the country does not state it.
 *
 * @psalm-type NextFleetGrid = array{grams: int, year: ?int, source: ?string}
 * @psalm-type NextFleetCo2 = array{grams: ?int, unstated: bool, source: string, year: ?int, grid: ?NextFleetGrid}
 *
 * @psalm-type NextFleetFigures = array{consumption: list<NextFleetPeriod>, wall_side: ?NextFleetRolling, cost: NextFleetCost, hours: ?int}
 *
 * @psalm-type NextFleetYear = array{year: NextFleetFigures, co2: ?NextFleetCo2, months: list<array{month: int, from: int, to: int, cost: NextFleetCost}>}
 *
 * A Reminder as a write answers it. Days are plain calendar days, YYYY-MM-DD.
 *
 * @psalm-type NextFleetReminder = array{
 *     uuid: string,
 *     template_key: ?string,
 *     title: ?string,
 *     mode: string,
 *     due_date: ?string,
 *     due_odo: ?int,
 *     lead_odo: ?int,
 *     warn_month_before: bool,
 *     warn_month_start: bool,
 *     warn_due_date: bool,
 *     recur_months: ?int,
 *     recur_odo: ?int,
 *     state: string,
 *     snoozed_until: ?string,
 *     occurrence: int,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 * }
 *
 * A Reminder as a list answers it: its `state` evaluated now, and the day the pace reaches its km.
 *
 * @psalm-type NextFleetListedReminder = array{
 *     uuid: string,
 *     template_key: ?string,
 *     title: ?string,
 *     mode: string,
 *     due_date: ?string,
 *     due_odo: ?int,
 *     lead_odo: ?int,
 *     warn_month_before: bool,
 *     warn_month_start: bool,
 *     warn_due_date: bool,
 *     recur_months: ?int,
 *     recur_odo: ?int,
 *     state: string,
 *     snoozed_until: ?string,
 *     occurrence: int,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 *     estimate: ?string,
 * }
 *
 * The same across the fleet, each naming its `vehicle` by uuid.
 *
 * @psalm-type NextFleetFleetReminder = array{
 *     vehicle: string,
 *     uuid: string,
 *     template_key: ?string,
 *     title: ?string,
 *     mode: string,
 *     due_date: ?string,
 *     due_odo: ?int,
 *     lead_odo: ?int,
 *     warn_month_before: bool,
 *     warn_month_start: bool,
 *     warn_due_date: bool,
 *     recur_months: ?int,
 *     recur_odo: ?int,
 *     state: string,
 *     snoozed_until: ?string,
 *     occurrence: int,
 *     created_at: int,
 *     updated_at: int,
 *     deleted_at: ?int,
 *     created_by: string,
 *     estimate: ?string,
 * }
 *
 * @psalm-type NextFleetReminderTemplate = array{key: string, mode: string, recur_months: ?int, recur_odo: ?int, lead_odo: ?int, first_due_months: ?int}
 *
 * @psalm-type NextFleetRecipient = array{user_id: string, display_name: string}
 *
 * @psalm-type NextFleetGrant = array{uuid: string, grantee: string, grantee_type: string, display_name: string, role: string}
 *
 * What the caller holds on a vehicle: their own grant's role, and each group's that reaches them.
 * `holders` is who reads the vehicle, by display name and role (`owner` for the owner), empty for
 * the owner and for a caller who holds nothing.
 *
 * @psalm-type NextFleetHeld = array{role: ?string, groups: list<array{grantee: string, display_name: string, role: string}>, holders: list<array{display_name: string, grantee_type: string, role: string}>}
 *
 * A paper, its file by id and its name as the caller sees it; `linked_*` name the Entry it backs.
 *
 * @psalm-type NextFleetDocument = array{uuid: string, kind: string, file_id: int, name: ?string, mime: ?string, linked_type: ?string, linked_uuid: ?string, may: list<string>}
 *
 * A booking, the booker by name and its trip by uuid; `may` is what the caller may do to it.
 *
 * @psalm-type NextFleetBooking = array{
 *     uuid: string,
 *     user_id: string,
 *     user_name: string,
 *     starts_at: int,
 *     starts_at_off: int,
 *     ends_at: int,
 *     ends_at_off: int,
 *     purpose: ?string,
 *     state: string,
 *     out_at: ?int,
 *     out_at_off: ?int,
 *     out_odo: ?int,
 *     out_level: ?int,
 *     out_notes: ?string,
 *     in_at: ?int,
 *     in_at_off: ?int,
 *     in_odo: ?int,
 *     in_level: ?int,
 *     in_notes: ?string,
 *     trip_uuid: ?string,
 *     trip_voided: bool,
 *     trip_draft: ?array{started_at: ?int, started_at_off: ?int, ended_at: ?int, ended_at_off: ?int, start_odo: ?int, end_odo: ?int, purpose: ?string},
 *     flags: list<string>,
 *     created_at: int,
 *     updated_at: int,
 *     created_by: string,
 *     may: list<string>,
 * }
 *
 * One row a sync hands over: its uuid, vehicle and dating, and the row as its own route answers it
 * - null when `deleted_at` says it is a tombstone.
 *
 * @psalm-type NextFleetSyncedReading = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetReading}
 * @psalm-type NextFleetSyncedTrip = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetTrip}
 * @psalm-type NextFleetSyncedEnergy = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetEnergy}
 * @psalm-type NextFleetSyncedMaintenance = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetMaintenance}
 * @psalm-type NextFleetSyncedExpense = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetExpense}
 * @psalm-type NextFleetSyncedReminder = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetListedReminder}
 * @psalm-type NextFleetSyncedDocument = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetDocument}
 * @psalm-type NextFleetSyncedBooking = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetBooking}
 * @psalm-type NextFleetSyncedGrant = array{vehicle_uuid: string, uuid: string, updated_at: int, deleted_at: ?int, row: ?NextFleetGrant}
 *
 * One page of a sync (docs/api.md#sync). `grants` holds the caller's own vehicles' only.
 *
 * @psalm-type NextFleetSync = array{
 *     vehicles: list<NextFleetVehicle>,
 *     changes: array{
 *         readings: list<NextFleetSyncedReading>,
 *         trips: list<NextFleetSyncedTrip>,
 *         energy: list<NextFleetSyncedEnergy>,
 *         maintenance: list<NextFleetSyncedMaintenance>,
 *         expenses: list<NextFleetSyncedExpense>,
 *         reminders: list<NextFleetSyncedReminder>,
 *         documents: list<NextFleetSyncedDocument>,
 *         bookings: list<NextFleetSyncedBooking>,
 *         grants: list<NextFleetSyncedGrant>,
 *     },
 *     unreachable: list<string>,
 *     cursor: string,
 *     more: bool,
 *     reset: bool,
 * }
 *
 * A file in the receipt inbox, and the inbox: its folder, a screenful of files, how many wait.
 *
 * @psalm-type NextFleetWaiting = array{file_id: int, name: string, mime: string, mtime: int, size: int}
 * @psalm-type NextFleetInbox = array{folder: ?array{file_id: int, path: string}, files: list<NextFleetWaiting>, count: int}
 *
 * A country's average grid factor, and the caller's settings with the jurisdictions to pick from.
 *
 * @psalm-type NextFleetGridAverage = array{grams: int, year: int, source: string}
 * @psalm-type NextFleetPreferences = array{preferences: array{jurisdiction: string, dismissed_hints: list<string>, dismissed_logbook_hints: list<string>, reclaim_vat: bool, kpi_period: string, grid_factor: ?int, inbox_folder: ?int}, jurisdictions: list<array{key: string, name: string, logbook_export: bool, mileage_claim: bool, logbook_rules: bool, grid_factor: ?NextFleetGridAverage}>}
 *
 * What importing a file would do (docs/architecture.md#import). A proposal is one source row as the
 * entry it would become: `fields` by the entry routes' names, in their units, empty when the row is
 * unreadable; `outcome` null to create, `duplicate` or `unreadable`, with the `reason` word and the
 * `column` to blame. `questions` are what the user must still answer before the import, each with
 * what may answer it; `categories` every cost category text the file holds, and
 * `category_defaults` what those the format gives a meaning become unless answered. `proposals` are the
 * first 50 rows, `counts` cover every row, `creates` what an import with these answers would write.
 *
 * @psalm-type NextFleetImportProposal = array{row: int, kind: string, fields: array<string, int|string|bool|null>|\stdClass, outcome: ?string, reason: ?string, column: ?string}
 * @psalm-type NextFleetImportPreview = array{
 *     importer: string,
 *     record_type: string,
 *     columns: array{placed: list<array{header: string, field: string}>, ignored: list<string>},
 *     questions: list<array{name: string, choices: list<string>}>,
 *     categories: list<string>,
 *     category_defaults: list<array{text: string, meaning: string}>,
 *     counts: array{new: int, duplicate: int, unreadable: int, creates: int},
 *     reasons: list<array{reason: string, count: int}>,
 *     proposals: list<NextFleetImportProposal>,
 *     etag: string,
 * }
 *
 * What an import wrote: the preview's `counts` under the same answers, and each entry it created
 * by its timeline `type` and `uuid`, in file order. That list is the import's identity: undoing it
 * names these entries.
 *
 * @psalm-type NextFleetImportResult = array{
 *     counts: array{new: int, duplicate: int, unreadable: int, creates: int},
 *     created: list<array{type: string, uuid: string}>,
 * }
 *
 * What an undo took back: how many of the entries an import created, which is all of them.
 *
 * @psalm-type NextFleetImportUndone = array{undone: int}
 *
 * A refusal the client can act on: a 400 says what was wrong with the request. `reason` is a word
 * for the refusals a client may want to put into its own words: `currency_in_use`, a vehicle's
 * currency changed after an amount was recorded in it; `end_below_start`, a trip's end counter
 * below its start; `ends_in_future`, a trip arriving more than a day ahead; `not_in_question`, a
 * Reading the chain no longer questions; `no_energy`, fill-ups imported into a vehicle that takes
 * no energy yet.
 *
 * @psalm-type NextFleetRefusal = array{message: string, reason?: string}
 *
 * A 422: a file an import will not read. `reason` is a word (`too_large`, `binary`, `encoding`, …),
 * `row` where reading stopped, null when no row is to blame.
 *
 * @psalm-type NextFleetImportRefusal = array{message: string, reason: string, row: ?int}
 *
 * A 412 from us, told apart from Nextcloud's own failed CSRF check by `conflict`.
 *
 * @psalm-type NextFleetConflict = array{message: string, conflict: true}
 *
 * A 409: the booking in the way, as the bookings list shows it.
 *
 * @psalm-type NextFleetBookingConflict = array{message: string, booking: array{uuid: string, user_id: string, user_name: string, starts_at: int, starts_at_off: int, ends_at: int, ends_at_off: int, state: string}}
 */
class ResponseDefinitions {
}
