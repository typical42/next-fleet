<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { BookingConflictError, changeBooking, ConflictError, createBooking, listBookings } from '../services/api.js'
import { formatSpan, formatWhen } from '../utils/format.js'
import { t } from '../utils/l10n.js'
import { refusalWords, spanFault } from '../utils/pool.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The booking to change, or null to book.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Booking|null>}
	 */
	booking: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const HOUR = 3600 * 1000

const editing = computed(() => props.booking !== null)

/** Nobody books the minute they are in: a new booking starts at the next full hour, for two. */
const soon = new Date()
soon.setMinutes(60, 0, 0)

const start = ref(props.booking === null ? soon : new Date(props.booking.starts_at * 1000))
const end = ref(props.booking === null ? new Date(soon.getTime() + 2 * HOUR) : new Date(props.booking.ends_at * 1000))
const purpose = ref(props.booking?.purpose ?? '')

const saving = ref(false)
const failure = ref('')
/**
 * The booking that holds part of the span, as the refusal named it, or null.
 *
 * @type {import('vue').Ref<import('../services/api.js').BookingHeld|null>}
 */
const clash = ref(null)
/** The save was refused because the booking moved on; the next one reads it back first. */
const stale = ref(false)
/** The booking the next write is checked against: the prop, until a refused write reads a newer one. */
const held = ref(props.booking)

const action = computed(() => {
	if (stale.value) {
		return t('nextfleet', 'Save anyway')
	}
	if (failure.value) {
		return t('nextfleet', 'Try again')
	}

	return editing.value ? t('nextfleet', 'Save') : t('nextfleet', 'Book')
})

const note = computed(() => {
	if (clash.value !== null) {
		const name = clash.value.user_name
		const span = formatSpan(clash.value.starts_at, clash.value.starts_at_off, clash.value.ends_at, clash.value.ends_at_off)
		let text = t('nextfleet', 'Booked by {name}, {span}', { name, span })
		// A car still out is in somebody's hands, not just on their calendar: say whose, and when it is
		// due back - or, overdue, that it still is out, since "until" a time gone by would be wrong.
		if (clash.value.state === 'out') {
			text = clash.value.ends_at * 1000 > Date.now()
				? t('nextfleet', 'With {name} until {when}', { name, when: formatWhen(clash.value.ends_at, clash.value.ends_at_off) })
				: t('nextfleet', 'Still with {name}, booked {span}', { name, span })
		}

		return { type: 'error', text }
	}
	if (failure.value) {
		return { type: 'error', text: failure.value }
	}
	if (stale.value) {
		return { type: 'warning', text: t('nextfleet', 'This booking was changed somewhere else while you had it open. Saving again writes your values over that change.') }
	}

	return { type: 'error', text: '' }
})

/**
 * What the sheet writes. A change states the whole booking, so an emptied purpose travels empty.
 *
 * @return {Record<string, unknown>} the span, each end at its own offset, and the purpose
 */
function fields() {
	const lost = t('nextfleet', 'A booking needs a start and an end.')
	const from = stated(start.value, lost)
	const until = stated(end.value, lost)
	const fault = spanFault(from, until, props.booking)
	if (fault !== null) {
		throw new Error(fault)
	}

	return {
		starts_at: seconds(from),
		starts_at_off: offset(from),
		ends_at: seconds(until),
		ends_at_off: offset(until),
		purpose: purpose.value,
	}
}

/** One attempt. A refusal leaves the sheet open with every value intact (docs/ui.md). */
async function save() {
	saving.value = true
	failure.value = ''
	clash.value = null
	try {
		const written = fields()
		const booking = await current()
		emit('saved', booking === null
			? await createBooking(props.vehicle.uuid, written)
			: await changeBooking(props.vehicle.uuid, booking, written))
	} catch (error) {
		if (error instanceof BookingConflictError) {
			clash.value = error.booking
		} else if (error instanceof ConflictError) {
			stale.value = true
		} else {
			failure.value = refusalWords(error.message) ?? error.message
		}
	} finally {
		saving.value = false
	}
}

/**
 * The booking under the token that is current. After a refused write it is read back from the
 * list, since there is no read of one booking alone.
 *
 * @return {Promise<import('../services/api.js').Booking|null>} the booking, or null for a new one
 */
async function current() {
	if (stale.value && held.value !== null) {
		const uuid = held.value.uuid
		const found = (await listBookings(props.vehicle.uuid)).find((one) => one.uuid === uuid)
		if (found === undefined) {
			throw new Error(t('nextfleet', 'This booking is no longer listed.'))
		}
		held.value = found
		stale.value = false
	}

	return held.value
}

/**
 * @param {unknown} moment - what a picker holds
 * @param {string} complaint - what to say when it holds nothing
 * @return {Date} the moment
 */
function stated(moment, complaint) {
	if (!(moment instanceof Date) || Number.isNaN(moment.getTime())) {
		throw new Error(complaint)
	}

	return moment
}

/**
 * @param {Date} moment - what a picker holds
 * @return {number} the instant, in seconds
 */
function seconds(moment) {
	return Math.floor(moment.getTime() / 1000)
}

/**
 * @param {Date} moment - what a picker holds
 * @return {number} the UTC offset of that moment, not of now, in minutes
 */
function offset(moment) {
	return -moment.getTimezoneOffset()
}

/** Esc in a date field belongs to the picker the browser opened (VehicleSheet.vue says why). */
function keepPicker() {}

/** A sheet that is mid-save has values nobody has an answer for yet, so it does not close. */
function requestClose() {
	if (!saving.value) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="editing ? t('nextfleet', 'Change booking') : t('nextfleet', 'Book vehicle')"
		:open="true"
		size="small"
		@update:open="requestClose">
		<div class="sheet" @keydown.esc.stop="requestClose">
			<NcNoteCard v-if="note.text" :type="note.type" :text="note.text" />

			<NcDateTimePickerNative v-model="start"
				type="datetime-local"
				:label="t('nextfleet', 'Start')"
				:disabled="saving"
				@keydown.esc.stop="keepPicker" />
			<NcDateTimePickerNative v-model="end"
				type="datetime-local"
				:label="t('nextfleet', 'End')"
				:disabled="saving"
				@keydown.esc.stop="keepPicker" />
			<NcTextField v-model="purpose"
				:label="t('nextfleet', 'Purpose')"
				:disabled="saving" />
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ action }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.sheet {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}
</style>
