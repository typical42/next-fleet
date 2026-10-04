<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { BookingConflictError, checkIn, checkOut, createBooking } from '../services/api.js'
import { formatCount, formatSpan, parseWhole } from '../utils/format.js'
import { t } from '../utils/l10n.js'
import { refusalWords, spanFault } from '../utils/pool.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The booking the car is taken or given back under, or null to take it now: the sheet then
	 * books from now to the end it asks for, and takes the car under that.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Booking|null>}
	 */
	booking: { type: Object, default: null },
	/**
	 * The counter the car last came back at, or null. No Reading holds it until somebody logs that
	 * trip, so the vehicle's counter can be behind it.
	 */
	lastIn: { type: Number, default: null },
})

const emit = defineEmits(['close', 'saved'])

const HOUR = 3600 * 1000

/** A booking that is out is given back; any other the sheet is offered on is taken. */
const returning = computed(() => props.booking?.state === 'out')

/** Taken now, the car is booked until the end a new booking defaults to (BookingSheet.vue). */
const soon = new Date()
soon.setMinutes(60, 0, 0)
const end = ref(new Date(soon.getTime() + 2 * HOUR))

// Taking it, the furthest counter known is the best guess and the dashboard corrects it - the one
// BookingService's `odo_below` measures against. Giving it back, a prefilled counter would be saved
// unread, so the field starts empty.
const known = [props.vehicle.odo_value, props.lastIn].filter((one) => one !== null && one !== undefined)
const odo = ref(returning.value || known.length === 0 ? '' : String(Math.max(...known)))
const level = ref('')
const notes = ref('')

const saving = ref(false)
const failure = ref('')
/**
 * The booking in the way, as the refusal named it, or null.
 *
 * @type {import('vue').Ref<import('../services/api.js').BookingHeld|null>}
 */
const clash = ref(null)
/**
 * The booking the car is taken under: the prop, or the one taking it now made. Kept across a failed
 * check-out, so trying again does not book twice.
 */
const held = ref(props.booking)

const action = computed(() => {
	if (failure.value) {
		return t('nextfleet', 'Try again')
	}

	return returning.value ? t('nextfleet', 'Return the car') : t('nextfleet', 'Take the car')
})

const taken = computed(() => returning.value
	? t('nextfleet', 'Taken at {counter}', { counter: `${formatCount(props.booking?.out_odo ?? 0)} ${props.vehicle.odo_unit}` })
	: '')

const note = computed(() => {
	if (clash.value !== null) {
		const name = clash.value.user_name
		const span = formatSpan(clash.value.starts_at, clash.value.starts_at_off, clash.value.ends_at, clash.value.ends_at_off)

		return clash.value.state === 'out'
			? t('nextfleet', 'Still with {name}, booked {span}', { name, span })
			: t('nextfleet', 'Booked by {name}, {span}', { name, span })
	}

	return failure.value
})

/**
 * What the sheet states about the car. An empty level or note is left out: not stated is not zero.
 *
 * @return {import('../services/api.js').Handover} the handover
 */
function handover() {
	const counter = parseWhole(odo.value)
	if (counter === null) {
		throw new Error(t('nextfleet', 'That is not a counter reading.'))
	}
	/** @type {import('../services/api.js').Handover} */
	const stated = { odo: counter, at_off: -new Date().getTimezoneOffset() }
	if (level.value.trim() !== '') {
		const percent = parseWhole(level.value)
		if (percent === null || percent > 100) {
			throw new Error(t('nextfleet', 'The tank or battery is a percentage, 0 to 100.'))
		}
		stated.level = percent
	}
	if (notes.value.trim() !== '') {
		stated.notes = notes.value
	}

	return stated
}

/**
 * Books from now to the end the sheet asks for. The start is the second the sheet is saved in,
 * which the server's "ends after now" and the check-out's "now before the end" both accept.
 *
 * @return {Promise<import('../services/api.js').Booking>} the booking the car is taken under
 */
async function bookNow() {
	const until = end.value
	if (!(until instanceof Date) || Number.isNaN(until.getTime())) {
		throw new Error(t('nextfleet', 'When will the car be back?'))
	}
	const now = new Date()
	const fault = spanFault(now, until)
	if (fault !== null) {
		throw new Error(fault)
	}

	return createBooking(props.vehicle.uuid, {
		starts_at: Math.floor(now.getTime() / 1000),
		starts_at_off: -now.getTimezoneOffset(),
		ends_at: Math.floor(until.getTime() / 1000),
		ends_at_off: -until.getTimezoneOffset(),
	})
}

/** One attempt. A refusal leaves the sheet open with every value intact (docs/ui.md). */
async function save() {
	saving.value = true
	failure.value = ''
	clash.value = null
	try {
		const stated = handover()
		if (returning.value) {
			emit('saved', await checkIn(props.vehicle.uuid, props.booking, stated))
			return
		}
		held.value ??= await bookNow()
		emit('saved', await checkOut(props.vehicle.uuid, held.value, stated))
	} catch (error) {
		if (error instanceof BookingConflictError) {
			clash.value = error.booking
		} else {
			failure.value = refusalWords(error.message) ?? error.message
		}
	} finally {
		saving.value = false
	}
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
	<NcDialog :name="returning ? t('nextfleet', 'Return the car') : t('nextfleet', 'Take the car')"
		:open="true"
		size="small"
		@update:open="requestClose">
		<div class="sheet" @keydown.esc.stop="requestClose">
			<NcNoteCard v-if="note" type="error" :text="note" />

			<NcDateTimePickerNative v-if="booking === null"
				v-model="end"
				type="datetime-local"
				:label="t('nextfleet', 'Back by')"
				:disabled="saving || held !== null"
				@keydown.esc.stop="keepPicker" />
			<NcTextField v-model="odo"
				:label="t('nextfleet', 'Counter reading ({unit})', { unit: vehicle.odo_unit })"
				:helper-text="taken"
				:disabled="saving"
				inputmode="numeric" />
			<NcTextField v-model="level"
				:label="t('nextfleet', 'Tank or battery (%)')"
				:disabled="saving"
				inputmode="numeric" />
			<NcTextArea v-model="notes"
				:label="t('nextfleet', 'Notes')"
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
