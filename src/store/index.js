/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { createVehicle, getVehicle, listVehicles, recordReading, updateVehicle } from '../services/api.js'

/** @typedef {import('../services/api.js').Vehicle} Vehicle */
/** @typedef {import('../services/api.js').Reading} Reading */

/**
 * A sold vehicle leaves the overview and a laid-up one sinks below the vehicles somebody still
 * drives (docs/ui.md). Reminders are what turn the rest of the order into urgency; until they
 * arrive (M4) the server's order stands, and Array#sort keeps it.
 */
/** @type {Record<string, number>} */
const RANK = { active: 0, laid_up: 1 }

/**
 * Vehicles held by uuid, because a plate is a label a user may change at any
 * time — a second write under the same uuid is the same vehicle.
 */
export const useVehiclesStore = defineStore('vehicles', () => {
	/** @type {import('vue').Ref<Map<string, Vehicle>>} */
	const byUuid = ref(new Map())

	const list = computed(() => [...byUuid.value.values()])

	const visible = computed(() => list.value
		.filter((vehicle) => vehicle.lifecycle !== 'disposed')
		.sort((a, b) => (RANK[a.lifecycle ?? 'active'] ?? 0) - (RANK[b.lifecycle ?? 'active'] ?? 0)))

	/**
	 * @param {Vehicle} vehicle - the vehicle to add or replace
	 */
	function upsert(vehicle) {
		byUuid.value.set(vehicle.uuid, vehicle)
	}

	/**
	 * Read the fleet. The answer is the whole truth, so what it leaves out is dropped rather than
	 * kept as a row nothing can write to.
	 *
	 * @return {Promise<void>} when the store holds the fleet
	 */
	async function load() {
		const fleet = await listVehicles()

		byUuid.value = new Map(fleet.map((vehicle) => [vehicle.uuid, vehicle]))
	}

	/**
	 * Add a vehicle and hold what the server made of it, identity and defaults included.
	 *
	 * @param {Partial<Vehicle>} fields - the ones the create sheet asks for (docs/ui.md)
	 * @return {Promise<Vehicle>} the vehicle as the server made it
	 */
	async function create(fields) {
		const vehicle = await createVehicle(fields)
		upsert(vehicle)

		return vehicle
	}

	/**
	 * Write a vehicle back and hold what the server saved, token included. A refusal is not
	 * caught here: the sheet that asked for the save is what stays open and offers the retry
	 * (docs/ui.md), and it cannot if the store answers for it.
	 *
	 * @param {Vehicle} vehicle - the vehicle as edited, carrying the `updated_at` it was read with
	 * @return {Promise<Vehicle>} the vehicle as the server now holds it
	 */
	async function save(vehicle) {
		const saved = await updateVehicle(vehicle)
		upsert(saved)

		return saved
	}

	/**
	 * Record one reading of a vehicle's counter, then read the vehicle back: `odo_value` is a
	 * cache the server restates from the whole chain, and counting it here would be a second
	 * implementation of the odometer rules — a wrong one as soon as a reading lands out of order.
	 *
	 * The re-read may fail on its own, and by then the Reading is written. Raising that would
	 * offer the sheet a retry which writes the Reading a second time, and nothing refuses a
	 * duplicate — two equal readings are not a contradiction. So the counter stays stale until
	 * the next load, which is the cheaper wrong.
	 *
	 * @param {string} uuid - the vehicle the counter belongs to
	 * @param {object} entry - what the sheet holds: the value, and the offset it was read at
	 * @return {Promise<Reading>} the reading as the server judged it
	 */
	async function record(uuid, entry) {
		const reading = await recordReading(uuid, entry)
		try {
			upsert(await getVehicle(uuid))
		} catch {
			// See above: the write stands, only this view of it is behind.
		}

		return reading
	}

	return { byUuid, create, list, load, record, save, upsert, visible }
})
