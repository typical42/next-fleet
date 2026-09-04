/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

/**
 * @typedef {object} Vehicle
 * @property {string} uuid - identity; the plate is only a label
 * @property {string} [plate] - as registered, free to change
 */

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

	return { list, upsert }
})
