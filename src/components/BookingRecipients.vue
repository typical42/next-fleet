<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { getCurrentUser } from '@nextcloud/auth'
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
 * The vehicle's events: what the owner hears while the list is empty, and what a new row starts
 * with. A column of the vehicle, saved with it; the list and each row's events are their own table
 * and are written on each pick and tick, as ReminderRecipients does it
 * (docs/architecture.md#booking-notices).
 */
const events = defineModel('events', { type: Array, required: true })

/**
 * The list as the server last answered, or null while unread or refused. The sheet mounts this for
 * the owner only; a refusal is a fault then, so it is said, and the vehicle's events go back as read.
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
const me = getCurrentUser()
// Nextcloud's search leaves out the one searching, and an owner off a list that holds somebody
// hears nothing, so the owner is offered first until listed.
const owner = computed(() => (me === null || chosen.value.some((one) => one.id === `user:${me.uid}`)
	? []
	: [option({ recipient: me.uid, recipient_type: 'user', display_name: me.displayName ?? me.uid })]))
const options = computed(() => [
	...owner.value,
	...found.value.map((one) => option({ recipient: one.grantee, recipient_type: one.grantee_type, display_name: one.display_name })),
])

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
 * @param {string[]} heard - the events before the tick
 * @param {string} id - the event ticked
 * @param {boolean} on - whether it is ticked now
 * @return {string[]} the events after it, in the order the server names them
 */
function ticked(heard, id, on) {
	return choices.value.map((one) => one.id).filter((one) => (one === id ? on : heard.includes(one)))
}

/**
 * Each row with its name and its events. The owner's while the list is empty, with the vehicle's
 * events and no recipient to write.
 */
const rows = computed(() => recipients.value?.length === 0
	? [{ key: 'owner', legend: t('nextfleet', 'You (owner)'), events: events.value, recipient: null }]
	: (recipients.value ?? []).map((one) => ({
		key: option(one).id,
		legend: one.recipient_type === 'group' ? t('nextfleet', 'Group {group}', { group: one.display_name }) : one.display_name,
		events: one.events,
		recipient: one,
	})))

/**
 * @param {{ events: string[], recipient: import('../services/api.js').BookingRecipient|null }} row - the row ticked
 * @param {string} id - the event
 * @param {boolean} on - whether it is ticked now
 */
async function tick(row, id, on) {
	const heard = ticked(row.events, id, on)
	if (row.recipient === null) {
		events.value = heard
		return
	}
	await write(() => addBookingRecipient(props.vehicle, { ...whom(row.recipient), events: heard }))
}

/**
 * @param {import('../services/api.js').BookingRecipient} one - an account or a group
 * @return {{ recipient: string, recipient_type: 'user'|'group' }} the name the routes take
 */
function whom(one) {
	return { recipient: one.recipient, recipient_type: one.recipient_type }
}

/**
 * One write to the list; the list becomes what the server answers.
 *
 * @param {() => Promise<import('../services/api.js').BookingRecipient[]>} send - the write
 */
async function write(send) {
	writing.value = true
	failure.value = ''
	try {
		recipients.value = await send()
	} catch (error) {
		failure.value = REFUSALS[error.message]?.() ?? t('nextfleet', 'The list was not changed: {reason}', { reason: error.message })
	} finally {
		writing.value = false
	}
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
 * taken off, which is the write. The server gives one added the vehicle's events as saved; the first
 * one takes the "You (owner)" row's ticks instead, as that row goes away with it.
 *
 * @param {{ id: string, recipient: import('../services/api.js').BookingRecipient }[]} selection - what the picker now holds
 */
async function pick(selection) {
	const before = chosen.value.map((one) => one.id)
	const added = selection.find((one) => !before.includes(one.id))
	const removed = chosen.value.find((one) => !selection.some((kept) => kept.id === one.id))
	if (added !== undefined) {
		const ownersTicks = recipients.value?.length === 0 ? { events: events.value } : {}
		await write(() => addBookingRecipient(props.vehicle, { ...whom(added.recipient), ...ownersTicks }))
	} else if (removed !== undefined) {
		await write(() => removeBookingRecipient(props.vehicle, whom(removed.recipient)))
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
			<fieldset class="notices__events">
				<legend>{{ t('nextfleet', 'What they hear of') }}</legend>
				<fieldset v-for="row in rows" :key="row.key" class="notices__row">
					<legend>{{ row.legend }}</legend>
					<NcCheckboxRadioSwitch v-for="one in choices"
						:key="one.id"
						:model-value="row.events.includes(one.id)"
						:disabled="disabled || writing"
						@update:model-value="tick(row, one.id, $event)">
						{{ one.label }}
					</NcCheckboxRadioSwitch>
				</fieldset>
			</fieldset>
		</template>
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

.notices__events,
.notices__row {
	border: none;
	padding: 0;
	margin: 0;
}

.notices__row {
	margin-top: calc(var(--default-grid-baseline) * 2);
}

.notices__row > legend {
	font-weight: bold;
}
</style>
