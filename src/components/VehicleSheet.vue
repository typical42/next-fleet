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
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref } from 'vue'

import { ConflictError, getPreferences, getVehicle } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { formatDay, jurisdictionWord, lifecycleWord, parseDay, parseWhole } from '../utils/format.js'

const props = defineProps({
	/**
	 * The vehicle to edit. Absent, the sheet creates one and asks for the four fields ui.md
	 * names; present, it edits every column a request may write.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle|null>}
	 */
	vehicle: { type: Object, default: null },
})

const emit = defineEmits(['close', 'created', 'saved'])

const store = useVehiclesStore()

const editing = computed(() => props.vehicle !== null)

// One ref per writable column (lib/Service/VehicleService.php WRITABLE), each prefilled from the
// vehicle and visibly editable (docs/ui.md). The numbers stay strings on the way through: the
// server judges them, and a sheet that refuses a field first would block on validation.
const plate = ref(text(props.vehicle?.plate))
const manufacturer = ref(text(props.vehicle?.manufacturer))
const model = ref(text(props.vehicle?.model))
const vin = ref(text(props.vehicle?.vin))
const tankMl = ref(text(props.vehicle?.tank_ml))
const batteryWh = ref(text(props.vehicle?.battery_wh))
const purchasePrice = ref(text(props.vehicle?.purchase_price))
const residualEst = ref(text(props.vehicle?.residual_est))
const currency = ref(text(props.vehicle?.currency))
const retentionMonths = ref(text(props.vehicle?.retention_months))
const color = ref(text(props.vehicle?.color))
const notes = ref(text(props.vehicle?.notes))
const firstReg = ref(parseDay(props.vehicle?.first_reg))
const disposedAt = ref(parseDay(props.vehicle?.disposed_at))
const counter = ref('')

const saving = ref(false)
const failure = ref('')
/**
 * The vehicle this sheet writes against - the prop until a refused write reads a newer one. The
 * token travels from here rather than from the prop, which never moves: a retry that kept sending
 * the token the sheet was opened with would be refused for the same reason forever.
 *
 * @type {import('vue').Ref<import('../services/api.js').Vehicle|null>}
 */
const held = ref(props.vehicle)
/**
 * The write that was refused because the row moved on (docs/architecture.md#concurrency), or null.
 * Latched rather than derived from `failure`, because it says what the *next* attempt has to do:
 * read the vehicle back first. It clears when that read has happened, and is set again if the
 * write then loses a second race. Which write it was decides only what the message says - both
 * buttons take the same way out.
 *
 * @type {import('vue').Ref<'save'|'delete'|null>}
 */
const refused = ref(null)
/**
 * The vehicle, once it exists. A create sheet asks for two writes - the vehicle and its first
 * Reading - and only the second one may fail on its own, so the first is never repeated.
 *
 * @type {import('vue').Ref<import('../services/api.js').Vehicle|null>}
 */
const created = ref(null)

/** The countries the server registers, read once the sheet is up. @type {import('vue').Ref<{ key: string, name: string }[]>} */
const countries = ref([])

// A code in the database and a word on screen (docs/ui.md#languages), looked up on render because
// the catalogue is registered by the page and not by this module.
const types = computed(() => [
	{ id: 'car', label: t('nextfleet', 'Car') },
	{ id: 'van', label: t('nextfleet', 'Van') },
	{ id: 'trailer', label: t('nextfleet', 'Trailer') },
	{ id: 'tractor', label: t('nextfleet', 'Tractor') },
	{ id: 'generator', label: t('nextfleet', 'Generator') },
])

const engines = computed(() => [
	{ id: 'petrol', label: t('nextfleet', 'Petrol') },
	{ id: 'diesel', label: t('nextfleet', 'Diesel') },
	{ id: 'lpg', label: t('nextfleet', 'LPG') },
	{ id: 'cng', label: t('nextfleet', 'CNG') },
	{ id: 'electric', label: t('nextfleet', 'Electric') },
	{ id: 'hybrid', label: t('nextfleet', 'Hybrid') },
])

// An Engine classifies the drivetrain; Energy Types is the set the vehicle actually accepts, and
// hybrid is not one of them - a plug-in hybrid is `hybrid` / `[petrol, electric]` (CONTEXT.md).
const energies = computed(() => engines.value.filter((one) => one.id !== 'hybrid'))

// Kilometres or engine hours: a tractor counts neither in km nor in miles, and miles are a
// rendering of kilometres rather than a unit a vehicle is kept in (docs/contributing.md).
const units = computed(() => [
	{ id: 'km', label: t('nextfleet', 'Kilometres') },
	{ id: 'h', label: t('nextfleet', 'Engine hours') },
])

const lifecycles = computed(() => ['active', 'laid_up', 'disposed']
	.map((id) => ({ id, label: lifecycleWord(id) })))

const jurisdictions = computed(() => countries.value
	.map(({ key, name }) => ({ id: key, label: jurisdictionWord(key, name) })))

const vehicleType = ref(chosen(types.value, props.vehicle?.vehicle_type ?? 'car'))
const engine = ref(chosen(engines.value, props.vehicle?.engine))
const energyTypes = ref((props.vehicle?.energy_types ?? [])
	.map((/** @type {string} */ id) => chosen(energies.value, id))
	.filter(Boolean))
const odoUnit = ref(chosen(units.value, props.vehicle?.odo_unit ?? 'km'))
const lifecycle = ref(chosen(lifecycles.value, props.vehicle?.lifecycle ?? 'active'))
const jurisdiction = ref(text(props.vehicle?.jurisdiction))

// The disposal day is a fact about a disposed vehicle and about no other, so it appears with that
// lifecycle and is written away again with any other one - see fields().
const disposing = computed(() => lifecycle.value?.id === 'disposed')

const country = computed(() => jurisdictions.value.find((one) => one.id === jurisdiction.value) ?? null)

// A button says what the click does, not what went wrong: while the token is stale this click
// overwrites somebody's change - whichever write was refused, and whether or not the last attempt
// failed for a second reason on top.
const action = computed(() => {
	if (refused.value !== null) {
		return t('nextfleet', 'Save anyway')
	}

	if (failure.value) {
		return t('nextfleet', 'Try again')
	}

	return editing.value ? t('nextfleet', 'Save') : t('nextfleet', 'Add vehicle')
})

// The message and its colour are one decision, so they are computed together: an error is
// something the user has to answer for, a warning is something that cost them nothing.
const note = computed(() => {
	// Which of the two writes failed decides what a create sheet has to say, because only one of
	// them can be repeated: the vehicle is already there.
	if (failure.value) {
		return created.value
			? { type: 'warning', text: t('nextfleet', 'The vehicle was added, but its counter reading was not: {reason}', { reason: failure.value }) }
			: { type: 'error', text: failure.value }
	}

	// The server's own words are English and name a column; this says what happened to the person
	// who typed, and what the click they are about to repeat now does - which is not the same
	// sentence for a save as for a delete.
	if (refused.value === 'save') {
		return { type: 'warning', text: t('nextfleet', 'This vehicle was changed somewhere else while you had it open. Saving again writes your values over that change.') }
	}

	if (refused.value === 'delete') {
		return { type: 'warning', text: t('nextfleet', 'This vehicle was changed somewhere else while you had it open. Deleting again removes it as it now stands.') }
	}

	return { type: 'error', text: '' }
})

/**
 * The registration list an edit picks a country from. It is read from the settings route because
 * the app has one API surface (docs/adr/0006-one-api-surface-in-v1.md), and the vehicle's own key
 * is added to whatever comes back: a country a later release stopped offering is still the one
 * this vehicle is kept under, and the dropdown may not quietly hide it.
 */
onMounted(async () => {
	if (!editing.value) {
		return
	}

	try {
		countries.value = (await getPreferences()).jurisdictions
	} catch {
		// The list is a convenience; a sheet that cannot offer the others still edits this
		// vehicle, and the country it already has is below.
	}

	const own = jurisdiction.value
	if (own !== '' && !countries.value.some((one) => one.key === own)) {
		countries.value = [...countries.value, { key: own, name: own }]
	}
})

/**
 * Every writable column, as the API spells it. Sent whole rather than as a diff: `apply()` writes
 * what the payload names, so a field the user emptied has to travel as an empty one to be cleared.
 *
 * @return {Record<string, unknown>} the vehicle's new state
 */
function fields() {
	return {
		plate: plate.value,
		manufacturer: manufacturer.value,
		model: model.value,
		vehicle_type: vehicleType.value?.id ?? '',
		engine: engine.value?.id ?? '',
		energy_types: energyTypes.value.map((/** @type {{ id: string }} */ one) => one.id),
		tank_ml: tankMl.value,
		battery_wh: batteryWh.value,
		first_reg: formatDay(firstReg.value),
		// Cleared here rather than by a watcher, so that toggling the lifecycle twice does not
		// throw away a day the user typed in between.
		disposed_at: disposing.value ? formatDay(disposedAt.value) : '',
		vin: vin.value,
		odo_unit: odoUnit.value?.id ?? '',
		purchase_price: purchasePrice.value,
		residual_est: residualEst.value,
		currency: currency.value,
		jurisdiction: jurisdiction.value,
		lifecycle: lifecycle.value?.id ?? '',
		retention_months: retentionMonths.value,
		color: color.value,
		notes: notes.value,
	}
}

/**
 * One attempt at a write this sheet asks for. A failure leaves the sheet open with every value
 * intact and offers the retry - the open sheet is the queue (docs/ui.md).
 *
 * @param {() => Promise<void>} work - the write to try
 * @param {'save'|'delete'} kind - which write it is, for the message a refusal gets
 */
async function attempt(work, kind) {
	saving.value = true
	failure.value = ''
	try {
		await work()
	} catch (error) {
		if (error instanceof ConflictError) {
			refused.value = kind
		} else {
			failure.value = error.message
		}
	} finally {
		saving.value = false
	}
}

/** The write the sheet is for: the edit, or the create it was opened without a vehicle for. */
function save() {
	return attempt(editing.value ? write : add, 'save')
}

/** Nothing asks "are you sure?": the way back is the undo toast the screen shows (docs/ui.md). */
function remove() {
	return attempt(erase, 'delete')
}

/**
 * The vehicle the next write is checked against. After a refused one it is read back first, so
 * the attempt goes out under the token that is actually current.
 *
 * @return {Promise<import('../services/api.js').Vehicle>} the vehicle as the server now holds it
 */
async function current() {
	if (refused.value !== null) {
		// Read for this sheet alone rather than through the store: what comes back may be a
		// vehicle the overview no longer lists - somebody else may have disposed of it - and
		// putting that in the store would take this screen away from under the open sheet
		// (src/App.vue). The write right after it is what the store hears about.
		held.value = await getVehicle(held.value.uuid)
		refused.value = null
	}

	return held.value
}

/**
 * The edit: one write, checked against the `updated_at` the vehicle was read with. What is on
 * screen wins, only the version it is written against changes.
 */
async function write() {
	emit('saved', await store.save({ ...await current(), ...fields() }))
}

/**
 * The delete. It emits nothing and closes nothing: the vehicle leaves the fleet, which takes this
 * sheet and the screen under it with it (src/App.vue). What is left of the vehicle is the way back
 * the store holds, and the toast that offers it (src/components/UndoToast.vue).
 */
async function erase() {
	await store.remove(await current())
}

/** The create: four fields, then the counter as a first Reading. */
async function add() {
	// The counter is optional and it is judged before anything is written: a field nobody can
	// read is a question for the driver, not a vehicle created with the answer thrown away.
	const number = counter.value.trim() === '' ? null : parseWhole(counter.value)
	if (counter.value.trim() !== '' && number === null) {
		throw new Error(t('nextfleet', 'That is not a counter reading.'))
	}

	if (created.value === null) {
		created.value = await store.create({
			plate: plate.value,
			manufacturer: manufacturer.value,
			model: model.value,
			engine: engine.value?.id ?? '',
		})
	}

	if (number !== null) {
		await store.record(created.value.uuid, {
			value: number,
			read_at: Math.floor(Date.now() / 1000),
			read_at_off: -new Date().getTimezoneOffset(),
		})
	}

	emit('created', created.value)
}

/** A sheet that is mid-save has values nobody has an answer for yet, so it does not close. */
function requestClose() {
	if (!saving.value) {
		emit('close')
	}
}

/**
 * @param {unknown} value - a column as the API stated it
 * @return {string} it as a field holds it; an absent column is an empty field
 */
function text(value) {
	return value === null || value === undefined ? '' : String(value)
}

/**
 * @param {{ id: string, label: string }[]} options - what the dropdown offers
 * @param {string|null|undefined} id - the code the vehicle carries
 * @return {{ id: string, label: string }|null} the option for it, or nothing
 */
function chosen(options, id) {
	return options.find((one) => one.id === id) ?? null
}
</script>

<template>
	<NcDialog :name="editing ? t('nextfleet', 'Edit vehicle') : t('nextfleet', 'New vehicle')"
		:open="true"
		:size="editing ? 'normal' : 'small'"
		@update:open="requestClose">
		<!-- NcDialog closes itself on Escape, but through a useHotKey, which passes over every
		     keystroke aimed at a text field - and this sheet opens with the caret in one. So the
		     key is caught where the dialog cannot see it and stopped there, which keeps the
		     mid-save guard on the one way out. An open NcSelect stops it first, so its dropdown
		     still closes on its own. -->
		<div class="sheet"
			:class="{ 'sheet--roomy': editing }"
			@keydown.esc.stop="requestClose">
			<NcNoteCard v-if="note.text"
				class="sheet__wide"
				:type="note.type"
				:text="note.text" />

			<!-- Creating asks for four fields, not twelve: the rest arrives through the vehicle's
			     own edit sheet and the hint that points at it (docs/ui.md). -->
			<NcTextField v-model="plate"
				:label="t('nextfleet', 'Registration plate')"
				:disabled="saving || !!created"
				autofocus />
			<NcTextField v-model="manufacturer"
				:label="t('nextfleet', 'Manufacturer')"
				:disabled="saving || !!created" />
			<NcTextField v-model="model"
				:label="t('nextfleet', 'Model')"
				:disabled="saving || !!created" />
			<NcSelect v-model="engine"
				:options="engines"
				:input-label="t('nextfleet', 'Engine')"
				:disabled="saving || !!created"
				label="label" />
			<NcTextField v-if="!editing"
				v-model="counter"
				:label="t('nextfleet', 'Counter reading')"
				:disabled="saving"
				inputmode="decimal" />

			<template v-if="editing">
				<NcSelect v-model="vehicleType"
					:options="types"
					:input-label="t('nextfleet', 'Vehicle type')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<NcSelect v-model="energyTypes"
					:options="energies"
					:input-label="t('nextfleet', 'Energy types')"
					:disabled="saving"
					:multiple="true"
					label="label" />
				<NcTextField v-model="vin"
					:label="t('nextfleet', 'VIN')"
					:disabled="saving" />
				<NcDateTimePickerNative v-model="firstReg"
					type="date"
					:label="t('nextfleet', 'First registration')" />
				<NcSelect v-model="odoUnit"
					:options="units"
					:input-label="t('nextfleet', 'Counter unit')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<!-- Integers in the units the database keeps: cents, millilitres, watt-hours
				     (docs/contributing.md). The label says which, because nothing converts yet. -->
				<NcTextField v-model="tankMl"
					:label="t('nextfleet', 'Tank size (ml)')"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="batteryWh"
					:label="t('nextfleet', 'Battery capacity (Wh)')"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="purchasePrice"
					:label="t('nextfleet', 'Purchase price (cents)')"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="residualEst"
					:label="t('nextfleet', 'Residual value (cents)')"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="currency"
					:label="t('nextfleet', 'Currency')"
					:disabled="saving"
					maxlength="3" />
				<!-- The jurisdiction is not a fifth create field: it defaults from the personal
				     setting and is changed here until the sidebar exists (docs/ui.md). -->
				<NcSelect :model-value="country"
					:options="jurisdictions"
					:input-label="t('nextfleet', 'Jurisdiction')"
					:disabled="saving"
					:clearable="false"
					label="label"
					@update:model-value="jurisdiction = $event?.id ?? jurisdiction" />
				<NcSelect v-model="lifecycle"
					:options="lifecycles"
					:input-label="t('nextfleet', 'Lifecycle')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<NcDateTimePickerNative v-if="disposing"
					v-model="disposedAt"
					type="date"
					:label="t('nextfleet', 'Disposed on')" />
				<NcTextField v-model="retentionMonths"
					:label="t('nextfleet', 'Retention (months)')"
					:disabled="saving"
					inputmode="numeric" />
				<NcTextField v-model="color"
					:label="t('nextfleet', 'Colour')"
					:disabled="saving" />
				<NcTextArea v-model="notes"
					class="sheet__wide"
					:label="t('nextfleet', 'Notes')"
					:disabled="saving" />
			</template>
		</div>

		<template #actions>
			<!-- First in the row and last in emphasis: the one action here nobody reaches for by
			     accident, and the only one with a way back (docs/ui.md). -->
			<NcButton v-if="editing"
				variant="error"
				:disabled="saving"
				@click="remove">
				{{ t('nextfleet', 'Delete vehicle') }}
			</NcButton>
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

/* One column on a phone; two once there is room, so twenty fields are not twenty screens. */
@media (min-width: 480px) {
	.sheet--roomy {
		grid-template-columns: 1fr 1fr;
	}

	.sheet--roomy .sheet__wide {
		grid-column: 1 / -1;
	}
}
</style>
