/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { readInbox } from '../services/api.js'

/**
 * The receipt inbox (docs/architecture.md#the-inbox) as this session last read it. A store rather
 * than the screen's own state, because the navigation shows the count and the screen takes from it.
 */
export const useInboxStore = defineStore('inbox', () => {
	/** @type {import('vue').Ref<import('../services/api.js').Inbox['folder']>} */
	const folder = ref(null)
	/** @type {import('vue').Ref<import('../services/api.js').Waiting[]>} */
	const files = ref([])
	const count = ref(0)
	/**
	 * The vehicle the last file went to, offered for the next: a batch of receipts is mostly one
	 * car's. Kept for the session only, since nothing in the app is written to the browser
	 * (docs/ui.md, the entry sheet).
	 *
	 * @type {import('vue').Ref<string|null>}
	 */
	const lastVehicle = ref(null)

	/** @return {Promise<void>} when the inbox is read */
	async function load() {
		const inbox = await readInbox()
		folder.value = inbox.folder
		files.value = inbox.files
		count.value = inbox.count
	}

	/**
	 * Reads the inbox again after a paper was attached, detached or restored elsewhere, which moves
	 * the count beside the menu; without a folder nothing waits. The write it follows stands, so a
	 * refused read keeps the count it had.
	 *
	 * @return {Promise<void>} when the inbox is read, or the read was refused
	 */
	async function refresh() {
		if (folder.value === null) {
			return
		}
		try {
			await load()
		} catch {
			// See above.
		}
	}

	/**
	 * A file attached is no longer waiting. Taken off here rather than read again: the server
	 * would answer the same, one request later.
	 *
	 * @param {number} fileId - the file just attached
	 * @param {string} vehicle - the vehicle it went to
	 */
	function attached(fileId, vehicle) {
		const before = files.value.length
		files.value = files.value.filter((one) => one.file_id !== fileId)
		count.value -= before - files.value.length
		lastVehicle.value = vehicle
	}

	return {
		attached,
		count: computed(() => count.value),
		files: computed(() => files.value),
		folder: computed(() => folder.value),
		lastVehicle: computed(() => lastVehicle.value),
		load,
		refresh,
	}
})
