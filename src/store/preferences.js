/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { getPreferences, savePreferences } from '../services/api.js'

/**
 * This user's own choices. Not part of the vehicles store: a preference belongs to the person and
 * outlives every vehicle they keep (CONTEXT.md).
 */
export const usePreferencesStore = defineStore('preferences', () => {
	/** The vehicles whose hint this user has answered. @type {import('vue').Ref<string[]>} */
	const dismissed = ref([])

	/** The vehicles whose Logbook Mode question this user has answered. @type {import('vue').Ref<string[]>} */
	const dismissedLogbook = ref([])

	/** The countries with a logbook ruleset, whose vehicles that question is for. @type {import('vue').Ref<string[]>} */
	const ruled = ref([])

	/**
	 * Whether the preferences have been read. Not the same as an empty list: until then a screen
	 * cannot tell an open hint from a dismissed one; showing a dismissed one undoes the dismissal.
	 */
	const loaded = ref(false)

	/** Whether cost figures are net of VAT. The server's default until it answers. */
	const reclaimVat = ref(false)

	/** The period the vehicle header shows, one of `PERIODS` in src/utils/period.js. */
	const period = ref('last-12')

	/**
	 * The period picked in this session, if any. It outranks every answer: an earlier pick's reply,
	 * a late load, or a refusal would otherwise flip the header back.
	 *
	 * @type {string|null}
	 */
	let picked = null

	/**
	 * The inbox folder's file id, or null while none is chosen. The settings page, another page,
	 * chooses it, so this session never writes it.
	 *
	 * @type {import('vue').Ref<number|null>}
	 */
	const inboxFolder = ref(null)

	/** @param {import('../services/api.js').Settings} settings - the server's answer */
	function hold(settings) {
		dismissed.value = settings.preferences.dismissed_hints
		dismissedLogbook.value = settings.preferences.dismissed_logbook_hints
		ruled.value = settings.jurisdictions.filter((one) => one.logbook_rules).map((one) => one.key)
		inboxFolder.value = settings.preferences.inbox_folder
		reclaimVat.value = settings.preferences.reclaim_vat
		period.value = picked ?? settings.preferences.kpi_period
		loaded.value = true
	}

	/**
	 * @return {Promise<void>} when this user's preferences are held
	 */
	async function load() {
		hold(await getPreferences())
	}

	/**
	 * The dismissal being written, if one is. The route replaces the whole list, so two at once
	 * would each send it without the other's, and the second answer would undo the first.
	 *
	 * @type {Promise<void>}
	 */
	let writing = Promise.resolve()

	/**
	 * Answer one vehicle's hint, for good and on every machine this user opens the app on.
	 * A refusal rejects to the screen that offered the click (src/store/index.js); the next
	 * dismissal, a different click, does not inherit it.
	 *
	 * @param {string} uuid - the vehicle whose hint was answered
	 * @return {Promise<void>} when it is stored
	 */
	function dismiss(uuid) {
		writing = writing.catch(() => {}).then(() => store('dismissed_hints', dismissed, uuid))

		return writing
	}

	/**
	 * Answer one vehicle's Logbook Mode question, as `dismiss()` answers its hint.
	 *
	 * @param {string} uuid - the vehicle whose question was answered
	 * @return {Promise<void>} when it is stored
	 */
	function dismissLogbook(uuid) {
		writing = writing.catch(() => {}).then(() => store('dismissed_logbook_hints', dismissedLogbook, uuid))

		return writing
	}

	/**
	 * What is stored is read first: the route replaces the whole list
	 * (lib/Service/PreferencesService.php), so a write off an unread list would drop every earlier
	 * dismissal.
	 *
	 * @param {string} key - the preference holding the list
	 * @param {import('vue').Ref<string[]>} list - this store's copy of it
	 * @param {string} uuid - the vehicle whose hint was answered
	 * @return {Promise<void>} when it is stored
	 */
	async function store(key, list, uuid) {
		if (!loaded.value) {
			await load()
		}

		if (list.value.includes(uuid)) {
			return
		}

		hold(await savePreferences({ [key]: [...list.value, uuid] }))
	}

	/**
	 * Show another period, and keep it for the next session. Held before the write answers, so the
	 * header reads once; a refusal keeps it for this session and rejects for whoever cares. Queued
	 * behind any other write, so the one stored last is the one picked last.
	 *
	 * @param {string} chosen - one of `PERIODS` in src/utils/period.js
	 * @return {Promise<void>} when it is stored
	 */
	function choosePeriod(chosen) {
		picked = chosen
		period.value = chosen
		writing = writing.catch(() => {}).then(async () => hold(await savePreferences({ kpi_period: chosen })))

		return writing
	}

	/**
	 * @param {string} uuid - the vehicle to ask about
	 * @return {boolean} whether this user has answered its hint
	 */
	function isDismissed(uuid) {
		return dismissed.value.includes(uuid)
	}

	/**
	 * @param {string} uuid - the vehicle to ask about
	 * @return {boolean} whether this user has answered its Logbook Mode question
	 */
	function isLogbookDismissed(uuid) {
		return dismissedLogbook.value.includes(uuid)
	}

	/**
	 * @param {string} jurisdiction - a vehicle's country
	 * @return {boolean} whether it has a logbook ruleset (lib/Jurisdiction/ILogbookRules.php)
	 */
	function hasLogbookRules(jurisdiction) {
		return ruled.value.includes(jurisdiction)
	}

	return {
		choosePeriod,
		dismiss,
		dismissLogbook,
		dismissed: computed(() => dismissed.value),
		hasLogbookRules,
		inboxFolder: computed(() => inboxFolder.value),
		isDismissed,
		isLogbookDismissed,
		load,
		loaded,
		period: computed(() => period.value),
		reclaimVat: computed(() => reclaimVat.value),
	}
})
