/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Which capacity each energy implies. The Energy Types, not the Engine, decide it (CONTEXT.md): a
 * plug-in hybrid has a tank and a battery, a trailer has neither.
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

/** The edit sheet's order (src/components/VehicleSheet.vue), so the hint reads top to bottom. */
const ORDER = ['vin', 'first_reg', 'tank_ml', 'battery_wh', 'currency']

/**
 * What creating a vehicle in four fields left out (docs/ui.md): its identity, its age, its
 * capacities and its currency, asked of every vehicle since a trailer has costs too. The hint is a
 * question, not a validation: a vehicle is usable without any of it.
 *
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string[]} the columns still unanswered, as the API spells them
 */
export function missingFrom(vehicle) {
	const asked = ['vin', 'first_reg', 'currency', ...(vehicle.energy_types ?? []).map((one) => CAPACITY[one])]

	return ORDER.filter((column) => asked.includes(column) && !answered(vehicle, column))
}

/**
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @param {string} column - the column to look at
 * @return {boolean} whether somebody has answered it; a zero is an answer, if maybe a wrong one
 */
function answered(vehicle, column) {
	const value = /** @type {Record<string, unknown>} */ (vehicle)[column]

	return value !== null && value !== undefined && value !== ''
}
