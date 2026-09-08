/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { getPreferences, savePreferences } from '../services/api.js'

/**
 * This user's own choices, as the app's screens read them. Its own store rather than a corner of
 * the vehicles one: a preference belongs to the person and outlives every vehicle they keep
 * (CONTEXT.md), and the server keeps the two apart for the same reason.
 */
export const usePreferencesStore = defineStore('preferences', () => {
	/** The vehicles whose hint this user has answered. @type {import('vue').Ref<string[]>} */
	const dismissed = ref([])

	/**
	 * Whether the preferences have been read at all. Not the same fact as an empty list: until the
	 * answer is in, a screen cannot tell a hint nobody dismissed from one somebody did, and
	 * showing it again would undo the dismissal in the only way that matters to the reader.
	 */
	const loaded = ref(false)

	/** @param {import('../services/api.js').Settings} settings - the server's answer */
	function hold(settings) {
		dismissed.value = settings.preferences.dismissed_hints
		loaded.value = true
	}

	/**
	 * @return {Promise<void>} when this user's preferences are held
	 */
	async function load() {
		hold(await getPreferences())
	}

	/**
	 * The dismissal being written, if one is. The route replaces the whole list, so two of them at
	 * once would each send the list as it stood before the other - and the one that answered
	 * second would take the first one's dismissal back out.
	 *
	 * @type {Promise<void>}
	 */
	let writing = Promise.resolve()

	/**
	 * Answer one vehicle's hint, for good and on every machine this user opens the app on.
	 *
	 * A refusal is not caught here - the hint stays and the screen that offered the click says so,
	 * as with every other write (src/store/index.js). It is caught for the *next* dismissal
	 * though, which is a different click and gets its own answer.
	 *
	 * @param {string} uuid - the vehicle whose hint was answered
	 * @return {Promise<void>} when it is stored
	 */
	function dismiss(uuid) {
		writing = writing.catch(() => {}).then(() => store(uuid))

		return writing
	}

	/**
	 * What is stored is read first: the route replaces the whole list
	 * (lib/Service/PreferencesService.php), so a write off a list this session never saw would drop
	 * every earlier dismissal.
	 *
	 * @param {string} uuid - the vehicle whose hint was answered
	 * @return {Promise<void>} when it is stored
	 */
	async function store(uuid) {
		if (!loaded.value) {
			await load()
		}

		if (dismissed.value.includes(uuid)) {
			return
		}

		hold(await savePreferences({ dismissed_hints: [...dismissed.value, uuid] }))
	}

	/**
	 * @param {string} uuid - the vehicle to ask about
	 * @return {boolean} whether this user has answered its hint
	 */
	function isDismissed(uuid) {
		return dismissed.value.includes(uuid)
	}

	return { dismiss, dismissed: computed(() => dismissed.value), isDismissed, load, loaded }
})
