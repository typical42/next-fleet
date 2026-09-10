/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateUrl } from '@nextcloud/router'

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
 * @property {string} [lifecycle] - `active`, `laid_up` or `disposed` (CONTEXT.md)
 * @property {string|null} [vin] - the vehicle's identity as its maker stamped it
 * @property {string|null} [first_reg] - the day it was first registered, `YYYY-MM-DD`
 * @property {string[]|null} [energy_types] - the energy it actually accepts (CONTEXT.md)
 * @property {number|null} [tank_ml] - the tank, in millilitres
 * @property {number|null} [battery_wh] - the battery, in watt-hours
 */

/**
 * One reading of a vehicle's counter (docs/architecture.md#odometer-rules). `origin` and `flagged`
 * are the server's answer, never a field a client fills in.
 *
 * @typedef {object} Reading
 * @property {string} uuid - identity
 * @property {number} read_at - the instant it was read, seconds
 * @property {number} read_at_off - the UTC offset it was read at, minutes
 * @property {number} value - kilometres or engine hours, per the vehicle's `odo_unit`
 * @property {string} origin - `observed` when somebody read it, `derived` when it was computed
 * @property {boolean} flagged - it contradicts the reading before it
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
 * The personal settings screen's whole state: what this user chose, and what each choice may be.
 * The options travel with the values because a dropdown needs both and the app has one API
 * surface (docs/adr/0006-one-api-surface-in-v1.md).
 *
 * @typedef {object} Settings
 * @property {{ jurisdiction: string, dismissed_hints: string[] }} preferences - this user's own
 *   choices: the country new vehicles are kept under, and the vehicles whose "complete this
 *   vehicle" hint they have answered
 * @property {{ key: string, name: string }[]} jurisdictions - the registered countries, English
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
 * Record one reading of a vehicle's counter. A Reading is only ever written, so it carries no
 * token and cannot lose a race (docs/architecture.md#concurrency).
 *
 * @param {string} uuid - the vehicle the counter belongs to
 * @param {object} entry - `value` or `distance`, never both, plus `read_at_off`
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
