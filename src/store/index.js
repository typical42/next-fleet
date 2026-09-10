/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { createVehicle, deleteVehicle, getVehicle, listVehicles, recordReading, recordTrip, restoreVehicle, updateVehicle } from '../services/api.js'

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

	/**
	 * The vehicle the last delete answered with — the way back into the fleet, and the only place
	 * the token that takes it is held. State rather than a return value: the screen that asks for
	 * a delete is unmounted by it, and a Vue component that is gone emits nothing.
	 *
	 * @type {import('vue').Ref<Vehicle|null>}
	 */
	const deleted = ref(null)

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
	 * Delete a vehicle. It leaves the fleet rather than staying as a flagged row: `visible` hides
	 * the disposed ones and nothing else, so a row left here would still be listed and still be
	 * openable. A refusal is not caught, as with `save()`.
	 *
	 * What the delete answered with is kept, because the token on it is the only one the undo is
	 * accepted with (docs/architecture.md#concurrency) and the screen that asked for the delete
	 * leaves with the vehicle - it cannot hold anything. One vehicle at a time: a second delete
	 * takes the offer of the first, which the toast has by then made and had answered.
	 *
	 * @param {Vehicle} vehicle - the vehicle as it was read, carrying the `updated_at` it was read with
	 * @return {Promise<void>} when it is out of the fleet and the way back is held
	 */
	async function remove(vehicle) {
		deleted.value = await deleteVehicle(vehicle)
		byUuid.value.delete(vehicle.uuid)
	}

	/**
	 * Undo the last delete, checked against the token that delete answered with — no newer one
	 * exists and no older one is accepted (docs/architecture.md#concurrency). A refusal leaves the
	 * offer standing: it is the only way back there is, and the vehicle is still deleted.
	 *
	 * Nothing deleted is nothing to undo, and no request: the toast is the only caller and it is
	 * only up while there is an offer, so this is the state after a page load, not a failure.
	 *
	 * @return {Promise<void>} when the vehicle is back in the fleet
	 */
	async function restore() {
		if (deleted.value === null) {
			return
		}

		upsert(await restoreVehicle(deleted.value))
		deleted.value = null
	}

	/** Let go of the way back, which is what closing the toast means. */
	function forget() {
		deleted.value = null
	}

	/**
	 * Record one reading of a vehicle's counter.
	 *
	 * @param {string} uuid - the vehicle the counter belongs to
	 * @param {object} entry - what the sheet holds: the value, and the offset it was read at
	 * @return {Promise<Reading>} the reading as the server judged it
	 */
	async function record(uuid, entry) {
		return counted(uuid, () => recordReading(uuid, entry))
	}

	/**
	 * Record one trip. It writes a Reading of its own — the counter it ended on, or the distance
	 * counted onto the chain (docs/architecture.md#odometer-rules).
	 *
	 * @param {string} uuid - the vehicle that drove it
	 * @param {object} trip - what the sheet holds (src/services/api.js)
	 * @return {Promise<import('../services/api.js').Trip>} the trip as the server wrote it
	 */
	async function log(uuid, trip) {
		return counted(uuid, () => recordTrip(uuid, trip))
	}

	/**
	 * One write that moves a vehicle's counter, and the read of the vehicle that follows it:
	 * `odo_value` is a cache the server restates from the whole chain, and counting it here would
	 * be a second implementation of the odometer rules — a wrong one as soon as a row lands out of
	 * order.
	 *
	 * The re-read may fail on its own, and by then the write has landed. Raising that would offer
	 * the sheet a retry which writes the entry a second time, and nothing refuses a duplicate —
	 * two equal readings are not a contradiction. So the counter stays stale until the next load,
	 * which is the cheaper wrong. A refused write is another matter: it never reached the vehicle,
	 * and the sheet is what has to say so (docs/ui.md).
	 *
	 * @template T
	 * @param {string} uuid - the vehicle the counter belongs to
	 * @param {() => Promise<T>} write - the write to make
	 * @return {Promise<T>} what the write answered with
	 */
	async function counted(uuid, write) {
		const written = await write()
		try {
			upsert(await getVehicle(uuid))
		} catch {
			// See above: the write stands, only this view of it is behind.
		}

		return written
	}

	return { byUuid, create, deleted, forget, list, load, log, record, remove, restore, save, upsert, visible }
})
