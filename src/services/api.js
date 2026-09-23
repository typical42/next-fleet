/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateOcsUrl, generateUrl } from '@nextcloud/router'

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
 * @property {{grams: number, year: number|null, source: string|null}|null} grid - the factor the
 *   charges were read at, null without a charge; the reader's own has no year and no source
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
 * The personal settings screen's whole state: what this user chose, and what each choice may be.
 * The options travel with the values because a dropdown needs both and the app has one API
 * surface (docs/adr/0006-one-api-surface-in-v1.md).
 *
 * @typedef {object} Settings
 * @property {{ jurisdiction: string, dismissed_hints: string[], reclaim_vat: boolean, kpi_period: string, grid_factor: number|null }} preferences -
 *   this user's own choices: the country new vehicles are kept under, the vehicles whose "complete
 *   this vehicle" hint they have answered, whether cost figures are net of VAT, the period the
 *   vehicle header shows, and their electricity's grams of CO₂ per kWh (null for the country's)
 * @property {{ key: string, name: string, logbook_export: boolean, mileage_claim: boolean, grid_factor: {grams: number, year: number, source: string}|null }[]} jurisdictions -
 *   the registered countries, English, whether each prints a logbook and a mileage claim, and its grid average
 */

/**
 * The row moved on since it was read, so the write was refused instead of overwriting it
 * (docs/architecture.md#concurrency). Its own class because the sheet answers it differently
 * from every other failure: the values are fine, the version is not.
 */
export class ConflictError extends Error {}

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
 * Undo a delete. It is checked against the token the delete answered with and leaves it where it
 * is, so the toast may hand back exactly the vehicle it was given and nothing newer.
 *
 * @param {Vehicle} vehicle - the vehicle as the delete answered with it
 * @return {Promise<Vehicle>} the vehicle, back in the fleet
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
 *   an end counter or a distance, and whatever of the route the driver typed
 * @return {Promise<Trip>} the trip as the server wrote it
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
 * Accounts matching what was typed, for the recipient picker. Core's own search rather than one of
 * ours: it already applies the instance's rules on who may find whom, which the sharing dialog
 * uses too.
 *
 * @param {string} term - what was typed
 * @return {Promise<Recipient[]>} at most ten accounts
 */
export async function searchUsers(term) {
	const query = new URLSearchParams({ search: term, itemType: 'nextfleet', itemId: '', limit: '10' })
	// A user share only: groups, mail addresses and remote accounts are nobody the job can notify.
	query.append('shareTypes[]', '0')
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
	return (answer?.ocs?.data ?? []).map((/** @type {{id: string, label: string}} */ one) => ({ user_id: one.id, display_name: one.label }))
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

	// Nextcloud answers its own failed CSRF check with 412 as well, so the conflict is the one
	// the body claims, not the one the status suggests.
	if (response.status === 412 && answer?.conflict === true) {
		throw new ConflictError(answer.message)
	}

	throw new Error(answer?.message ?? `The server answered ${response.status}`)
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
