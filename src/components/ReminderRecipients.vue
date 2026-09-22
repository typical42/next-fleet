<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { computed, onMounted, ref } from 'vue'

import { addRecipient, listRecipients, removeRecipient, searchUsers } from '../services/api.js'

const props = defineProps({
	/** The vehicle's uuid. */
	vehicle: { type: String, required: true },
	/** Whether the sheet around it is mid-save. */
	disabled: { type: Boolean, default: false },
})

/**
 * The mail cadence is a column of the vehicle, so it is saved with the vehicle; the list is its
 * own table and is written on each pick.
 */
const cadence = defineModel('cadence', { type: String, default: 'weekly' })

/**
 * The list as the server last answered, or null while it is unread or was refused. Only someone
 * who may edit the vehicle reads it, so a refusal is how a driver or viewer is told apart, and
 * they see neither control.
 *
 * @type {import('vue').Ref<import('../services/api.js').Recipient[]|null>}
 */
const recipients = ref(null)
/** @type {import('vue').Ref<import('../services/api.js').Recipient[]>} */
const found = ref([])
const writing = ref(false)
const failure = ref('')

/**
 * @param {import('../services/api.js').Recipient} one - an account
 * @return {{ id: string, displayName: string, subname: string, user: string }} it as the picker
 *   shows it; the picker filters again on the names it shows, so the account is one of them
 */
function option(one) {
	return { id: one.user_id, displayName: one.display_name, subname: one.user_id, user: one.user_id }
}

const chosen = computed(() => (recipients.value ?? []).map(option))
const options = computed(() => found.value.map(option))

const cadences = computed(() => [
	{ id: 'off', label: t('nextfleet', 'No mail') },
	{ id: 'daily', label: t('nextfleet', 'Daily') },
	{ id: 'weekly', label: t('nextfleet', 'Weekly, on Monday') },
	{ id: 'monthly', label: t('nextfleet', 'Monthly, on the 1st') },
])
const cadenceOption = computed(() => cadences.value.find((one) => one.id === cadence.value) ?? null)

onMounted(async () => {
	try {
		recipients.value = await listRecipients(props.vehicle)
	} catch {
		recipients.value = null
	}
})

/** @param {string} term - what was typed */
async function search(term) {
	try {
		found.value = term.trim() === '' ? [] : await searchUsers(term)
	} catch {
		found.value = []
	}
}

/**
 * The picker hands over the whole new selection; it differs from the list by the one account
 * added or taken off, which is the write.
 *
 * @param {{ id: string }[]} selection - what the picker now holds
 */
async function pick(selection) {
	const before = chosen.value.map((one) => one.id)
	const after = selection.map((one) => one.id)
	const added = after.find((id) => !before.includes(id))
	const removed = before.find((id) => !after.includes(id))

	writing.value = true
	failure.value = ''
	try {
		if (added !== undefined) {
			recipients.value = await addRecipient(props.vehicle, added)
		} else if (removed !== undefined) {
			recipients.value = await removeRecipient(props.vehicle, removed)
		}
	} catch (error) {
		failure.value = t('nextfleet', 'The list was not changed: {reason}', { reason: error.message })
	} finally {
		writing.value = false
	}
}
</script>

<template>
	<div v-if="recipients !== null" class="recipients">
		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<NcSelectUsers :model-value="chosen"
			:options="options"
			:input-label="t('nextfleet', 'Reminders go to')"
			:disabled="disabled || writing"
			:loading="writing"
			multiple
			@search="search"
			@update:model-value="pick" />
		<NcSelect :model-value="cadenceOption"
			:options="cadences"
			:input-label="t('nextfleet', 'Reminder mail')"
			:disabled="disabled"
			:clearable="false"
			label="label"
			@update:model-value="cadence = $event?.id ?? cadence" />
	</div>
</template>

<style scoped>
.recipients {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
