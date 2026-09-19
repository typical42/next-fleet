<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, ref, useId, watch } from 'vue'

import { energyPrefill } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { CATEGORIES, categoryWord, energyWord, formatDecimal, parseDecimal, parseWhole } from '../utils/format.js'

const props = defineProps({
	/** @type {import('vue').PropType<import('../services/api.js').Vehicle>} */
	vehicle: { type: Object, required: true },
})

// Two things, because the screen behind needs them apart: `saved` is what happened to the vehicle,
// `close` is what happened to the sheet, and a cancel is only the second.
const emit = defineEmits(['close', 'saved'])

const store = useVehiclesStore()

// A journey is what a logbook is for and what a driver enters daily; the counter on its own is the
// escape hatch for everything not otherwise recorded (docs/ui.md).
const kind = ref('trip')

// `energy_types` decides which energies a fill-up may be of (docs/architecture.md#data-model), so a
// vehicle that names none is offered no fill-up and a diesel is never asked about electricity.
const energies = computed(() => (props.vehicle.energy_types ?? [])
	.map((/** @type {string} */ id) => ({ id, label: energyWord(id) })))

// Which of the two the driver happens to know, and the only thing this chooser decides
// (docs/architecture.md#odometer-rules): the counter the journey ended on, or the kilometres it
// covered. Never both - either would state the end of the journey twice.
const knows = ref('counter')

// Which chain an Odometer Entry reads, asked only of a vehicle that keeps two
// (docs/architecture.md#odometer-rules, rule 4). Kilometres unless the person says otherwise.
const reads = ref('main')
const twoCounters = computed(() => Boolean(props.vehicle.second_unit))

// Prefilled with the counter as it stands and visibly editable, because a driver reads the last
// three digits off the dashboard and not the whole number (docs/ui.md). The numbers stay strings
// on the way through: what is typed is what the sheet keeps when a save comes back refused.
const counter = ref(standing('main'))
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

// A fill-up. The first of the vehicle's energies, because a single-energy vehicle then has nothing
// to choose; the moment is now, because a fill-up is logged at the pump.
const energy = ref(energies.value[0] ?? null)
const filledAt = ref(new Date())
const amount = ref('')
const total = ref('')
const unitPrice = ref('')
const vatRate = ref('')
// On, because it usually is and consumption is measured full to full
// (docs/architecture.md#numbers-consumption-cost-emissions).
const fullTank = ref(true)
const missedPrevious = ref(false)
// Never prefilled, like a trip's counters: the counter at the pump is the one fact consumption is
// measured against, and the vehicle's cached one is not that fact (docs/architecture.md#odometer-rules).
const fillOdo = ref('')
const fillSecond = ref('')
const station = ref('')
// Asked of electricity only, and not answered for the driver: a wall box and a public charger cost
// differently, and a guess would put one's price on the other.
const where = ref('')
const isDc = ref(false)

// What the server offered, and the two values the sheet took from it. A field still holding what
// was prefilled is the server's guess, not the driver's word, which decides what a later prefill
// may overwrite and whether a unit price is sent at all.
/** @type {import('vue').Ref<import('../services/api.js').EnergyPrefill>} */
const prefilled = ref({ vat_rate: null, stations: [] })
const prefilledVat = ref('')
const prefilledPrice = ref('')

const electric = computed(() => energy.value?.id === 'electric')
// The station field completes from this vehicle's own history through a native datalist, which
// the field's input takes by id.
const stationList = useId()
const stations = computed(() => [...new Set(prefilled.value.stations.map((one) => one.station))])

const saving = ref(false)
const failure = ref('')

// A code in the database and a word on screen (docs/ui.md#languages), looked up on render because
// the catalogue is registered by the page and not by this module. The words are the timeline's as
// well, so they are read from the one place that has them (src/utils/format.js).
const categories = computed(() => CATEGORIES.map((id) => ({ id, label: categoryWord(id) })))

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
		await ({ trip, energy: fillUp, odometer: reading })[kind.value]()
		emit('saved')
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
		...(twoCounters.value ? { counter: reads.value } : {}),
		// The clock is the reader's: when they read it, and the offset they read it at
		// (docs/architecture.md#time). The server's own clock is when it heard about it.
		read_at: Math.floor(Date.now() / 1000),
		read_at_off: -new Date().getTimezoneOffset(),
	})
}

/**
 * One fill-up. The amount is the one field it requires (docs/ui.md); everything else left empty is
 * left out, and what that costs the numbers is the server's to flag.
 */
async function fillUp() {
	const complaint = t('nextfleet', 'That is not an amount.')
	const litres = decimal(amount, 3, complaint)
	if (litres === null) {
		throw new Error(complaint)
	}
	const moment = stated(filledAt.value, t('nextfleet', 'A fill-up carries the moment it happened.'))
	const price = t('nextfleet', 'That is not a price.')
	const paid = decimal(total, 2, price)

	await store.fill(props.vehicle.uuid, {
		filled_at: seconds(moment),
		filled_at_off: offset(moment),
		energy: energy.value?.id ?? '',
		full_tank: fullTank.value,
		missed_previous: missedPrevious.value,
		...omitted({
			amount: litres,
			total: paid,
			// A price the station last charged is a guess the total answers better, so it is sent
			// only when the driver typed it or there is no total to derive it from.
			unit_price: paid === null || unitPrice.value !== prefilledPrice.value
				? decimal(unitPrice, 3, price)
				: null,
			vat_rate: decimal(vatRate, 2, t('nextfleet', 'That is not a VAT rate.')),
			odo: whole(fillOdo, t('nextfleet', 'That is not a counter reading.')),
			second_odo: twoCounters.value ? whole(fillSecond, t('nextfleet', 'That is not a counter reading.')) : null,
		}),
		...(station.value.trim() === '' ? {} : { station: station.value.trim() }),
		...(electric.value && where.value !== '' ? { location_kind: where.value } : {}),
		...(electric.value && where.value === 'public' ? { is_dc: isDc.value } : {}),
	})
}

/**
 * What the server prefills a fill-up with, for the moment the sheet is on: the VAT rate changes on
 * a day (lib/Jurisdiction/De/RateProvider.php), so a fill-up dated back is asked about again. A rate
 * the driver typed or cleared is theirs and stays. A prefill that fails leaves the fields empty,
 * which is what they were before it was asked for.
 */
async function prefill() {
	const moment = filledAt.value
	if (kind.value !== 'energy' || !(moment instanceof Date) || Number.isNaN(moment.getTime())) {
		return
	}

	// A date typed digit by digit asks once per digit, and only the last question counts.
	const question = ++asked
	try {
		const answer = await energyPrefill(props.vehicle.uuid, seconds(moment), offset(moment))
		if (question !== asked) {
			return
		}
		prefilled.value = answer
	} catch {
		return
	}

	const rate = prefilled.value.vat_rate === null ? '' : formatDecimal(prefilled.value.vat_rate, 2)
	if (vatRate.value === prefilledVat.value) {
		vatRate.value = rate
	}
	prefilledVat.value = rate
}

let asked = 0
watch([kind, filledAt], prefill)

/**
 * A station the vehicle has filled up at before prefills the price it last charged for this
 * energy (docs/ui.md). The guess belongs to one station and one energy, so it follows both, and
 * where there is none the field empties rather than carry another station's price. A price the
 * driver typed is theirs and stays.
 */
function reprice() {
	if (unitPrice.value !== prefilledPrice.value) {
		return
	}

	const last = prefilled.value.stations
		.find((one) => one.station === station.value.trim() && one.energy === energy.value?.id)
	unitPrice.value = last === undefined || last.unit_price === null ? '' : formatDecimal(last.unit_price, 3)
	prefilledPrice.value = unitPrice.value
}

watch([station, energy, prefilled], reprice)

/**
 * A decimal as somebody typed it, as the integer its column holds - what whole() is for a counter.
 *
 * @param {import('vue').Ref<string>} input - the field
 * @param {number} places - how many decimals the column keeps
 * @param {string} complaint - what to say when it cannot be read
 * @return {number|null} the number, or null for an empty field
 * @throws {Error} when the field says something that is not a number
 */
function decimal(input, places, complaint) {
	if (input.value.trim() === '') {
		return null
	}

	const number = parseDecimal(input.value, places)
	if (number === null) {
		throw new Error(complaint)
	}

	return number
}

/**
 * One journey. What it did to the counter is the server's to work out - a counter it ended on, or
 * a distance counted onto the chain - so the sheet sends the one the driver typed and nothing it
 * computed from it (docs/architecture.md#odometer-rules).
 */
async function trip() {
	const lost = t('nextfleet', 'A trip carries the moment it set off and the moment it arrived.')
	const setOff = stated(departure.value, lost)
	const arrived = stated(arrival.value, lost)

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
 * One moment of an entry. A date field the driver cleared, or half typed, leaves the picker
 * holding null or an invalid date - and an entry with no moment is one no timeline can place, so
 * it is said in the sheet's own words rather than left to what a null does to the arithmetic.
 *
 * @param {Date|null} moment - what a picker holds
 * @param {string} complaint - what to say when it holds none
 * @return {Date} that moment
 * @throws {Error} when the field holds no moment at all
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
 * @return {number} the UTC offset that moment is at, in minutes - the offset of the moment itself
 *   and not of now, so a trip entered in July and dated in January is stated as it was driven
 */
function offset(moment) {
	return -moment.getTimezoneOffset()
}

/**
 * @param {'main'|'second'} chain - the kilometres, or the engine hours beside them
 * @return {string} that counter as a field holds it, or nothing when it was never read
 */
function standing(chain) {
	const value = chain === 'second' ? props.vehicle.second_value : props.vehicle.odo_value

	return value === null || value === undefined ? '' : String(value)
}

/**
 * The other chain, prefilled as it stands. What was typed for the first one is a number on the
 * wrong counter, so it is not kept.
 *
 * @param {'main'|'second'} chain - the counter the person now says they read
 */
function readOther(chain) {
	reads.value = chain
	counter.value = standing(chain)
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
		:size="kind === 'odometer' ? 'small' : 'normal'"
		@update:open="requestClose">
		<!-- NcDialog closes itself on Escape, but through a useHotKey, which passes over every
		     keystroke aimed at a text field - and this sheet opens with the caret in one. So the
		     key is caught where the dialog cannot see it and stopped there, which keeps the
		     mid-save guard on the one way out. An open NcSelect stops it first, so its dropdown
		     still closes on its own. -->
		<div class="sheet"
			:class="{ 'sheet--roomy': kind !== 'odometer' }"
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
				<NcRadioGroupButton v-if="energies.length > 0"
					value="energy"
					:label="t('nextfleet', 'Energy')"
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

			<template v-else-if="kind === 'energy'">
				<NcSelect v-model="energy"
					:options="energies"
					:input-label="t('nextfleet', 'Energy')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<NcDateTimePickerNative v-model="filledAt"
					type="datetime-local"
					:label="t('nextfleet', 'Date')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />

				<!-- A comma or a point is the decimal mark here, unlike in a counter field
				     (src/utils/format.js). -->
				<NcTextField v-model="amount"
					:label="electric ? t('nextfleet', 'Amount (kWh)') : t('nextfleet', 'Amount (l)')"
					:disabled="saving"
					inputmode="decimal"
					autofocus />
				<NcTextField v-model="total"
					:label="t('nextfleet', 'Total price')"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-model="station"
					:label="t('nextfleet', 'Station')"
					:disabled="saving"
					:list="stationList" />
				<datalist :id="stationList">
					<option v-for="name in stations" :key="name" :value="name" />
				</datalist>
				<NcTextField v-model="unitPrice"
					:label="electric ? t('nextfleet', 'Price per kWh') : t('nextfleet', 'Price per litre')"
					:disabled="saving"
					inputmode="decimal" />
				<!-- Clearable to "not stated", which is not zero (docs/architecture.md#data-model). -->
				<NcTextField v-model="vatRate"
					:label="t('nextfleet', 'VAT rate (%)')"
					:disabled="saving"
					inputmode="decimal" />

				<NcTextField v-model="fillOdo"
					:label="t('nextfleet', 'Counter reading')"
					:helper-text="fillOdo.trim() === '' ? t('nextfleet', 'Consumption needs the counter reading.') : ''"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-if="twoCounters"
					v-model="fillSecond"
					:label="t('nextfleet', 'Engine hours')"
					:disabled="saving"
					inputmode="decimal" />

				<template v-if="electric">
					<NcRadioGroup v-model="where"
						class="sheet__wide"
						:label="t('nextfleet', 'Where')">
						<NcRadioGroupButton value="home"
							:label="t('nextfleet', 'Home')"
							:disabled="saving" />
						<NcRadioGroupButton value="public"
							:label="t('nextfleet', 'Public')"
							:disabled="saving" />
					</NcRadioGroup>
					<!-- Direct current only at a public charger: no wall box delivers it. -->
					<NcFormBoxSwitch v-if="where === 'public'"
						v-model="isDc"
						class="sheet__wide"
						:label="t('nextfleet', 'DC fast charging')"
						:disabled="saving" />
				</template>

				<NcFormBoxSwitch v-model="fullTank"
					class="sheet__wide"
					:label="electric ? t('nextfleet', 'Charged to full') : t('nextfleet', 'Full tank')"
					:disabled="saving" />
				<NcFormBoxSwitch v-model="missedPrevious"
					class="sheet__wide"
					:label="t('nextfleet', 'Missed the previous one')"
					:description="t('nextfleet', 'A fill-up went unrecorded before this one.')"
					:disabled="saving" />
			</template>

			<template v-else>
				<NcRadioGroup v-if="twoCounters"
					:model-value="reads"
					:label="t('nextfleet', 'Which counter')"
					@update:model-value="readOther">
					<NcRadioGroupButton value="main"
						:label="t('nextfleet', 'Kilometres')"
						:disabled="saving" />
					<NcRadioGroupButton value="second"
						:label="t('nextfleet', 'Engine hours')"
						:disabled="saving" />
				</NcRadioGroup>
				<NcTextField v-model="counter"
					:label="t('nextfleet', 'Counter reading')"
					:disabled="saving"
					inputmode="decimal"
					autofocus />
			</template>
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
