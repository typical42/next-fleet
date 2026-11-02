<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { computed, onMounted, ref } from 'vue'

import { addBookingRecipient, listBookingRecipients, removeBookingRecipient, searchGrantees } from '../services/api.js'
import { t } from '../utils/l10n.js'
import { latestSearch } from '../utils/search.js'

const props = defineProps({
	/** The vehicle's uuid. */
	vehicle: { type: String, required: true },
	/** Whether the sheet around it is mid-save. */
	disabled: { type: Boolean, default: false },
})

/**
 * The events are a column of the vehicle, saved with it; the list is its own table and is written
 * on each pick, as ReminderRecipients does it (docs/architecture.md#booking-notices).
 */
const events = defineModel('events', { type: Array, required: true })

/**
 * The list as the server last answered, or null while unread or refused. The sheet mounts this for
 * the owner only; a refusal is a fault then, so it is said, and the events stay.
 *
 * @type {import('vue').Ref<import('../services/api.js').BookingRecipient[]|null>}
 */
const recipients = ref(null)
const { found, search } = latestSearch(searchGrantees)
const writing = ref(false)
const failure = ref('')
/** Why the list could not be read, or '' while it is read or once it was. */
const unread = ref('')

/**
 * @param {import('../services/api.js').BookingRecipient} one - an account or a group
 * @return {object} it as the picker shows it; the id carries the type, as VehicleGrants' does
 */
function option(one) {
	return one.recipient_type === 'group'
		? { id: `group:${one.recipient}`, displayName: one.display_name, subname: t('nextfleet', 'Group {group}', { group: one.recipient }), isNoUser: true, recipient: one }
		: { id: `user:${one.recipient}`, displayName: one.display_name, subname: one.recipient, user: one.recipient, recipient: one }
}

const chosen = computed(() => (recipients.value ?? []).map(option))
const options = computed(() => found.value.map((one) => option({ recipient: one.grantee, recipient_type: one.grantee_type, display_name: one.display_name })))

/** In the order the server names them. */
const choices = computed(() => [
	{ id: 'booked', label: t('nextfleet', 'Booked ahead') },
	{ id: 'taken', label: t('nextfleet', 'Taken now') },
	{ id: 'returned', label: t('nextfleet', 'Returned, with the counter and the level') },
	{ id: 'cancelled', label: t('nextfleet', 'Cancelled') },
	{ id: 'changed', label: t('nextfleet', 'Moved to another start or end') },
])

onMounted(async () => {
	try {
		recipients.value = await listBookingRecipients(props.vehicle)
	} catch (error) {
		unread.value = t('nextfleet', 'The list could not be read: {reason}', { reason: error.message })
	}
})

/**
 * @param {string} id - the event
 * @param {boolean} on - whether it is ticked now
 */
function tick(id, on) {
	const kept = events.value.filter((one) => one !== id)
	events.value = on ? choices.value.map((one) => one.id).filter((one) => one === id || kept.includes(one)) : kept
}

/**
 * RecipientService's refusals a person can run into, by its English words; the rest show as sent.
 *
 * @type {Record<string, () => string>}
 */
const REFUSALS = {
	'recipient is not an account or group on this instance': () => t('nextfleet', 'You cannot add this account or group to the list.'),
}

/**
 * The picker hands over the whole new selection; it differs from the list by the one added or
 * taken off, which is the write.
 *
 * @param {{ id: string, recipient: import('../services/api.js').BookingRecipient }[]} selection - what the picker now holds
 */
async function pick(selection) {
	const before = chosen.value.map((one) => one.id)
	const added = selection.find((one) => !before.includes(one.id))
	const removed = chosen.value.find((one) => !selection.some((kept) => kept.id === one.id))

	/** @param {import('../services/api.js').BookingRecipient} one - an account or a group */
	const whom = (one) => ({ recipient: one.recipient, recipient_type: one.recipient_type })
	writing.value = true
	failure.value = ''
	try {
		if (added !== undefined) {
			recipients.value = await addBookingRecipient(props.vehicle, whom(added.recipient))
		} else if (removed !== undefined) {
			recipients.value = await removeBookingRecipient(props.vehicle, whom(removed.recipient))
		}
	} catch (error) {
		failure.value = REFUSALS[error.message]?.() ?? t('nextfleet', 'The list was not changed: {reason}', { reason: error.message })
	} finally {
		writing.value = false
	}
}
</script>

<template>
	<div class="notices">
		<NcNoteCard v-if="unread" type="error" :text="unread" />
		<template v-if="recipients !== null">
			<NcNoteCard v-if="failure" type="error" :text="failure" />
			<NcSelectUsers :model-value="chosen"
				:options="options"
				:input-label="t('nextfleet', 'Booking notices go to')"
				:disabled="disabled || writing"
				:loading="writing"
				multiple
				@search="search"
				@update:model-value="pick" />
			<p v-if="recipients.length === 0" class="notices__hint">
				{{ t('nextfleet', 'With nobody on the list, the notices go to you.') }}
			</p>
		</template>
		<fieldset class="notices__events">
			<legend>{{ t('nextfleet', 'What they hear of') }}</legend>
			<NcCheckboxRadioSwitch v-for="one in choices"
				:key="one.id"
				:model-value="events.includes(one.id)"
				:disabled="disabled"
				@update:model-value="tick(one.id, $event)">
				{{ one.label }}
			</NcCheckboxRadioSwitch>
		</fieldset>
	</div>
</template>

<style scoped>
.notices {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.notices__hint {
	color: var(--color-text-maxcontrast);
}

.notices__events {
	border: none;
	padding: 0;
	margin: 0;
}
</style>
