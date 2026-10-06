<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { computed, onMounted, ref } from 'vue'

import { addRecipient, listRecipients, removeRecipient, searchUsers } from '../services/api.js'
import { t } from '../utils/l10n.js'
import { latestSearch } from '../utils/search.js'

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
 * The list as the server last answered, or null while unread or refused. The vehicle sheet mounts
 * this only for `edit`; a refusal still shows neither control rather than an empty list.
 *
 * @type {import('vue').Ref<import('../services/api.js').Recipient[]|null>}
 */
const recipients = ref(null)
const { found, search } = latestSearch(searchUsers)
const writing = ref(false)
const failure = ref('')

/**
 * @param {import('../services/api.js').Recipient} one - an account
 * @return {{ id: string, displayName: string, subname: string, user: string }} it as the picker
 *   shows it; the account is the subname, as the picker filters on what it shows and a display
 *   name need not contain what was typed
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

/**
 * RecipientService's refusals a person can run into, by its English words; the rest show as sent.
 *
 * @type {Record<string, () => string>}
 */
const REFUSALS = {
	'user_id is not an account on this instance': () => t('nextfleet', 'You cannot add this account to the list.'),
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
		failure.value = REFUSALS[error.message]?.() ?? t('nextfleet', 'The list was not changed: {reason}', { reason: error.message })
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
