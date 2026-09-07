/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Which capacity each energy implies. A vehicle's Energy Types are what it actually accepts
 * (CONTEXT.md), so they and not the Engine decide which number there is to ask for: a plug-in
 * hybrid has a tank and a battery, a diesel has only a tank, and a trailer has neither.
 *
 * @type {Record<string, string>}
 */
const CAPACITY = {
	petrol: 'tank_ml',
	diesel: 'tank_ml',
	lpg: 'tank_ml',
	cng: 'tank_ml',
	electric: 'battery_wh',
}

/**
 * The order the fields are asked for in, which is the order the edit sheet shows them in
 * (src/components/VehicleSheet.vue) - so following the hint reads top to bottom.
 */
const ORDER = ['vin', 'first_reg', 'tank_ml', 'battery_wh']

/**
 * What creating a vehicle in four fields left out (docs/ui.md): its identity, its age, and the
 * capacity of whatever it is filled with. This is the whole rule behind the "complete this
 * vehicle" hint, and it is a question rather than a validation - a vehicle is perfectly usable
 * without any of it.
 *
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string[]} the columns still unanswered, as the API spells them
 */
export function missingFrom(vehicle) {
	const asked = ['vin', 'first_reg', ...(vehicle.energy_types ?? []).map((one) => CAPACITY[one])]

	return ORDER.filter((column) => asked.includes(column) && !answered(vehicle, column))
}

/**
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @param {string} column - the column to look at
 * @return {boolean} whether somebody has answered it. A zero is an answer; it may be a wrong one,
 *   and the hint is not a validator.
 */
function answered(vehicle, column) {
	const value = /** @type {Record<string, unknown>} */ (vehicle)[column]

	return value !== null && value !== undefined && value !== ''
}
