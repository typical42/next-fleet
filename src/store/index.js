/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { updateVehicle } from '../services/api.js'

/** @typedef {import('../services/api.js').Vehicle} Vehicle */

/**
 * Vehicles held by uuid, because a plate is a label a user may change at any
 * time — a second write under the same uuid is the same vehicle.
 */
export const useVehiclesStore = defineStore('vehicles', () => {
	/** @type {import('vue').Ref<Map<string, Vehicle>>} */
	const byUuid = ref(new Map())

	const list = computed(() => [...byUuid.value.values()])

	/**
	 * @param {Vehicle} vehicle - the vehicle to add or replace
	 */
	function upsert(vehicle) {
		byUuid.value.set(vehicle.uuid, vehicle)
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

	return { byUuid, list, save, upsert }
})
