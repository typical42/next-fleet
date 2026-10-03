<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import { useHotKey } from '@nextcloud/vue/composables/useHotKey'
import { computed, ref, watch } from 'vue'

import DueBanner from '../components/DueBanner.vue'
import EntrySheet from '../components/EntrySheet.vue'
import ImportSheet from '../components/ImportSheet.vue'
import KpiHeader from '../components/KpiHeader.vue'
import LeaveVehicle from '../components/LeaveVehicle.vue'
import Timeline from '../components/Timeline.vue'
import VehicleBookings from '../components/VehicleBookings.vue'
import VehicleDocuments from '../components/VehicleDocuments.vue'
import VehicleSheet from '../components/VehicleSheet.vue'
import VehicleSticker from '../components/VehicleSticker.vue'
import { useVehiclesStore } from '../store/index.js'
import { may } from '../utils/access.js'
import { nameOf, subtitleOf } from '../utils/format.js'
import { holderWords, nextBookingWords } from '../utils/pool.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
	/** Whether the screen opens with the entry sheet up, as the QR sticker's link asks. */
	enter: { type: Boolean, default: false },
})

defineEmits(['costs'])

const store = useVehiclesStore()
const logs = computed(() => may(props.vehicle, 'log'))
// Overdue is read off the clock whenever the vehicle is read again, as after any booking write.
const holder = computed(() => holderWords(props.vehicle))
// Read once: the sticker asks for one sheet on arrival, not for one whenever the flag is set. A
// viewer who scanned it lands on the screen without one.
const entering = ref(props.enter && logs.value)
const editing = ref(false)
const importing = ref(false)
/**
 * The timeline row the sheet is open on, or null.
 *
 * @type {import('vue').Ref<import('../services/api.js').Entry|null>}
 */
const opened = ref(null)
/**
 * The reminder a new Maintenance Record is to close, from "Done" in the due banner, or null.
 *
 * @type {import('vue').Ref<string|null>}
 */
const closing = ref(null)
/**
 * The returned booking whose trip is being logged, from the Bookings section, or null.
 *
 * @type {import('vue').Ref<import('../services/api.js').Booking|null>}
 */
const logging = ref(null)
/**
 * The vehicle's documents as its section last read them; the timeline shows the linked ones.
 *
 * @type {import('vue').Ref<import('../services/api.js').Document[]>}
 */
const papers = ref([])

// The timeline holds its own pages and its own chip, the header its own period, and neither is
// this screen's business - what is, is that a write happened and both are now a row behind.
const timeline = ref(null)
const kpis = ref(null)
const bookings = ref(null)

/**
 * Reads the list and the figures back after a write, and the bookings: a trip logged, voided or
 * restored changes what its booking's row says.
 */
function written() {
	timeline.value?.reload()
	kpis.value?.reload()
	bookings.value?.reload()
}

// The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue); a sheet on the
// last one's booking would log its trip onto this one.
watch(() => props.vehicle.uuid, () => {
	logging.value = null
})

// `n` is the primary action of the screen in view (docs/ui.md), and the shell mounts one screen
// at a time - so the key belongs to the screen rather than to an arbiter above it. useHotKey
// already passes over a keystroke typed into a field or aimed at an open sheet, and drops the
// listener when the screen goes.
useHotKey('n', () => {
	if (logs.value) {
		entering.value = true
	}
})
</script>

<template>
	<div class="vehicle">
		<!-- One primary button per screen, and on this screen it is the entry (docs/ui.md). -->
		<div class="vehicle__header">
			<div>
				<h2>{{ nameOf(vehicle) }}</h2>
				<p class="vehicle__subtitle">
					{{ subtitleOf(vehicle) }}
				</p>
				<p v-if="holder" class="vehicle__holder" :class="{ 'vehicle__holder--overdue': holder.overdue }">
					{{ holder.words }}
				</p>
				<p v-if="nextBookingWords(vehicle)" class="vehicle__next">
					{{ nextBookingWords(vehicle) }}
				</p>
				<!-- Under the name rather than among the actions: it is about whose car this is. -->
				<LeaveVehicle :vehicle="vehicle" />
			</div>
			<div class="vehicle__actions">
				<NcButton v-if="logs" variant="primary" @click="entering = true">
					{{ t('nextfleet', 'New entry') }}
				</NcButton>
				<!-- The shell swaps the screen (src/App.vue). -->
				<NcButton @click="$emit('costs')">
					{{ t('nextfleet', 'Costs') }}
				</NcButton>
				<NcButton v-if="may(vehicle, 'edit')" @click="editing = true">
					{{ t('nextfleet', 'Edit vehicle') }}
				</NcButton>
				<!-- The sticker's link opens the entry sheet, which is no use to somebody who adds nothing. -->
				<VehicleSticker v-if="logs" :vehicle="vehicle" />
			</div>
		</div>

		<KpiHeader ref="kpis" :vehicle="vehicle" />

		<DueBanner :vehicle="vehicle" @done="closing = $event" />

		<VehicleBookings ref="bookings"
			:vehicle="vehicle"
			:papers="papers"
			@changed="store.refresh(vehicle.uuid)"
			@log="logging = $event"
			@open="opened = $event" />

		<!-- Above the timeline, which scrolls on without end. -->
		<VehicleDocuments :vehicle="vehicle" @listed="papers = $event" />

		<!-- One timeline of everything that happened to this vehicle, which is the question people
		     actually ask (docs/ui.md). -->
		<Timeline ref="timeline"
			:vehicle="vehicle"
			:papers="papers"
			@open="opened = $event" />

		<EntrySheet v-if="entering"
			:vehicle="vehicle"
			@close="entering = false"
			@saved="written" />
		<EntrySheet v-if="closing"
			:vehicle="vehicle"
			:closes="closing"
			@close="closing = null"
			@saved="written" />
		<EntrySheet v-if="logging"
			:vehicle="vehicle"
			:booking="logging"
			@close="logging = null"
			@saved="written" />
		<EntrySheet v-if="opened"
			:vehicle="vehicle"
			:entry="opened"
			@close="opened = null"
			@saved="written" />
		<!-- A save is done with, so the sheet goes: what it wrote is in the store this screen
		     reads, and a sheet still open would be a second copy of the same vehicle. -->
		<VehicleSheet v-if="editing"
			:vehicle="vehicle"
			@close="editing = false"
			@import="editing = false; importing = true"
			@saved="editing = false" />
		<!-- In the vehicle sheet's place rather than over it: one dialog, one way out. Its result
		     and its undo are the toast's (src/components/UndoToast.vue). -->
		<ImportSheet v-if="importing"
			:vehicle="vehicle"
			@close="importing = false"
			@imported="importing = false; written()" />
	</div>
</template>

<style scoped>
.vehicle {
	padding: calc(var(--default-grid-baseline) * 4);
}

.vehicle__header {
	display: flex;
	/* The buttons go under the name when both do not fit, rather than squeezing it to a word a line. */
	flex-wrap: wrap;
	align-items: start;
	justify-content: space-between;
	gap: calc(var(--default-grid-baseline) * 2);
}

.vehicle__actions {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
}

.vehicle__subtitle {
	color: var(--color-text-maxcontrast);
}

/* Text, so the text token, as on the overview. */
.vehicle__holder--overdue {
	color: var(--color-warning-text);
	font-weight: bold;
}
</style>
