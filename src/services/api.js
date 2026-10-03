/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl, generateUrl, imagePath } from '@nextcloud/router'

/**
 * A vehicle as it travels: the JSON keys are the column names, so what a client reads is what it
 * may send back (docs/architecture.md#data-model).
 *
 * @typedef {object} Vehicle
 * @property {string} uuid - identity; the plate is only a label
 * @property {number} updated_at - the token the next write is checked against
 * @property {string} [plate] - as registered, free to change
 * @property {string} [manufacturer] - who built it
 * @property {string} [model] - what they call it
 * @property {string} [engine] - the drivetrain classification (CONTEXT.md)
 * @property {number|null} [odo_value] - the newest Reading, cached; null until one exists
 * @property {string} [odo_unit] - `km` or `h`, the vehicle's own
 * @property {string|null} [second_unit] - `h` when engine hours are counted beside the kilometres
 * @property {number|null} [second_value] - the newest hour Reading, cached; null until one exists
 * @property {string} [lifecycle] - `active`, `laid_up` or `disposed` (CONTEXT.md)
 * @property {string} [jurisdiction] - the country whose rules it is kept under (CONTEXT.md)
 * @property {boolean|null} [logbook_mode] - under its jurisdiction's logbook rules; null is off
 * @property {string|null} [vin] - the vehicle's identity as its maker stamped it
 * @property {string|null} [first_reg] - the day it was first registered, `YYYY-MM-DD`
 * @property {string[]|null} [energy_types] - the energy it actually accepts (CONTEXT.md)
 * @property {number|null} [tank_ml] - the tank, in millilitres
 * @property {number|null} [battery_wh] - the battery, in watt-hours
 * @property {string|null} [currency] - the code its costs are in; null leaves them unsummed
 * @property {string} [reminder_mail] - how often the reminder digest covers it: `off`, `daily`,
 *   `weekly` or `monthly`
 * @property {string[]} [may] - what the session may do on it: `view`, `log`, `edit`, `delete`,
 *   `own` (CONTEXT.md, Vehicle Access), and `book` - `log` on a car in service; the server's
 *   answer, never a field a client sends
 * @property {string|null} [owned_by] - the owner's display name when the session reached it through
 *   a grant; null on the session's own
 * @property {{user_id: string, user_name: string, ends_at: number, ends_at_off: number}|null} [out_with]
 *   - who has the car checked out, and the end of their booking; null while it is not out
 * @property {{uuid: string, starts_at: number, starts_at_off: number, ends_at: number, ends_at_off: number}|null} [my_next_booking]
 *   - the session's own next booking of it within seven days, not yet taken; null when none
 */

/**
 * One reading of a vehicle's counter (docs/architecture.md#odometer-rules). `origin` and `flagged`
 * are the server's answer, never a field a client fills in.
 *
 * @typedef {object} Reading
 * @property {string} uuid - identity
 * @property {number} read_at - the instant it was read, seconds
 * @property {number} read_at_off - the UTC offset it was read at, minutes
 * @property {number} value - in the unit of its counter: `odo_unit` on `main`, `second_unit` on `second`
 * @property {string} origin - `observed` when somebody read it, `derived` when it was computed
 * @property {boolean} flagged - it contradicts the reading before it on the same counter
 * @property {'main'|'second'} counter - which of the vehicle's chains it is on
 */

/**
 * One journey (CONTEXT.md). The counter it ended on and the kilometres it covered are two facts
 * and never computed into one another, so exactly one of them is filled in
 * (docs/architecture.md#odometer-rules).
 *
 * @typedef {object} Trip
 * @property {string} uuid - identity
 * @property {number} started_at - when it set off, seconds
 * @property {number} started_at_off - the UTC offset it set off at, minutes
 * @property {number} ended_at - when it arrived, seconds
 * @property {number} ended_at_off - the UTC offset it arrived at, minutes
 * @property {number|null} start_odo - the counter the driver claims it set off on
 * @property {number|null} end_odo - the counter it ended on
 * @property {number|null} distance - the kilometres it covered, when no counter was read
 * @property {string|null} from_label - where it set off
 * @property {string|null} to_label - where it arrived
 * @property {string|null} purpose - why it was driven
 * @property {string|null} partner - the business contact it visited
 * @property {string} category - `business`, `private` or `commute` (CONTEXT.md)
 * @property {boolean} reconciled - the app wrote it to close a Gap; never a field a client fills in
 */

/**
 * One fill-up or charging session (docs/architecture.md#data-model): `amount` in millilitres or
 * watt-hours by `energy`, money in gross cents.
 *
 * @typedef {object} Energy
 * @property {string} uuid - identity
 * @property {string} energy - petrol, diesel, lpg, cng or electric
 * @property {number} amount - millilitres or watt-hours
 * @property {number|null} total - what it cost, cents
 * @property {boolean} full_tank - filled to the brim
 * @property {boolean} missed_previous - a fill-up before it was not recorded
 * @property {string|null} station - where it was bought
 */

/**
 * One Maintenance Record (CONTEXT.md).
 *
 * @typedef {object} Maintenance
 * @property {string} uuid - identity
 * @property {string} title - what was done
 * @property {string|null} type - one of MAINTENANCE_TYPES (src/utils/format.js)
 * @property {string|null} vendor - who did it
 * @property {number|null} cost - gross cents
 */

/**
 * One Expense (CONTEXT.md).
 *
 * @typedef {object} Expense
 * @property {string} uuid - identity
 * @property {string|null} category - one of EXPENSE_CATEGORIES (src/utils/format.js)
 * @property {number} amount - gross cents
 */

/**
 * One thing that happened to a vehicle, whatever table it was written in
 * (docs/architecture.md#the-timeline). The Entry itself sits under its own kind's key, and an Entry
 * that wrote Readings carries them — the Entry and the counter it moved are one row on screen
 * (docs/architecture.md#odometer-rules, rule 5).
 *
 * @typedef {object} Entry
 * @property {'trip'|'odometer'|'energy'|'maintenance'|'expense'} type - which key the Entry is under
 * @property {number} occurred_at - when it happened, seconds; a trip is placed where it set off
 * @property {number} occurred_at_off - the UTC offset it happened at, minutes
 * @property {Trip} [trip] - the journey, when that is what this row is
 * @property {Reading} [odometer] - the counter reading, when that is what this row is
 * @property {Energy} [energy] - the fill-up, when that is what this row is
 * @property {Maintenance} [maintenance] - the Maintenance Record, when that is what this row is
 * @property {Expense} [expense] - the expense, when that is what this row is
 * @property {Reading|null} [reading] - the Reading a trip left on the counter
 * @property {Reading[]} [readings] - the Readings a fill-up or maintenance record left, one per
 *   counter it was given
 * @property {string[]} [flags] - what a fill-up is flagged for: `foreign_energy`, `no_price`,
 *   `overfilled`
 * @property {string[]} [missing] - what a trip's jurisdiction requires and it leaves unstated,
 *   measured on every vehicle
 * @property {Consumption|null} [consumption] - the segment a fill-up closes, or null when it closes
 *   none
 * @property {string|null} [closes] - the uuid of the reminder a maintenance record closed, sent back
 *   as `closes` when the record is saved (docs/architecture.md#reminder-engine)
 * @property {string[]} [may] - what the session may do to this Entry: `edit`, `delete`, or neither
 * @property {string|null} [entered_by] - who entered it, by display name, on a vehicle anybody else
 *   was ever given access to; null on any other
 */

/**
 * A full-to-full segment (docs/architecture.md#numbers-consumption-cost-emissions), measured on the
 * main counter.
 *
 * @typedef {object} Consumption
 * @property {string} energy - the energy both ends were filled with
 * @property {string} closes - the uuid of the fill-up that closes it
 * @property {number} filled_at - when that fill-up was, seconds
 * @property {number} amount - millilitres or watt-hours that went in after the opening fill-up
 * @property {number} distance - how far the main counter moved, in its unit
 * @property {'km'|'h'} per - the main counter's unit
 * @property {number} value - litres or kWh per 100 km, or per hour
 */

/**
 * One energy's consumption over the segments that close in a period: their amounts over their
 * distances (lib/Service/ConsumptionService.php).
 *
 * @typedef {object} PeriodConsumption
 * @property {string} energy - the energy
 * @property {number} amount - millilitres or watt-hours
 * @property {number} distance - in the main counter's unit
 * @property {'km'|'h'} per - the main counter's unit
 * @property {number} value - litres or kWh per 100 km, or per hour
 */

/**
 * What a vehicle cost in a period (lib/Service/CostService.php). Money is cents; `value`,
 * `energy_value` and `tco` are cents per 100 km, or per hour. Every sum is null on a vehicle without
 * a currency, and in a period with no fill-up, record or expense.
 *
 * @typedef {object} Cost
 * @property {string|null} currency - null when the vehicle has none
 * @property {boolean} net - whether rows count net of their VAT rate
 * @property {'km'|'h'} per - the main counter's unit
 * @property {number|null} distance - how far the main counter moved in the period
 * @property {number|null} total - every cost in the period
 * @property {number|null} energy - the fill-ups' share of it
 * @property {number|null} maintenance - the maintenance records' share of it
 * @property {{category: string|null, total: number}[]|null} expenses - the expenses' share, per
 *   category in the sheet's order, then any word it does not offer, then the uncategorised
 * @property {number|null} value - total per 100 km or per hour; null without a distance
 * @property {number|null} energy_value - energy per 100 km or per hour
 * @property {number|null} tco - value plus depreciation; null unless purchase and residual are set
 * @property {boolean} incomplete - a fill-up in the period has no price
 * @property {boolean} unstated - under `net`, a row without a stated rate counted gross
 */

/**
 * The vehicle header's figures for one period.
 *
 * @typedef {object} Kpis
 * @property {PeriodConsumption[]} consumption - one per energy that closed a segment in it
 * @property {{amount: number, distance: number, per: 'km'|'h', value: number}|null} wall_side - the
 *   rolling electric figure, counted at the charger
 * @property {Cost} cost - what it cost
 * @property {number|null} hours - how far the second counter moved; null on a vehicle without one
 */

/**
 * One vehicle's year for the Costs screen: the header's figures for the whole year, and each month's
 * cost, cut at the reader's midnight.
 *
 * @typedef {object} CostYear
 * @property {Kpis} year - the year's figures
 * @property {Co2|null} co2 - the year's CO₂; null where the vehicle's country states no factors
 * @property {{month: number, from: number, to: number, cost: Cost}[]} months - January first
 */

/**
 * A period's CO₂ estimate (lib/Service/EmissionService.php).
 *
 * @typedef {object} Co2
 * @property {number|null} grams - null when nothing was burnt
 * @property {boolean} unstated - a fill-up had no factor and is left out
 * @property {string} source - where the fuel factors are written down
 * @property {number|null} year - the year that source came out, null where it is not stated
 * @property {{grams: number, year: number|null, source: string|null}|null} grid - the factor the
 *   charges were read at, null without a charge; the reader's own has no year and no source
 */

/**
 * A paper on a vehicle: a file in somebody's Files, referenced by id (docs/architecture.md#documents).
 *
 * @typedef {object} Document
 * @property {string} uuid - identity
 * @property {'registration'|'insurance'|'manual'|'receipt'|'photo'} kind - what it is
 * @property {number} file_id - the file in Files
 * @property {string|null} name - the file's name now; null once it is deleted
 * @property {string|null} mime - its type; null once it is deleted
 * @property {'energy'|'maintenance'|'expense'|'booking'|null} linked_type - the kind of entry it belongs to, or a booking
 * @property {string|null} linked_uuid - that entry or booking; null when it belongs to the vehicle itself
 * @property {string[]} may - `detach` where the session may take it off, by the rule of the row it hangs on
 */

/**
 * Something coming due on a vehicle (docs/architecture.md#reminder-engine). Not an Entry: nothing
 * has happened yet.
 *
 * @typedef {object} Reminder
 * @property {string} uuid - identity
 * @property {number} updated_at - the token the next write is checked against
 * @property {string|null} template_key - the template it was made from; its title translates
 * @property {string|null} title - the user's own title, when there is no template
 * @property {'date'|'odo'|'either'} mode - due by date, by the main counter, or by whichever is first
 * @property {string|null} due_date - a plain day, `YYYY-MM-DD`
 * @property {number|null} due_odo - on the main counter, in its unit
 * @property {number|null} lead_odo - how far before `due_odo` it warns
 * @property {boolean} warn_month_before - warns a month before the due date
 * @property {boolean} warn_month_start - warns at the start of the month it is due
 * @property {boolean} warn_due_date - warns on the due date
 * @property {number|null} recur_months - the recurrence by date
 * @property {number|null} recur_odo - the recurrence by counter
 * @property {'planned'|'warned'|'due'|'overdue'|'done'|'snoozed'|'dismissed'} state - where it
 *   stands today; the list evaluates it, a write answers what is stored
 * @property {string|null} snoozed_until - the day a snooze ends
 * @property {string|null} [estimate] - the day the counter is expected to reach `due_odo`, or null
 *   without enough data; only the list carries it
 */

/**
 * What a Reminder Template (CONTEXT.md) fills in.
 *
 * @typedef {object} ReminderTemplate
 * @property {string} key - what the reminder's `template_key` becomes
 * @property {'date'|'odo'|'either'} mode - how it comes due
 * @property {number|null} recur_months - its recurrence by date
 * @property {number|null} recur_odo - its recurrence by counter
 * @property {number|null} lead_odo - how far before the due km it warns
 * @property {number|null} first_due_months - on the inspection only: how long after the first
 *   registration the first one falls
 */

/**
 * One account a vehicle's reminders go to.
 *
 * @typedef {object} Recipient
 * @property {string} user_id - the account
 * @property {string} display_name - its name, or the account where it has none any more
 */

/**
 * @typedef {object} TimelinePage
 * @property {Entry[]} rows - at most fifty, newest first
 * @property {string|null} next - the cursor the next page starts at, null on the last page
 */

/**
 * Kilometres before a trip that no trip accounts for (CONTEXT.md), bracketed by the Reading the last
 * trip left and the trip's start.
 *
 * @typedef {object} Gap
 * @property {string} trip - the uuid of the trip whose `start_odo` opened it
 * @property {number} distance - how far, in the vehicle's `odo_unit`
 * @property {number} from_at - when the Reading it is measured against was read, seconds
 * @property {number} from_at_off - the UTC offset that Reading was read at, minutes
 * @property {number} to_at - when the trip set off, seconds
 * @property {number} to_at_off - the UTC offset it set off at, minutes
 */

/**
 * A plan to use a vehicle for a span (CONTEXT.md, Booking). Not an Entry: it is on no timeline. The
 * span is half-open, and only `booked` and `out` hold the vehicle.
 *
 * @typedef {object} Booking
 * @property {string} uuid - identity
 * @property {number} updated_at - the token the next write is checked against
 * @property {string} user_id - the booker
 * @property {string} user_name - the booker's display name, or the account where it has none
 * @property {number} starts_at - the first second, unix
 * @property {number} starts_at_off - the UTC offset it was planned at, minutes
 * @property {number} ends_at - the second after the last
 * @property {number} ends_at_off - the UTC offset it was planned at, minutes
 * @property {string|null} purpose - what it is for
 * @property {'booked'|'out'|'returned'|'cancelled'} state - where it stands
 * @property {number|null} out_at - when the car was taken, seconds
 * @property {number|null} out_at_off - the UTC offset it was taken at, minutes
 * @property {number|null} out_odo - the main counter as it was taken
 * @property {number|null} out_level - the tank or battery as it was taken, percent
 * @property {string|null} out_notes - what the driver noted on taking it
 * @property {number|null} in_at - when it was given back, seconds
 * @property {number|null} in_at_off - the UTC offset it was given back at, minutes
 * @property {number|null} in_odo - the main counter as it was given back
 * @property {number|null} in_level - the tank or battery as it was given back, percent
 * @property {string|null} in_notes - what the driver noted on giving it back
 * @property {string|null} trip_uuid - the trip logged from it
 * @property {boolean} trip_voided - whether that trip was voided since; it stays tied
 * @property {TripDraft|null} trip_draft - the trip the handover describes, while a returned booking has none
 * @property {('odo_below'|'late')[]} flags - what the handover is in question for, computed on read
 * @property {string[]} may - what the session may do to it: `edit`, `cancel`, `check_out`, `check_in`,
 *   `log_trip`, `attach` (a handover photo, once handed over), and `open_trip` where the trip it
 *   became is the session's to open, as its timeline row says
 */

/**
 * The booking a refused span or check-out collides with, as the 409 names it: `out` when the car is
 * still with its booker.
 *
 * @typedef {Pick<Booking, 'uuid'|'user_id'|'user_name'|'starts_at'|'starts_at_off'|'ends_at'|'ends_at_off'|'state'>} BookingHeld
 */

/**
 * What a check-out or check-in states about the car. The moment is the server's; the offset is the
 * client's (docs/architecture.md#time).
 *
 * @typedef {object} Handover
 * @property {number} odo - the main counter, in its unit
 * @property {number} [level] - the tank or battery, percent
 * @property {string} [notes] - anything worth telling the next driver or the owner
 * @property {number} at_off - the UTC offset now, minutes
 */

/**
 * The trip a returned booking suggests, from the two handovers, while it has none. No category:
 * that is the driver's word.
 *
 * @typedef {object} TripDraft
 * @property {number} started_at - when the car was taken, seconds
 * @property {number} started_at_off - minutes
 * @property {number} ended_at - when it was given back, seconds
 * @property {number} ended_at_off - minutes
 * @property {number} start_odo - the counter as it was taken
 * @property {number} end_odo - the counter as it was given back
 * @property {string|null} purpose - the booking's
 */

/**
 * The personal settings screen's whole state: what this user chose, and what each choice may be.
 * The options travel with the values because a dropdown needs both.
 *
 * @typedef {object} Settings
 * @property {{ jurisdiction: string, dismissed_hints: string[], reclaim_vat: boolean, kpi_period: string, grid_factor: number|null, inbox_folder: number|null }} preferences -
 *   this user's own choices: the country new vehicles are kept under, the vehicles whose "complete
 *   this vehicle" hint they have answered, whether cost figures are net of VAT, the period the
 *   vehicle header shows, their electricity's grams of CO₂ per kWh (null for the country's), and
 *   the file id of their inbox folder (null for none; kept even once the folder is gone)
 * @property {{ key: string, name: string, logbook_export: boolean, mileage_claim: boolean, grid_factor: {grams: number, year: number, source: string}|null }[]} jurisdictions -
 *   the registered countries, English, whether each prints a logbook and a mileage claim, and its grid average
 */

/**
 * A file in the inbox folder that no paper references yet.
 *
 * @typedef {object} Waiting
 * @property {number} file_id - the file in Files, what attaching it names
 * @property {string} name - its name
 * @property {string} mime - an image type or `application/pdf`
 * @property {number} mtime - when it last changed, seconds
 * @property {number} size - bytes
 */

/**
 * @typedef {object} Inbox
 * @property {{file_id: number, path: string}|null} folder - the inbox folder and its path in the
 *   person's Files; null when none is chosen or it is gone
 * @property {Waiting[]} files - the newest hundred, newest first
 * @property {number} count - all of them, the hundred or not
 */

/**
 * What an import preview and the import itself are asked with (docs/api.md). `units` is
 * `{distance: km|mi, volume: l|us_gal|uk_gal}`; `tz` is the zone a date without a time is local to.
 *
 * @typedef {object} ImportAsked
 * @property {number} file_id - the file in the person's own Files
 * @property {string} importer - `lubelogger` or `spritmonitor`
 * @property {string} record_type - one of the importer's
 * @property {{distance: string, volume: string}} units - what the file does not say
 * @property {string} tz - an IANA zone
 * @property {string} [date_order] - `dmy` or `mdy`
 * @property {string} [energy] - one of the vehicle's energy types
 * @property {Record<string, string>} [category_map] - text => `skip`, `expense.…` or `maintenance.…`
 * @property {boolean} [include_duplicates] - whether rows already there are created again; not by default
 */

/**
 * One row of the file as the import would take it. `outcome` is null for a new entry.
 *
 * @typedef {object} ImportProposal
 * @property {number} row - the spreadsheet row; the header is 1
 * @property {string} kind - `energy`, `maintenance`, `expense` or `odometer`
 * @property {Record<string, unknown>} fields - the entry's fields in canonical units; empty when unreadable
 * @property {'duplicate'|'unreadable'|null} outcome - what becomes of it
 * @property {string|null} reason - why it is not new
 * @property {string|null} column - the header blamed
 */

/**
 * @typedef {object} ImportCounts
 * @property {number} new - rows that become entries
 * @property {number} duplicate - rows already there
 * @property {number} unreadable - rows that cannot be read
 * @property {number} creates - what an import with these answers writes
 */

/**
 * @typedef {object} ImportPreview
 * @property {{placed: {header: string, field: string}[], ignored: string[]}} columns - which header became which field
 * @property {{name: string, choices: string[]}[]} questions - what is still to be answered
 * @property {string[]} categories - a costs file's distinct category texts, all of them
 * @property {{text: string, meaning: string}[]} category_defaults - what a text the format gives a meaning becomes unless answered
 * @property {ImportCounts} counts - every row by outcome
 * @property {{reason: string, count: number}[]} reasons - how many rows each reason left out
 * @property {ImportProposal[]} proposals - the first fifty rows, in file order
 * @property {string} etag - the file as it was read; the import is checked against it
 */

/**
 * @typedef {object} ImportResult
 * @property {ImportCounts} counts - as the preview counted them
 * @property {{type: string, uuid: string}[]} created - what was created, which is what an undo names
 */

/**
 * The row moved on since it was read, so the write was refused instead of overwriting it
 * (docs/architecture.md#concurrency). Its own class because the sheet answers it differently
 * from every other failure: the values are fine, the version is not.
 */
export class ConflictError extends Error {}

/**
 * Something the request named is not there for this user. Its own class because the server's
 * message names only the vehicle, and attaching a document means something else by it
 * (src/components/VehicleDocuments.vue).
 */
export class NotFoundError extends Error {}

/**
 * Another live booking holds part of the span, or the booking a trip is logged from is not back or
 * was logged already. It carries that booking, so the sheet says whose it is and when without a
 * second read.
 */
export class BookingConflictError extends Error {

	/**
	 * @param {string} message - the server's words
	 * @param {BookingHeld} booking - the booking in the way
	 */
	constructor(message, booking) {
		super(message)
		this.booking = booking
	}

}

/**
 * What a request stood on moved since it was read: an import's file since its preview, or the
 * entries an undo names since their import. Unlike ConflictError there is no token to read anew;
 * the screen previews again, or lets the undo go.
 */
export class ChangedError extends Error {}

/**
 * Somebody's client is writing the file right now; a moment later it serves
 * (DocumentController::download()).
 */
export class LockedError extends Error {}

/**
 * A file an import will not read at all (docs/security.md). It carries the server's reason word
 * and the row reading stopped at, which the screen puts into words (src/utils/imports.js).
 */
export class ImportRefusedError extends Error {

	/**
	 * @param {string} message - the server's words, English, for a log
	 * @param {string} reason - `too_large`, `binary`, `encoding`, …
	 * @param {number|null} row - where reading stopped, null when no row is to blame
	 */
	constructor(message, reason, row) {
		super(message)
		this.reason = reason
		this.row = row
	}

}

/**
 * Every vehicle the session may see - their own and the ones granted to them
 * (docs/adr/0001-own-access-table.md).
 *
 * @return {Promise<Vehicle[]>} the fleet, as the server ordered it
 */
export async function listVehicles() {
	return request('GET', '/api/vehicles')
}

/**
 * One vehicle as the server now holds it. Read after a Reading was written: `odo_value` is a cache
 * the server recomputes and no client can count for itself (docs/architecture.md#odometer-rules).
 *
 * @param {string} uuid - the vehicle's identity
 * @return {Promise<Vehicle>} the vehicle, with its current token
 */
export async function getVehicle(uuid) {
	return request('GET', `/api/vehicles/${uuid}`)
}

/**
 * Add a vehicle. What the sheet leaves out the server decides, so the answer is what the client
 * keeps rather than what it sent.
 *
 * @param {Partial<Vehicle>} fields - the four the create sheet asks for (docs/ui.md)
 * @return {Promise<Vehicle>} the vehicle as the server made it, identity and token included
 */
export async function createVehicle(fields) {
	return request('POST', '/api/vehicles', fields)
}

/**
 * Write a vehicle back, checked against the `updated_at` it was read with.
 *
 * @param {Vehicle} vehicle - the vehicle as the sheet has it, `uuid` and `updated_at` included
 * @return {Promise<Vehicle>} the vehicle as the server now holds it, with its next token
 * @throws {ConflictError} when another writer got there first
 */
export async function updateVehicle(vehicle) {
	return request('PUT', `/api/vehicles/${vehicle.uuid}`, vehicle)
}

/**
 * Delete a vehicle. Soft, so it is undone rather than confirmed (docs/ui.md): the answer is the
 * vehicle as the delete left it, and the token on it is the one `restoreVehicle` is checked
 * against — no other one is accepted.
 *
 * @param {Vehicle} vehicle - the vehicle as it was read, `uuid` and `updated_at` included
 * @return {Promise<Vehicle>} the vehicle as the delete left it
 * @throws {ConflictError} when it moved on since it was read
 */
export async function deleteVehicle(vehicle) {
	// A DELETE has no body, so the token travels in the query string; the controller reads both
	// out of the request parameters (docs/architecture.md#concurrency).
	return request('DELETE', `/api/vehicles/${vehicle.uuid}?updated_at=${vehicle.updated_at}`)
}

/**
 * Undo a delete. It is checked against the token the delete answered with, so the toast hands back
 * exactly the vehicle it was given and nothing newer. The answer carries a new token; the next edit
 * needs it.
 *
 * @param {Vehicle} vehicle - the vehicle as the delete answered with it
 * @return {Promise<Vehicle>} the vehicle, back in the fleet, under its new token
 * @throws {ConflictError} when it moved on since, or was never deleted
 */
export async function restoreVehicle(vehicle) {
	return request('POST', `/api/vehicles/${vehicle.uuid}/restore`, { updated_at: vehicle.updated_at })
}

/**
 * Record one reading of a vehicle's counter. A new Reading carries no token: nothing was read that
 * it could lose a race against (docs/architecture.md#concurrency). Its edits do (updateEntry()).
 *
 * @param {string} uuid - the vehicle the counter belongs to
 * @param {object} entry - `value` or `distance`, never both, plus `read_at_off`, and `counter`
 *   (`main` by default, `second` for engine hours)
 * @return {Promise<Reading>} the reading as the server judged it, `origin` and `flagged` included
 */
export async function recordReading(uuid, entry) {
	return request('POST', `/api/vehicles/${uuid}/readings`, entry)
}

/**
 * Record one trip. Like a Reading it hangs off its vehicle and carries no token — a trip is
 * written, and under Logbook Mode revised through the audit trail rather than overwritten
 * (docs/architecture.md#concurrency).
 *
 * @param {string} uuid - the vehicle that drove it
 * @param {object} trip - what the sheet holds: the two instants with their offsets, the category,
 *   an end counter or a distance, whatever of the route the driver typed, and `booking_uuid` when
 *   it is a returned booking's trip
 * @return {Promise<Trip>} the trip as the server wrote it
 * @throws {BookingConflictError} when that booking is not back yet, or was logged already
 */
export async function recordTrip(uuid, trip) {
	return request('POST', `/api/vehicles/${uuid}/trips`, trip)
}

/**
 * What the entry sheet completes a trip's route, purpose and partner from (docs/ui.md).
 *
 * @param {string} uuid - the vehicle the trip is for
 * @return {Promise<TripPrefill>} the words of this vehicle's own trips
 */
export async function tripPrefill(uuid) {
	return request('GET', `/api/vehicles/${uuid}/trips/prefill`)
}

/**
 * @typedef {object} TripPrefill
 * @property {string[]} places - starting points and destinations in one list, each once, latest first
 * @property {string[]} purposes - as places
 * @property {string[]} partners - as places
 */

/**
 * Record one fill-up or charging session, and a Reading per counter it carries
 * (docs/architecture.md#odometer-rules). Written only, like a trip, so it carries no token.
 *
 * @param {string} uuid - the vehicle that took it
 * @param {object} fill - what the sheet holds: the moment with its offset, the energy and amount,
 *   and whatever of price, VAT, counters and station the driver gave
 * @return {Promise<object>} the fill-up as the server wrote it, its `flags` included
 */
export async function recordEnergy(uuid, fill) {
	return request('POST', `/api/vehicles/${uuid}/energy`, fill)
}

/**
 * What the entry sheet prefills a fill-up with (docs/ui.md).
 *
 * @param {string} uuid - the vehicle the fill-up is for
 * @param {number} at - the moment the sheet is on, seconds
 * @param {number} off - the UTC offset of that moment, minutes
 * @return {Promise<EnergyPrefill>} the rate on that day and this vehicle's stations
 */
export async function energyPrefill(uuid, at, off) {
	return request('GET', `/api/vehicles/${uuid}/energy/prefill?${new URLSearchParams({ at: String(at), off: String(off) })}`)
}

/**
 * @typedef {object} EnergyPrefill
 * @property {number|null} vat_rate - the jurisdiction's standard rate on the day, basis points, or
 *   null where it states none
 * @property {{ station: string, energy: string, unit_price: number|null }[]} stations - where this
 *   vehicle filled up, latest first, with the price each last charged per energy
 */

/**
 * Record one Maintenance Record, and a Reading per counter it carries, as a fill-up does.
 *
 * @param {string} uuid - the vehicle the work was done on
 * @param {object} work - what the sheet holds: the moment with its offset, the title, and whatever
 *   of type, vendor, cost, VAT, notes and counters the person gave
 * @return {Promise<object>} the record as the server wrote it
 */
export async function recordMaintenance(uuid, work) {
	return request('POST', `/api/vehicles/${uuid}/maintenance`, work)
}

/**
 * What the entry sheet prefills a Maintenance Record with (docs/ui.md).
 *
 * @param {string} uuid - the vehicle the work is for
 * @param {number} at - the moment the sheet is on, seconds
 * @param {number} off - the UTC offset of that moment, minutes
 * @return {Promise<MaintenancePrefill>} the rate on that day and this vehicle's vendors
 */
export async function maintenancePrefill(uuid, at, off) {
	return request('GET', `/api/vehicles/${uuid}/maintenance/prefill?${new URLSearchParams({ at: String(at), off: String(off) })}`)
}

/**
 * @typedef {object} MaintenancePrefill
 * @property {number|null} vat_rate - as in EnergyPrefill
 * @property {string[]} vendors - the vendors this vehicle has used, each once, latest first
 */

/**
 * Record one Expense. It carries no counter, so it writes no Reading.
 *
 * @param {string} uuid - the vehicle the money was spent on
 * @param {object} expense - what the sheet holds: the moment with its offset, the amount, and
 *   whatever of category, VAT and notes the person gave
 * @return {Promise<object>} the expense as the server wrote it
 */
export async function recordExpense(uuid, expense) {
	return request('POST', `/api/vehicles/${uuid}/expenses`, expense)
}

/**
 * What the entry sheet prefills an Expense with (docs/ui.md).
 *
 * @param {string} uuid - the vehicle the money is spent on
 * @param {number} at - the moment the sheet is on, seconds
 * @param {number} off - the UTC offset of that moment, minutes
 * @param {string|null} [category] - the category picked, since some carry no VAT
 * @return {Promise<{vat_rate: number|null}>} the rate on that day, as in EnergyPrefill
 */
export async function expensePrefill(uuid, at, off, category = null) {
	const query = new URLSearchParams({ at: String(at), off: String(off), ...(category === null ? {} : { category }) })
	return request('GET', `/api/vehicles/${uuid}/expenses/prefill?${query}`)
}

/**
 * What an edit, a delete and an undo reach below a vehicle: every kind of Entry, and a Reminder,
 * which is not an Entry but is written the same way.
 *
 * @typedef {Entry['type']|'reminder'} Written
 */

/** Where each of them is written, below its vehicle. */
const COLLECTIONS = { trip: 'trips', odometer: 'readings', energy: 'energy', maintenance: 'maintenance', expense: 'expenses', reminder: 'reminders' }

/**
 * One Entry as its timeline row, read back after an edit lost a race: its token is the one the
 * next write is checked against (docs/ui.md).
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {Entry['type']} type - which kind of Entry it is
 * @param {string} entryUuid - the Entry's own identity
 * @return {Promise<Entry>} the row as it now stands
 */
export async function readEntry(uuid, type, entryUuid) {
	return request('GET', `/api/vehicles/${uuid}/timeline/${type}/${entryUuid}`)
}

/**
 * Rewrite one Entry or Reminder, checked against the `updated_at` it was read with. The fields are
 * the whole row, as its create takes them: one left out is one the person emptied.
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {Written} type - which kind it is
 * @param {{uuid: string, updated_at: number}} entry - the Entry as it was read
 * @param {object} fields - what the sheet holds
 * @return {Promise<object>} the Entry as the server now holds it
 * @throws {ConflictError} when it moved on since it was read
 */
export async function updateEntry(uuid, type, entry, fields) {
	return request('PUT', `/api/vehicles/${uuid}/${COLLECTIONS[type]}/${entry.uuid}`, { ...fields, updated_at: entry.updated_at })
}

/**
 * Delete one Entry or Reminder - a trip under Logbook Mode is voided
 * (docs/features.md#logbook-mode). The answer carries the token the undo is checked against, as a
 * vehicle's does.
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {Written} type - which kind it is
 * @param {{uuid: string, updated_at: number}} entry - the Entry as it was read
 * @return {Promise<{uuid: string, updated_at: number}>} the Entry as the delete left it
 * @throws {ConflictError} when it moved on since it was read
 */
export async function deleteEntry(uuid, type, entry) {
	return request('DELETE', `/api/vehicles/${uuid}/${COLLECTIONS[type]}/${entry.uuid}?updated_at=${entry.updated_at}`)
}

/**
 * Undo a delete, on the token the delete answered with.
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {Written} type - which kind it is
 * @param {{uuid: string, updated_at: number}} entry - the row as the delete answered with it
 * @return {Promise<object>} the row, back
 * @throws {ConflictError} when it moved on since, or was never deleted
 */
export async function restoreEntry(uuid, type, entry) {
	return request('POST', `/api/vehicles/${uuid}/${COLLECTIONS[type]}/${entry.uuid}/restore`, { updated_at: entry.updated_at })
}

/**
 * One vehicle's reminders, live ones only, in the server's order (src/utils/reminders.js sorts
 * them). The state is where each stands today, and only this read carries the estimate, so a
 * screen reads it again after a write.
 *
 * @param {string} uuid - the vehicle they hang off
 * @return {Promise<Reminder[]>} the reminders
 */
export async function listReminders(uuid) {
	return request('GET', `/api/vehicles/${uuid}/reminders`)
}

/**
 * Every reminder on the vehicles the overview lists, as `listReminders` answers each.
 *
 * @return {Promise<Array<Reminder & {vehicle: string}>>} each naming the vehicle's uuid
 */
export async function listFleetReminders() {
	return request('GET', '/api/reminders')
}

/**
 * What the reminder sheet may start from on this vehicle, and what each fills in.
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<ReminderTemplate[]>} the service intervals, then the inspection where there is one
 */
export async function reminderTemplates(uuid) {
	return request('GET', `/api/vehicles/${uuid}/reminder-templates`)
}

/**
 * Add a reminder. It carries no token: nothing was read that it could lose a race against.
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {object} fields - what the sheet holds: a template key or a title, the mode and what it reads
 * @return {Promise<Reminder>} the reminder as the server wrote it
 */
export async function createReminder(uuid, fields) {
	return request('POST', `/api/vehicles/${uuid}/reminders`, fields)
}

/**
 * Silence a reminder until a day; its due date stays (docs/architecture.md#reminder-engine).
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {{uuid: string, updated_at: number}} reminder - as it was read
 * @param {string} until - a plain day still to come, `YYYY-MM-DD`
 * @return {Promise<Reminder>} the reminder as it now stands
 * @throws {ConflictError} when it moved on since it was read
 */
export async function snoozeReminder(uuid, reminder, until) {
	return request('POST', `/api/vehicles/${uuid}/reminders/${reminder.uuid}/snooze`, { until, updated_at: reminder.updated_at })
}

/**
 * Skip this occurrence; a recurring reminder moves on to its next one.
 *
 * @param {string} uuid - the vehicle it hangs off
 * @param {{uuid: string, updated_at: number}} reminder - as it was read
 * @return {Promise<Reminder>} the reminder as it now stands
 * @throws {ConflictError} when it moved on since it was read
 */
export async function dismissReminder(uuid, reminder) {
	return request('POST', `/api/vehicles/${uuid}/reminders/${reminder.uuid}/dismiss`, { updated_at: reminder.updated_at })
}

/**
 * Who the vehicle's reminders go to. Only someone who may edit the vehicle reads it; anyone else
 * is refused.
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<Recipient[]>} the list, in the order it was added to
 */
export async function listRecipients(uuid) {
	return request('GET', `/api/vehicles/${uuid}/recipients`)
}

/**
 * Put an account on the list. No token: adding and removing one person commute.
 *
 * @param {string} uuid - the vehicle
 * @param {string} userId - the account
 * @return {Promise<Recipient[]>} the list as it now stands
 */
export async function addRecipient(uuid, userId) {
	return request('POST', `/api/vehicles/${uuid}/recipients`, { user_id: userId })
}

/**
 * Take an account off the list, the owner's included.
 *
 * @param {string} uuid - the vehicle
 * @param {string} userId - the account
 * @return {Promise<Recipient[]>} the list as it now stands
 */
export async function removeRecipient(uuid, userId) {
	return request('DELETE', `/api/vehicles/${uuid}/recipients/${encodeURIComponent(userId)}`)
}

/**
 * What the session holds on a vehicle through grants: a grant in their own name they may leave,
 * and the groups only the owner can change. The owner holds no grant and gets neither.
 *
 * @typedef {object} Held
 * @property {string|null} role - the role of their own grant, null when they have none
 * @property {{grantee: string, display_name: string, role: string}[]} groups - each group that
 *   reaches the vehicle, with its role
 */

/**
 * @param {string} uuid - the vehicle
 * @return {Promise<Held>} what the session holds on it
 */
export async function readHeld(uuid) {
	return request('GET', `/api/vehicles/${uuid}/access`)
}

/**
 * Give back the session's own grant. A group's grant stays: only the owner changes that.
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<Held>} what the session still holds on it
 */
export async function leaveVehicle(uuid) {
	return request('DELETE', `/api/vehicles/${uuid}/access`)
}

/** Core's share types, as its autocomplete takes them. */
const USERS = '0'
const GROUPS = '1'

/**
 * Accounts matching what was typed, for the recipient picker. Core's own search rather than one of
 * ours: it already applies the instance's rules on who may find whom, which the sharing dialog
 * uses too.
 *
 * @param {string} term - what was typed
 * @return {Promise<Recipient[]>} at most ten accounts
 */
export async function searchUsers(term) {
	// Accounts only: groups, mail addresses and remote accounts are nobody the job can notify.
	return (await autocomplete(term, [USERS]))
		.map((one) => ({ user_id: one.id, display_name: one.label }))
}

/**
 * Who else may use one vehicle: a user or a group, with its role (CONTEXT.md, Vehicle Access).
 *
 * @typedef {object} Grant
 * @property {string} uuid - the grant's identity
 * @property {string} grantee - the account or the group
 * @property {'user'|'group'} grantee_type - which of the two
 * @property {string} display_name - its name, or the grantee where it has none any more
 * @property {'viewer'|'driver'|'manager'} role - what it may do
 */

/**
 * @param {string} uuid - the vehicle
 * @return {Promise<Grant[]>} its grants, in the order they were given; the owner alone reads them
 */
export async function listGrants(uuid) {
	return request('GET', `/api/vehicles/${uuid}/grants`)
}

/**
 * Give a user or a group access. Granting to one that already has it changes its role.
 *
 * @param {string} uuid - the vehicle
 * @param {{ grantee: string, grantee_type: 'user'|'group' }} whom - the user or the group
 * @param {string} role - viewer, driver or manager
 * @return {Promise<Grant[]>} the list as it now stands
 */
export async function addGrant(uuid, whom, role) {
	return request('POST', `/api/vehicles/${uuid}/grants`, { grantee: whom.grantee, grantee_type: whom.grantee_type, role })
}

/**
 * @param {string} uuid - the vehicle
 * @param {string} grant - the grant's uuid
 * @param {string} role - its new role
 * @return {Promise<Grant[]>} the list as it now stands
 */
export async function changeGrant(uuid, grant, role) {
	return request('PUT', `/api/vehicles/${uuid}/grants/${grant}`, { role })
}

/**
 * Take a grant back. Whoever no longer sees the vehicle leaves its reminder recipients with it.
 *
 * @param {string} uuid - the vehicle
 * @param {string} grant - the grant's uuid
 * @return {Promise<Grant[]>} the list as it now stands
 */
export async function revokeGrant(uuid, grant) {
	return request('DELETE', `/api/vehicles/${uuid}/grants/${grant}`)
}

/**
 * Accounts and groups matching what was typed, for the Access section, by the same core search as
 * searchUsers().
 *
 * @param {string} term - what was typed
 * @return {Promise<{ grantee: string, grantee_type: 'user'|'group', display_name: string }[]>} at
 *   most ten of each
 */
export async function searchGrantees(term) {
	return (await autocomplete(term, [USERS, GROUPS]))
		.map((one) => ({ grantee: one.id, grantee_type: one.source === 'groups' ? 'group' : 'user', display_name: one.label }))
}

/**
 * @param {string} term - what was typed
 * @param {string[]} types - the share types to search
 * @return {Promise<{ id: string, label: string, source: string }[]>} core's matches
 */
async function autocomplete(term, types) {
	const query = new URLSearchParams({ search: term, itemType: 'nextfleet', itemId: '', limit: '10' })
	for (const type of types) {
		query.append('shareTypes[]', type)
	}
	const response = await fetch(`${generateOcsUrl('core/autocomplete/get')}?${query}`, {
		headers: {
			Accept: 'application/json',
			'OCS-APIRequest': 'true',
			requesttoken: getRequestToken() ?? '',
		},
	})
	if (!response.ok) {
		throw new Error(`The server answered ${response.status}`)
	}

	const answer = await response.json()
	return answer?.ocs?.data ?? []
}

/**
 * One page of one vehicle's timeline, newest first (docs/architecture.md#the-timeline). The merge
 * is the server's, so a client asks for a page and scrolls; it never holds two tables to work out
 * which rows come first.
 *
 * @param {string} uuid - the vehicle whose timeline to read
 * @param {object} at - where in it to read
 * @param {string|null} [at.type] - the chip: one kind of Entry, or nothing for all of them
 * @param {string|null} [at.cursor] - what the last page answered with, or nothing for the newest
 * @return {Promise<TimelinePage>} the rows and the cursor the next page starts at
 */
export async function readTimeline(uuid, { type, cursor }) {
	const query = new URLSearchParams()
	for (const [name, value] of Object.entries({ type, cursor })) {
		if (value) {
			query.set(name, value)
		}
	}

	// A filter that narrows nothing is the absent parameter: an empty one is a word the timeline
	// never handed out, and it is refused rather than read as "everything".
	const asked = query.toString()

	return request('GET', `/api/vehicles/${uuid}/timeline${asked === '' ? '' : `?${asked}`}`)
}

/**
 * The vehicle header's figures for one period (lib/Service/KpiService.php). The period is the
 * browser's to name, since a year starts at the person's midnight (src/utils/period.js).
 *
 * @param {string} uuid - the vehicle whose figures to read
 * @param {object} period - what to read them for
 * @param {number} period.from - its first second, unix
 * @param {number} period.to - the second after its last
 * @param {boolean} period.net - whether the person reclaims VAT
 * @return {Promise<Kpis>} the figures
 */
export async function readKpis(uuid, { from, to, net }) {
	const query = new URLSearchParams({ from: String(from), to: String(to), net: String(net) })

	return request('GET', `/api/vehicles/${uuid}/kpis?${query}`)
}

/**
 * One vehicle's year for the Costs screen (lib/Service/KpiService.php). The reader names the zone
 * and the server cuts the months at its midnight (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @param {string} uuid - the vehicle whose year to read
 * @param {object} asked - what to read
 * @param {string} asked.year - four digits, which is what the route takes
 * @param {string} asked.tz - the reader's IANA zone
 * @param {boolean} asked.net - whether the person reclaims VAT
 * @return {Promise<CostYear>} the year
 */
export async function readYear(uuid, { year, tz, net }) {
	const query = new URLSearchParams({ tz, net: String(net) })

	return request('GET', `/api/vehicles/${uuid}/costs/${year}?${query}`)
}

/**
 * Every Gap in one vehicle's logbook, oldest first. Not paged: a month header states the whole
 * month's (docs/architecture.md#the-timeline).
 *
 * @param {string} uuid - the vehicle whose Gaps to read
 * @return {Promise<Gap[]>} the Gaps
 */
export async function readGaps(uuid) {
	return request('GET', `/api/vehicles/${uuid}/gaps`)
}

/**
 * Close one Gap as one private trip the server marks reconciled (docs/features.md#logbook-mode). The
 * Gap travels as the driver confirmed it, and the server closes nothing that no longer matches.
 *
 * @param {string} uuid - the vehicle the Gap is in
 * @param {Gap} gap - the Gap as it was read and confirmed
 * @return {Promise<Trip>} the trip that closed it
 * @throws {ConflictError} when the Gap has moved or been closed since it was read
 */
export async function closeGap(uuid, gap) {
	return request('POST', `/api/vehicles/${uuid}/gaps/${gap.trip}/close`, {
		distance: gap.distance,
		from_at: gap.from_at,
		to_at: gap.to_at,
	})
}

/**
 * Where one vehicle's Fahrtenbuch for one year is printed. An address rather than a request: the
 * page is opened by navigating to it, which is why the route asks no request token
 * (docs/architecture.md#the-fahrtenbuch-export).
 *
 * @param {string} uuid - the vehicle whose logbook to print
 * @param {string} year - the year as four digits, which is what the route takes
 * @return {string} the page's address
 */
export function logbookUrl(uuid, year) {
	return generateUrl(`/apps/nextfleet/vehicles/${uuid}/logbook/${year}`)
}

/**
 * Where one vehicle's mileage claim for one year is printed; an address for the reason logbookUrl()
 * gives (docs/architecture.md#the-mileage-claim).
 *
 * @param {string} uuid - the vehicle whose business trips to value
 * @param {string} year - the year as four digits
 * @return {string} the page's address
 */
export function mileageClaimUrl(uuid, year) {
	return generateUrl(`/apps/nextfleet/vehicles/${uuid}/mileage/${year}`)
}

/**
 * Where one table of one vehicle's year is saved as CSV; an address for the reason logbookUrl()
 * gives (docs/architecture.md#csv-export).
 *
 * @param {string} uuid - the vehicle whose rows to export
 * @param {string} year - the year as four digits
 * @param {'trips'|'energy'|'maintenance'|'expenses'} table - which file
 * @return {string} the file's address
 */
export function csvUrl(uuid, year, table) {
	return generateUrl(`/apps/nextfleet/vehicles/${uuid}/csv/${year}/${table}`)
}

/**
 * One vehicle's papers (docs/architecture.md#documents).
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<Document[]>} the list
 */
export async function listDocuments(uuid) {
	return request('GET', `/api/vehicles/${uuid}/documents`)
}

/**
 * Attach a file the person picked in Files. No token: nothing edits a document.
 *
 * @param {string} uuid - the vehicle
 * @param {{file_id: number, kind: string, linked_type?: string, linked_uuid?: string}} fields - the
 *   file, what it is, and the entry it belongs to or neither
 * @return {Promise<Document[]>} the list as it now stands
 */
export async function attachDocument(uuid, fields) {
	return request('POST', `/api/vehicles/${uuid}/documents`, fields)
}

/**
 * Take a paper off the vehicle; the file stays in Files.
 *
 * @param {string} uuid - the vehicle
 * @param {string} document - the paper's uuid
 * @return {Promise<Document[]>} the list as it now stands
 */
export async function detachDocument(uuid, document) {
	return request('DELETE', `/api/vehicles/${uuid}/documents/${document}`)
}

/**
 * Put a removed paper back. No token, for the reason detaching takes none.
 *
 * @param {string} uuid - the vehicle
 * @param {string} document - the paper's uuid
 * @return {Promise<Document[]>} the list as it now stands
 */
export async function restoreDocument(uuid, document) {
	return request('POST', `/api/vehicles/${uuid}/documents/${document}/restore`)
}

/**
 * Where one paper is saved from; an address for the reason logbookUrl() gives. Served by us, so a
 * driver reaches the owner's file without a share.
 *
 * @param {string} uuid - the vehicle
 * @param {string} document - the paper's uuid
 * @return {string} the file's address
 */
export function documentUrl(uuid, document) {
	return generateUrl(`/apps/nextfleet/vehicles/${uuid}/documents/${document}`)
}

/**
 * One paper's file, fetched from documentUrl() rather than followed: a refusal is JSON, which a
 * followed link would show in place of the app.
 *
 * @param {string} uuid - the vehicle
 * @param {string} document - the paper's uuid
 * @return {Promise<Blob>} the file
 * @throws {NotFoundError} when the paper or its file is gone
 * @throws {LockedError} when somebody is writing the file
 */
export async function fetchDocument(uuid, document) {
	const response = await fetch(documentUrl(uuid, document))
	if (!response.ok) {
		throw refusal(response, await parse(response))
	}

	return response.blob()
}

/**
 * What importing a file of the person's own would do, row by row (docs/architecture.md#import).
 * Stateless: nothing is kept between this and runImport().
 *
 * @param {string} uuid - the vehicle
 * @param {ImportAsked} asked - the file, the format and the answers
 * @return {Promise<ImportPreview>} the columns, the open questions, the counts and a sample
 * @throws {ImportRefusedError} when the file is not one an import reads
 * @throws {NotFoundError} when the file is not the person's own
 */
export async function previewImport(uuid, asked) {
	return request('POST', `/api/vehicles/${uuid}/import/preview`, asked)
}

/**
 * Create what the preview with the same answers counted, in one go.
 *
 * @param {string} uuid - the vehicle
 * @param {ImportAsked & {etag: string}} asked - the preview's request and the etag it answered
 * @return {Promise<ImportResult>} the counts, and what was created
 * @throws {ChangedError} when the file changed since the preview
 */
export async function runImport(uuid, asked) {
	return request('POST', `/api/vehicles/${uuid}/import`, asked)
}

/**
 * Take back exactly what one import created, all of it or nothing.
 *
 * @param {string} uuid - the vehicle
 * @param {{type: string, uuid: string}[]} created - the import's answer's list, as it came
 * @return {Promise<{undone: number}>} how many went
 * @throws {ChangedError} when one of them is no longer as the import left it
 */
export async function undoImport(uuid, created) {
	return request('POST', `/api/vehicles/${uuid}/import/undo`, { created })
}

/**
 * One vehicle's bookings from a week ago on, by start: the coming ones and the last week's.
 *
 * @param {string} uuid - the vehicle
 * @param {object} [since] - how far back instead
 * @param {number} [since.from] - the instant, seconds, the bookings reach past; 0 for all of them
 * @return {Promise<Booking[]>} the list
 */
export async function listBookings(uuid, { from } = {}) {
	return request('GET', `/api/vehicles/${uuid}/bookings${from === undefined ? '' : `?from=${from}`}`)
}

/**
 * Book the vehicle for the session. No token: nothing was read that it could lose a race against.
 *
 * @param {string} uuid - the vehicle
 * @param {object} fields - the span, each end with its offset, and the purpose
 * @return {Promise<Booking>} the booking as the server wrote it
 * @throws {BookingConflictError} when another live booking holds part of the span
 */
export async function createBooking(uuid, fields) {
	return request('POST', `/api/vehicles/${uuid}/bookings`, fields)
}

/**
 * Move the span or rewrite the purpose. It states the whole booking: an absent purpose is cleared.
 *
 * @param {string} uuid - the vehicle
 * @param {{uuid: string, updated_at: number}} booking - as it was read
 * @param {object} fields - the span and the purpose
 * @return {Promise<Booking>} the booking as it now stands
 * @throws {ConflictError} when it moved on since it was read
 * @throws {BookingConflictError} when another live booking holds part of the new span
 */
export async function changeBooking(uuid, booking, fields) {
	return request('PUT', `/api/vehicles/${uuid}/bookings/${booking.uuid}`, { ...fields, updated_at: booking.updated_at })
}

/**
 * Cancel a booking. The row stays, `cancelled`; a booker who did not cancel it is told.
 *
 * @param {string} uuid - the vehicle
 * @param {{uuid: string, updated_at: number}} booking - as it was read
 * @return {Promise<Booking>} the booking as the cancel left it
 * @throws {ConflictError} when it moved on since it was read
 */
export async function cancelBooking(uuid, booking) {
	return request('DELETE', `/api/vehicles/${uuid}/bookings/${booking.uuid}?updated_at=${booking.updated_at}`)
}

/**
 * Take the car under a booking. No token: a booking already out refuses a second check-out. The
 * server stamps the moment; the client says only its offset.
 *
 * @param {string} uuid - the vehicle
 * @param {{uuid: string}} booking - the booking it is taken under
 * @param {Handover} handover - what the driver read off the car
 * @return {Promise<Booking>} the booking, now `out`
 * @throws {BookingConflictError} when the car is still out with someone, or booked until then
 */
export async function checkOut(uuid, booking, handover) {
	return request('POST', `/api/vehicles/${uuid}/bookings/${booking.uuid}/check-out`, handover)
}

/**
 * Give the car back. Writes no Reading: the trip it answers a draft of is the evidence
 * (docs/architecture.md, "A booking is a plan").
 *
 * @param {string} uuid - the vehicle
 * @param {{uuid: string}} booking - the booking it was taken under
 * @param {Handover} handover - what the driver read off the car
 * @return {Promise<Booking>} the booking, now `returned`, its trip as a draft
 */
export async function checkIn(uuid, booking, handover) {
	return request('POST', `/api/vehicles/${uuid}/bookings/${booking.uuid}/check-in`, handover)
}

/**
 * The files in the person's inbox folder that no paper references yet (docs/architecture.md#the-inbox).
 *
 * @return {Promise<Inbox>} the folder, the newest of them, and how many wait
 */
export async function readInbox() {
	return request('GET', '/api/inbox')
}

/**
 * Nextcloud's own thumbnail of a file; no preview code of ours. A PDF gets core's icon, since an
 * instance previews PDFs only when its admin enabled that.
 *
 * @param {Pick<Waiting, 'file_id'|'mime'>} file - one waiting in the inbox
 * @return {string} the image's address
 */
export function thumbnailUrl(file) {
	if (file.mime === 'application/pdf') {
		return imagePath('core', 'filetypes/application-pdf')
	}

	return generateUrl(`/core/preview?fileId=${file.file_id}&x=256&y=256&a=1&mimeFallback=true`)
}

/**
 * What the QR sticker carries: the app opened on the vehicle with the entry sheet up (docs/ui.md,
 * "The QR shortcut"). Absolute, because a phone's camera has no page to resolve it against. It
 * names the vehicle and nothing else; opening it still takes a session (docs/security.md).
 *
 * @param {string} uuid - the vehicle
 * @return {string} the address
 */
export function stickerUrl(uuid) {
	const query = new URLSearchParams({ vehicle: uuid, entry: 'new' })
	return new URL(`${generateUrl('/apps/nextfleet/')}?${query}`, window.location.href).href
}

/**
 * This user's settings. No identity in the URL: a session reaches its own and no others
 * (docs/security.md).
 *
 * @return {Promise<Settings>} the choices and the options they are picked from
 */
export async function getPreferences() {
	return request('GET', '/api/preferences')
}

/**
 * Change a preference. It carries no token — a personal setting has one writer, so there is no
 * race to lose (docs/architecture.md#concurrency).
 *
 * @param {Partial<Settings['preferences']>} fields - the preferences to change, the rest untouched
 * @return {Promise<Settings>} the settings as the server now holds them
 */
export async function savePreferences(fields) {
	return request('PUT', '/api/preferences', fields)
}

/**
 * @param {string} method - the HTTP verb
 * @param {string} path - below the app's own route prefix
 * @param {object} [body] - sent as JSON, which Nextcloud merges into the request parameters
 * @return {Promise<any>} the parsed answer
 */
async function request(method, path, body) {
	const response = await fetch(generateUrl(`/apps/nextfleet${path}`), {
		method,
		headers: {
			'Content-Type': 'application/json',
			requesttoken: getRequestToken() ?? '',
		},
		// A read has no body at all. `JSON.stringify(undefined)` is `undefined`, but spelling it
		// out keeps a future `null` from travelling as the string "null".
		body: body === undefined ? undefined : JSON.stringify(body),
	})

	const answer = await parse(response)
	if (response.ok) {
		return answer
	}

	throw refusal(response, answer)
}

/**
 * The error a refused request is thrown as, by what the server answered.
 *
 * @param {Response} response - the refusal
 * @param {any} answer - its parsed body, or null
 * @return {Error} the error to throw
 */
function refusal(response, answer) {
	// Nextcloud answers its own failed CSRF check with 412 as well, so the conflict is the one
	// the body claims, not the one the status suggests.
	if (response.status === 412 && answer?.conflict === true) {
		return new ConflictError(answer.message)
	}
	if (response.status === 404) {
		return new NotFoundError(answer?.message ?? 'Not found')
	}
	if (response.status === 409 && answer?.booking) {
		return new BookingConflictError(answer.message, answer.booking)
	}
	if (response.status === 409) {
		return new ChangedError(answer?.message ?? 'Changed meanwhile')
	}
	if (response.status === 422 && typeof answer?.reason === 'string') {
		return new ImportRefusedError(answer.message, answer.reason, answer.row ?? null)
	}
	if (response.status === 423) {
		return new LockedError(answer?.message ?? 'Locked')
	}

	return new Error(answer?.message ?? `The server answered ${response.status}`)
}

/**
 * @param {Response} response - the answer to read
 * @return {Promise<any>} its JSON body, or null when it carries none
 */
async function parse(response) {
	try {
		return await response.json()
	} catch {
		// A refusal from the server itself — a session that expired into a login page — is not
		// JSON. It is still a failure, and the status is what tells the story.
		return null
	}
}
