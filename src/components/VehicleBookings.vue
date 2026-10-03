<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { mdiPaperclip } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { computed, ref, watch } from 'vue'

import BookingSheet from './BookingSheet.vue'
import HandoverSheet from './HandoverSheet.vue'
import { cancelBooking, ConflictError, documentUrl, listBookings, readEntry } from '../services/api.js'
import { may } from '../utils/access.js'
import { formatSpan } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/**
	 * The vehicle's papers, as the documents section read them (src/views/VehicleView.vue). Those
	 * linked to a booking are its handover photos; they are added in that section.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Document[]>}
	 */
	papers: { type: Array, default: () => [] },
})

/** Each booking's papers, by its uuid. */
const papersOf = computed(() => {
	/** @type {Map<string, import('../services/api.js').Document[]>} */
	const linked = new Map()
	for (const paper of props.papers) {
		if (paper.linked_type === 'booking' && paper.linked_uuid !== null) {
			linked.set(paper.linked_uuid, [...(linked.get(paper.linked_uuid) ?? []), paper])
		}
	}

	return linked
})

// The entry sheet is the vehicle screen's, as it is for the timeline: `log` asks for the trip of a
// returned booking, `open` hands over the trip a booking became, as a timeline row. `changed` says
// a write may have moved who has the car, which the screen's header reads off the vehicle.
const emit = defineEmits(['log', 'open', 'changed'])

/**
 * The list as the server last answered: from a week ago on, by start. Null until it is read.
 *
 * @type {import('vue').Ref<import('../services/api.js').Booking[]|null>}
 */
const bookings = ref(null)
const failure = ref('')
/** Why the last cancel did not happen; kept across the read that follows it. */
const refusal = ref('')
/** The vehicle's `book` (lib/Db/Vehicle.php); each row's own `may` decides the rest. */
const books = computed(() => may(props.vehicle, 'book'))

/**
 * The sheet: `true` to book, a booking to change it, or null when none is open.
 *
 * @type {import('vue').Ref<import('../services/api.js').Booking|true|null>}
 */
const sheet = ref(null)
/**
 * The handover sheet: a booking to take or give the car back under, `true` to take it now, or
 * null when none is open.
 *
 * @type {import('vue').Ref<import('../services/api.js').Booking|true|null>}
 */
const handover = ref(null)
/** The booking whose cancel is being asked about, by uuid. */
const asking = ref('')
const cancelling = ref(false)

/**
 * Whether a booking is behind the reader: over, and not a car still out past its end, which has not
 * been given back and is what anyone waiting for it plans around.
 *
 * @param {import('../services/api.js').Booking} booking - as listed
 * @return {boolean} it belongs under the last week's
 */
function past(booking) {
	return booking.state !== 'out' && booking.ends_at * 1000 <= Date.now()
}

/** The coming ones first, which are what a driver plans around; groups without any left out. */
const groups = computed(() => [
	{ key: 'coming', title: t('nextfleet', 'Coming'), list: (bookings.value ?? []).filter((one) => !past(one)) },
	{ key: 'past', title: t('nextfleet', 'Last 7 days'), list: (bookings.value ?? []).filter(past) },
].filter((group) => group.list.length > 0))

/**
 * Whether *Take it now* is offered: nobody holds the car this minute, none out, none booked across
 * now. Whether the hours ahead are free is the server's to answer, and the sheet names who holds them.
 */
const free = computed(() => bookings.value !== null && !bookings.value.some((one) => one.state === 'out'
	|| (one.state === 'booked' && one.starts_at * 1000 <= Date.now() && Date.now() < one.ends_at * 1000)))

/** What each of BookingService's flags reads as. */
const FLAG_WORDS = {
	odo_below: t('nextfleet', 'Counter below the one before'),
	late: t('nextfleet', 'Returned after the end'),
}

/** Bumped by each read, so an answer for a vehicle the screen has left is dropped. */
let asked = 0

/** @return {Promise<void>} when the list is in, or the refusal is on screen */
async function load() {
	const question = ++asked
	failure.value = ''
	try {
		const list = await listBookings(props.vehicle.uuid)
		if (question === asked) {
			bookings.value = list
		}
	} catch (error) {
		if (question === asked) {
			failure.value = error.message
		}
	}
}

// The screen reads the list again once the trip of a booking is saved, so the row links it.
defineExpose({ reload: load })

// The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue).
watch(() => props.vehicle.uuid, () => {
	bookings.value = null
	refusal.value = ''
	asking.value = ''
	sheet.value = null
	handover.value = null
	load()
}, { immediate: true })

/**
 * A sheet wrote; the list, ordered and with every `may`, is the server's to answer again. The
 * handover sheet may have written even when closed unsaved: taking it now books before it checks out.
 * A car just given back asks for its trip, prefilled from the handover.
 *
 * @param {import('../services/api.js').Booking} [booking] - what the sheet wrote, when it says
 */
function saved(booking) {
	sheet.value = null
	handover.value = null
	refusal.value = ''
	load()
	emit('changed')
	if (booking !== undefined && may(booking, 'log_trip')) {
		emit('log', booking)
	}
}

/**
 * @param {string} tripUuid - the trip a booking became
 * @return {Promise<void>} when it is handed over, or the refusal is on screen
 */
async function showTrip(tripUuid) {
	refusal.value = ''
	try {
		emit('open', await readEntry(props.vehicle.uuid, 'trip', tripUuid))
	} catch (error) {
		refusal.value = error.message
	}
}

/**
 * @param {import('../services/api.js').Booking} booking - the one to cancel, as read
 * @return {Promise<void>} when it is cancelled, or the refusal is on screen
 */
async function cancel(booking) {
	cancelling.value = true
	refusal.value = ''
	try {
		await cancelBooking(props.vehicle.uuid, booking)
	} catch (error) {
		refusal.value = error instanceof ConflictError
			? t('nextfleet', 'This booking was changed somewhere else. It is shown as it now stands.')
			: t('nextfleet', 'The booking was not cancelled: {reason}', { reason: { value: error.message, escape: false } })
	} finally {
		cancelling.value = false
		asking.value = ''
	}
	emit('changed')
	await load()
}

/**
 * @param {import('../services/api.js').Booking} booking - as listed
 * @return {string} where it stands, in words
 */
function stateWord(booking) {
	/** @type {Record<string, string>} */
	const words = {
		booked: t('nextfleet', 'Booked'),
		out: t('nextfleet', 'Out'),
		returned: t('nextfleet', 'Returned'),
		cancelled: t('nextfleet', 'Cancelled'),
	}

	return words[booking.state] ?? booking.state
}
</script>

<template>
	<section class="bookings">
		<div class="bookings__head">
			<h3>{{ t('nextfleet', 'Bookings') }}</h3>
			<NcButton v-if="books" @click="sheet = true">
				{{ t('nextfleet', 'Book') }}
			</NcButton>
			<NcButton v-if="books && free" @click="handover = true">
				{{ t('nextfleet', 'Take it now') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="failure" type="error" :text="failure" />
		<NcNoteCard v-if="refusal" type="warning" :text="refusal" />
		<NcLoadingIcon v-if="bookings === null && failure === ''" />
		<p v-else-if="bookings !== null && bookings.length === 0" class="bookings__empty">
			{{ books
				? t('nextfleet', 'No bookings. Book the vehicle before you take it, so nobody else plans on it.')
				: t('nextfleet', 'No bookings.') }}
		</p>

		<div v-for="group in groups" :key="group.key" :class="`bookings__${group.key}`">
			<h4 class="bookings__when">
				{{ group.title }}
			</h4>
			<ul class="bookings__list">
				<li v-for="booking in group.list"
					:key="booking.uuid"
					class="bookings__booking"
					:data-booking="booking.uuid">
					<div class="bookings__row">
						<span class="bookings__what">
							<span class="bookings__span">{{ formatSpan(booking.starts_at, booking.starts_at_off, booking.ends_at, booking.ends_at_off) }}</span>
							<span>{{ booking.user_name }}</span>
							<span v-if="booking.purpose" class="bookings__purpose">{{ booking.purpose }}</span>
							<span class="bookings__state">{{ stateWord(booking) }}</span>
							<span v-for="flag in booking.flags ?? []" :key="flag" class="bookings__flag">{{ FLAG_WORDS[flag] ?? flag }}</span>
							<span v-if="booking.trip_voided" class="bookings__state">{{ t('nextfleet', 'Trip voided') }}</span>
							<span v-else-if="booking.trip_uuid && !may(booking, 'open_trip')" class="bookings__state">{{ t('nextfleet', 'Trip logged') }}</span>
						</span>
						<span v-if="asking !== booking.uuid" class="bookings__actions">
							<NcButton v-if="may(booking, 'check_out')" @click="handover = booking">
								{{ t('nextfleet', 'Take the car') }}
							</NcButton>
							<NcButton v-if="may(booking, 'check_in')" @click="handover = booking">
								{{ t('nextfleet', 'Return the car') }}
							</NcButton>
							<NcButton v-if="may(booking, 'log_trip')" @click="emit('log', booking)">
								{{ t('nextfleet', 'Log the trip') }}
							</NcButton>
							<NcButton v-if="may(booking, 'open_trip')" variant="tertiary" @click="showTrip(booking.trip_uuid)">
								{{ t('nextfleet', 'Show the trip') }}
							</NcButton>
							<NcButton v-if="may(booking, 'edit')" variant="tertiary" @click="sheet = booking">
								{{ t('nextfleet', 'Change') }}
							</NcButton>
							<NcButton v-if="may(booking, 'cancel')" variant="tertiary" @click="asking = booking.uuid">
								{{ t('nextfleet', 'Cancel booking') }}
							</NcButton>
						</span>
					</div>
					<span v-if="papersOf.has(booking.uuid)" class="bookings__papers">
						<template v-for="paper in papersOf.get(booking.uuid)" :key="paper.uuid">
							<a v-if="paper.name !== null"
								:href="documentUrl(vehicle.uuid, paper.uuid)"
								:aria-label="t('nextfleet', 'Open {name}', { name: { value: paper.name, escape: false } })"
								:title="paper.name">
								<NcIconSvgWrapper :path="mdiPaperclip" :size="20" />
							</a>
							<span v-else class="bookings__flag">{{ t('nextfleet', 'The file is gone from Files') }}</span>
						</template>
					</span>
					<!-- Asks once: a cancel has no undo, and someone else's booking may be all they planned on. -->
					<div v-if="asking === booking.uuid" class="bookings__question">
						<span>{{ t('nextfleet', 'Cancel this booking?') }}</span>
						<NcButton :disabled="cancelling" @click="asking = ''">
							{{ t('nextfleet', 'Keep it') }}
						</NcButton>
						<NcButton variant="warning" :disabled="cancelling" @click="cancel(booking)">
							{{ t('nextfleet', 'Yes, cancel it') }}
						</NcButton>
					</div>
				</li>
			</ul>
		</div>

		<BookingSheet v-if="sheet !== null"
			:vehicle="vehicle"
			:booking="sheet === true ? null : sheet"
			@close="sheet = null"
			@saved="saved" />
		<HandoverSheet v-if="handover !== null"
			:vehicle="vehicle"
			:booking="handover === true ? null : handover"
			@close="saved"
			@saved="saved" />
	</section>
</template>

<style scoped>
.bookings {
	margin-top: calc(var(--default-grid-baseline) * 4);
}

.bookings__head {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.bookings__empty,
.bookings__when,
.bookings__purpose {
	color: var(--color-text-maxcontrast);
}

.bookings__when {
	margin: calc(var(--default-grid-baseline) * 2) 0 0;
	font-size: 0.9em;
	text-transform: uppercase;
}

.bookings__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.bookings__booking {
	border-bottom: 1px solid var(--color-border);
}

.bookings__row {
	display: flex;
	/* At 320 px the buttons go under the words rather than squeezing them to a word a line. */
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.bookings__what {
	display: flex;
	flex: 1 1 16em;
	flex-wrap: wrap;
	min-width: 0;
	gap: 0 calc(var(--default-grid-baseline) * 2);
	/* A purpose is free text; at 320 px it breaks rather than pushing the page. */
	overflow-wrap: anywhere;
}

.bookings__span {
	font-weight: bold;
}

.bookings__state {
	font-size: 0.9em;
	color: var(--color-text-maxcontrast);
}

.bookings__flag {
	font-size: 0.9em;
	color: var(--color-warning-text);
}

.bookings__actions,
.bookings__question {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.bookings__question {
	padding-bottom: calc(var(--default-grid-baseline) * 2);
}

/* The timeline row's paperclips (TimelineRow.vue), so a paper looks the same wherever it hangs. */
.bookings__papers {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.bookings__papers a {
	display: inline-flex;
	/* A finger's worth of target around a small icon (docs/ui.md). */
	min-width: var(--default-clickable-area);
	min-height: var(--default-clickable-area);
	align-items: center;
	justify-content: center;
	border-radius: var(--border-radius-element, var(--border-radius-large));
}

.bookings__papers a:hover,
.bookings__papers a:focus-visible {
	background-color: var(--color-background-hover);
}
</style>
