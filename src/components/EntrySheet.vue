<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref } from 'vue'

import { useVehiclesStore } from '../store/index.js'
import { parseWhole } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

const emit = defineEmits(['close'])

const store = useVehiclesStore()

// A journey is what a logbook is for and what a driver enters daily; the counter on its own is the
// escape hatch for everything not otherwise recorded (docs/ui.md). Energy and maintenance are the
// two kinds this chooser grows in M3.
const kind = ref('trip')

// Which of the two the driver happens to know, and the only thing this chooser decides
// (docs/architecture.md#odometer-rules): the counter the journey ended on, or the kilometres it
// covered. Never both - either would state the end of the journey twice.
const knows = ref('counter')

// Prefilled with the counter as it stands and visibly editable, because a driver reads the last
// three digits off the dashboard and not the whole number (docs/ui.md). The numbers stay strings
// on the way through: what is typed is what the sheet keeps when a save comes back refused.
const counter = ref(standing())
// A trip's counters are the one thing the sheet does not prefill: `start_odo` is a claim about
// what the dashboard read when the journey set off (docs/architecture.md#odometer-rules), and the
// vehicle's own counter is not that claim. Filling it in would answer the question gap detection
// exists to ask, and it would answer it wrong for every kilometre nobody logged.
const startOdo = ref('')
const endOdo = ref('')
const distance = ref('')
// The moment is the driver's, and the offset it was entered at travels with it
// (docs/architecture.md#time). Now, because a trip is logged when it is over.
const departure = ref(new Date())
const arrival = ref(new Date())
const fromLabel = ref('')
const toLabel = ref('')
const purpose = ref('')
const partner = ref('')

const saving = ref(false)
const failure = ref('')

// A code in the database and a word on screen (docs/ui.md#languages), looked up on render because
// the catalogue is registered by the page and not by this module.
const categories = computed(() => [
	{ id: 'business', label: t('nextfleet', 'Business') },
	{ id: 'private', label: t('nextfleet', 'Private') },
	{ id: 'commute', label: t('nextfleet', 'Commute') },
])

// The category the logbook exists for: a business trip is the one Germany asks the questions about
// (docs/features.md#logbook-mode), so it is the one the sheet offers to answer them for.
const category = ref(categories.value[0])

/**
 * Records what the sheet is on. A refusal leaves it open with every value intact and offers the
 * retry - nothing is written anywhere else, because the open sheet is the queue (docs/ui.md).
 */
async function save() {
	saving.value = true
	failure.value = ''
	try {
		await (kind.value === 'trip' ? trip() : reading())
		emit('close')
	} catch (error) {
		failure.value = error.message
	} finally {
		saving.value = false
	}
}

/** The escape hatch: one number, at the moment it was read. */
async function reading() {
	const complaint = t('nextfleet', 'That is not a counter reading.')
	// An Odometer Entry is the number and nothing else (CONTEXT.md), so an empty field is not a
	// question left open for the timeline to ask - it is nothing to record.
	const value = whole(counter, complaint)
	if (value === null) {
		throw new Error(complaint)
	}

	await store.record(props.vehicle.uuid, {
		value,
		// The clock is the reader's: when they read it, and the offset they read it at
		// (docs/architecture.md#time). The server's own clock is when it heard about it.
		read_at: Math.floor(Date.now() / 1000),
		read_at_off: -new Date().getTimezoneOffset(),
	})
}

/**
 * One journey. What it did to the counter is the server's to work out - a counter it ended on, or
 * a distance counted onto the chain - so the sheet sends the one the driver typed and nothing it
 * computed from it (docs/architecture.md#odometer-rules).
 */
async function trip() {
	const setOff = stated(departure.value)
	const arrived = stated(arrival.value)

	await store.log(props.vehicle.uuid, {
		started_at: seconds(setOff),
		started_at_off: offset(setOff),
		ended_at: seconds(arrived),
		ended_at_off: offset(arrived),
		category: category.value?.id ?? '',
		from_label: fromLabel.value,
		to_label: toLabel.value,
		purpose: purpose.value,
		partner: partner.value,
		...counted(),
	})
}

/**
 * The kilometres, as the toggle asks for them. A distance leaves the counter it set off on unsaid
 * (docs/architecture.md#odometer-rules): the claim and the distance are two different facts, and
 * the driver who knows neither is only asked for one.
 *
 * @return {Record<string, number>} the fields that state what the journey covered
 */
function counted() {
	if (knows.value === 'distance') {
		return omitted({ distance: whole(distance, t('nextfleet', 'That is not a distance.')) })
	}

	return omitted({
		start_odo: whole(startOdo, t('nextfleet', 'That is not a counter reading.')),
		end_odo: whole(endOdo, t('nextfleet', 'That is not a counter reading.')),
	})
}

/**
 * A number as somebody typed it. A field nobody can read is a question for the driver, and asking
 * it here saves them a round trip that would come back in the server's own words. An empty field is
 * a question they did not answer, and what that means is the caller's to decide.
 *
 * @param {import('vue').Ref<string>} input - the field
 * @param {string} complaint - what to say when it cannot be read
 * @return {number|null} the number, or null for an empty field
 * @throws {Error} when the field says something that is not a number
 */
function whole(input, complaint) {
	if (input.value.trim() === '') {
		return null
	}

	const number = parseWhole(input.value)
	if (number === null) {
		throw new Error(complaint)
	}

	return number
}

/**
 * @param {Record<string, number|null>} fields - the numbers, as far as they were typed
 * @return {Record<string, number>} the ones that were - an absent field and an empty one are the
 *   same fact to the server, and leaving it out says so without the client asserting a null
 */
function omitted(fields) {
	return Object.fromEntries(Object.entries(fields).filter(([, value]) => value !== null))
}

/**
 * One moment of a journey. A date field the driver cleared, or half typed, leaves the picker
 * holding null or an invalid date - and a journey with no moment is one no logbook can place, so
 * it is said in the sheet's own words rather than left to what a null does to the arithmetic.
 *
 * @param {Date|null} moment - what a picker holds
 * @return {Date} that moment
 * @throws {Error} when the field holds no moment at all
 */
function stated(moment) {
	if (!(moment instanceof Date) || Number.isNaN(moment.getTime())) {
		throw new Error(t('nextfleet', 'A trip carries the moment it set off and the moment it arrived.'))
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
 * @return {number} the UTC offset that moment is at, in minutes - the offset of the moment itself
 *   and not of now, so a trip entered in July and dated in January is stated as it was driven
 */
function offset(moment) {
	return -moment.getTimezoneOffset()
}

/**
 * @return {string} the vehicle's counter as a field holds it, or nothing when it was never read
 */
function standing() {
	const value = props.vehicle.odo_value

	return value === null || value === undefined ? '' : String(value)
}

/**
 * The Escape a native date picker is dismissed with. The browser draws that picker over the input
 * and closes it on the key, but the keydown reaches the input all the same - so without the `.stop`
 * this handler is here to carry, backing out of a calendar would close the sheet over everything
 * that has been typed into it (src/components/VehicleSheet.vue).
 */
function keepPicker() {}

/** A sheet that is mid-save has values nobody has an answer for yet, so it does not close. */
function requestClose() {
	if (!saving.value) {
		emit('close')
	}
}
</script>

<template>
	<NcDialog :name="t('nextfleet', 'New entry')"
		:open="true"
		:size="kind === 'trip' ? 'normal' : 'small'"
		@update:open="requestClose">
		<!-- NcDialog closes itself on Escape, but through a useHotKey, which passes over every
		     keystroke aimed at a text field - and this sheet opens with the caret in one. So the
		     key is caught where the dialog cannot see it and stopped there, which keeps the
		     mid-save guard on the one way out. An open NcSelect stops it first, so its dropdown
		     still closes on its own. -->
		<div class="sheet"
			:class="{ 'sheet--roomy': kind === 'trip' }"
			@keydown.esc.stop="requestClose">
			<NcNoteCard v-if="failure"
				class="sheet__wide"
				type="error"
				:text="failure" />

			<NcRadioGroup v-model="kind"
				class="sheet__wide"
				:label="t('nextfleet', 'Entry type')">
				<NcRadioGroupButton value="trip"
					:label="t('nextfleet', 'Trip')"
					:disabled="saving" />
				<NcRadioGroupButton value="odometer"
					:label="t('nextfleet', 'Odometer')"
					:disabled="saving" />
			</NcRadioGroup>

			<template v-if="kind === 'trip'">
				<NcDateTimePickerNative v-model="departure"
					type="datetime-local"
					:label="t('nextfleet', 'Departure')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />
				<NcDateTimePickerNative v-model="arrival"
					type="datetime-local"
					:label="t('nextfleet', 'Arrival')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />

				<NcRadioGroup v-model="knows"
					class="sheet__wide"
					:label="t('nextfleet', 'Counter or distance')">
					<NcRadioGroupButton value="counter"
						:label="t('nextfleet', 'Counter')"
						:disabled="saving" />
					<NcRadioGroupButton value="distance"
						:label="t('nextfleet', 'Distance')"
						:disabled="saving" />
				</NcRadioGroup>

				<!-- Both fields read in whole kilometres or whole hours, and a comma or a point in
				     one of them groups thousands - which is what the app wrote out a moment
				     earlier (docs/ui.md#languages). -->
				<template v-if="knows === 'counter'">
					<NcTextField v-model="startOdo"
						:label="t('nextfleet', 'Start counter')"
						:disabled="saving"
						inputmode="decimal" />
					<NcTextField v-model="endOdo"
						:label="t('nextfleet', 'End counter')"
						:disabled="saving"
						inputmode="decimal"
						autofocus />
				</template>
				<NcTextField v-else
					v-model="distance"
					:label="t('nextfleet', 'Distance')"
					:disabled="saving"
					inputmode="decimal"
					autofocus />

				<NcSelect v-model="category"
					:options="categories"
					:input-label="t('nextfleet', 'Category')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<NcTextField v-model="purpose"
					:label="t('nextfleet', 'Purpose')"
					:disabled="saving" />
				<NcTextField v-model="fromLabel"
					:label="t('nextfleet', 'Starting point')"
					:disabled="saving" />
				<NcTextField v-model="toLabel"
					:label="t('nextfleet', 'Destination')"
					:disabled="saving" />
				<NcTextField v-model="partner"
					class="sheet__wide"
					:label="t('nextfleet', 'Business partner')"
					:disabled="saving" />
			</template>

			<NcTextField v-else
				v-model="counter"
				:label="t('nextfleet', 'Counter reading')"
				:disabled="saving"
				inputmode="decimal"
				autofocus />
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving" @click="save">
				{{ failure ? t('nextfleet', 'Try again') : t('nextfleet', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<style scoped>
.sheet {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

/* One column on a phone; two once there is room, so a trip is not ten screens. */
@media (min-width: 480px) {
	.sheet--roomy {
		grid-template-columns: 1fr 1fr;
	}

	.sheet--roomy .sheet__wide {
		grid-column: 1 / -1;
	}
}
</style>
