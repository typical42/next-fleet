<!--
  - SPDX-FileCopyrightText: 2026 Johannes Kolb
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
<script setup>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { computed, onMounted, ref, watch } from 'vue'

import InspectionSticker from './InspectionSticker.vue'
import ReminderRecipients from './ReminderRecipients.vue'
import VehicleGrants from './VehicleGrants.vue'
import { ConflictError, RefusedError, getPreferences, getVehicle, listReminders, reminderTemplates } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { usePreferencesStore } from '../store/preferences.js'
import { may } from '../utils/access.js'
import { decimalComplaint, energyWord, formatCount, formatDay, formatDecimal, jurisdictionWord, lifecycleWord, parseDay, parseDecimal, parseWhole } from '../utils/format.js'
import { INSPECTION, inspectionOf, rewrite } from '../utils/reminders.js'
import { t } from '../utils/l10n.js'
import { newUuid } from '../utils/uuid.js'

const props = defineProps({
	/**
	 * The vehicle to edit; without one the sheet creates a vehicle.
	 *
	 * @type {import('vue').PropType<import('../services/api.js').Vehicle|null>}
	 */
	vehicle: { type: Object, default: null },
})

const emit = defineEmits(['close', 'created', 'import', 'saved'])

const store = useVehiclesStore()
const preferences = usePreferencesStore()

const editing = computed(() => props.vehicle !== null)

// One ref per writable column (lib/Service/VehicleService.php WRITABLE). Numbers stay strings for
// the server to judge, so the sheet never blocks on validation - except the four decimals, which
// go out as the integer their column keeps.
const plate = ref(text(props.vehicle?.plate))
const manufacturer = ref(text(props.vehicle?.manufacturer))
const model = ref(text(props.vehicle?.model))
const vin = ref(text(props.vehicle?.vin))
const tank = ref(decimalText(props.vehicle?.tank_ml, 3))
const battery = ref(decimalText(props.vehicle?.battery_wh, 3))
const purchasePrice = ref(decimalText(props.vehicle?.purchase_price, 2))
const residualEst = ref(decimalText(props.vehicle?.residual_est, 2))
const currency = ref(text(props.vehicle?.currency))
const color = ref(text(props.vehicle?.color))
const notes = ref(text(props.vehicle?.notes))
const firstReg = ref(parseDay(props.vehicle?.first_reg))
const disposedAt = ref(parseDay(props.vehicle?.disposed_at))
const reminderMail = ref(text(props.vehicle?.reminder_mail))
const counter = ref('')
/** How often the recipients were read; a revoke counts it up. */
const recipientsRead = ref(0)

const saving = ref(false)
const failure = ref('')
/**
 * The vehicle this sheet writes against: the prop until a refused write reads a newer one. The
 * prop never moves, so a retry sending its token would be refused forever.
 *
 * @type {import('vue').Ref<import('../services/api.js').Vehicle|null>}
 */
const held = ref(props.vehicle)
/**
 * The write refused because the row moved on (docs/architecture.md#concurrency), or null. Latched
 * rather than derived from `failure`: it tells the next attempt to read the vehicle back first.
 * Which write it was decides only the message.
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

// The two rows a create writes, named before they are sent: an answer lost on the way leaves the
// row written, and the retry is then that row again (docs/api.md#retried-creates).
const clientUuids = { vehicle: newUuid(), reading: newUuid() }

/** The countries the server registers, read once the sheet is up. @type {import('vue').Ref<{ key: string, name: string }[]>} */
const countries = ref([])

// Translated on render, like lifecycleWord() (docs/ui.md#languages).
const types = computed(() => [
	{ id: 'car', label: t('nextfleet', 'Car') },
	{ id: 'motorcycle', label: t('nextfleet', 'Motorcycle') },
	{ id: 'van', label: t('nextfleet', 'Van') },
	{ id: 'truck', label: t('nextfleet', 'Truck') },
	{ id: 'trailer', label: t('nextfleet', 'Trailer') },
	{ id: 'tractor', label: t('nextfleet', 'Tractor') },
	{ id: 'generator', label: t('nextfleet', 'Generator') },
])

const engines = computed(() => ['petrol', 'diesel', 'lpg', 'cng', 'electric', 'hybrid']
	.map((id) => ({ id, label: energyWord(id) })))

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
// Engine hours as a second counter beside the kilometres (docs/architecture.md#odometer-rules).
const countsHours = ref(props.vehicle?.second_unit === 'h')
const offersHours = computed(() => odoUnit.value?.id === 'km')
// A column nobody wrote is null, which means off (docs/architecture.md#data-model).
const logbookMode = ref(props.vehicle?.logbook_mode === true)

/**
 * Whether switching the mode off has been confirmed. The switch itself is not intercepted: the
 * question stands before the save, the moment anything is written.
 */
const confirmedOff = ref(false)

// Switching the mode off ends the period its trips are read under (docs/features.md#logbook-mode),
// so it is asked about; switching on takes nothing away. Derived, so putting the switch back
// answers it. Checked against `held`, not the prop: somebody else switching the mode on meanwhile
// turns a plain save into a switch-off.
const askingOff = computed(() => held.value?.logbook_mode === true && !logbookMode.value && !confirmedOff.value)

// A confirmation does not outlive the switch going back on: a second switch-off is asked again.
watch(logbookMode, (on) => {
	if (on) {
		confirmedOff.value = false
	}
})

/**
 * The type, and the unit it suggests: hours for a tractor or a generator, kilometres otherwise.
 * Only while nothing is counted - a Reading's number means what its unit meant when it was read.
 *
 * @param {{ id: string, label: string }|null} option - the type chosen
 */
function chooseType(option) {
	vehicleType.value = option
	const counted = (held.value?.odo_value ?? null) !== null || (held.value?.second_value ?? null) !== null
	if (option === null || counted) {
		return
	}

	odoUnit.value = chosen(units.value, ['tractor', 'generator'].includes(option.id) ? 'h' : 'km')
}

/**
 * What the fields hold, raw: the import takes this sheet's place (src/views/VehicleView.vue), so it
 * waits until nothing typed would be lost. Not fields(), which throws on a half-typed decimal.
 *
 * @return {string} the fields, comparable
 */
function typed() {
	return JSON.stringify([
		plate.value, manufacturer.value, model.value, vin.value, tank.value, battery.value,
		purchasePrice.value, residualEst.value, currency.value, color.value, notes.value,
		formatDay(firstReg.value), formatDay(disposedAt.value), reminderMail.value,
		vehicleType.value?.id, engine.value?.id, energyTypes.value.map((/** @type {{ id: string }} */ one) => one.id),
		odoUnit.value?.id, lifecycle.value?.id, jurisdiction.value, countsHours.value, logbookMode.value,
	])
}
const untyped = typed()
const pristine = computed(() => typed() === untyped)

// The disposal day belongs to a disposed vehicle only; fields() clears it for any other.
const disposing = computed(() => lifecycle.value?.id === 'disposed')

const country = computed(() => jurisdictions.value.find((one) => one.id === jurisdiction.value) ?? null)

/**
 * The inspection template the vehicle's jurisdiction offers, or null where it requires none.
 *
 * @type {import('vue').Ref<import('../services/api.js').ReminderTemplate|null>}
 */
const inspection = ref(null)
/**
 * The open HU/AU reminder, at the token the interval is written against. Moves on when that
 * write lands, so a retry of the vehicle does not write it twice.
 *
 * @type {import('vue').Ref<import('../services/api.js').Reminder|null>}
 */
const inspected = ref(null)
/** Whether the sticker question is open in the sheet. */
const adding = ref(false)

/**
 * @param {number} months - an inspection interval
 * @return {{ id: number, label: string }} it as the dropdown offers it
 */
function intervalOption(months) {
	return { id: months, label: t('nextfleet', '{months} months', { months }) }
}

// Yearly or every two years is what the law sets by weight and use, which the vehicle does not
// carry (lib/Jurisdiction/De/InspectionScheme.php); an interval set elsewhere is kept on offer.
const intervals = computed(() => [...new Set([12, 24, inspected.value?.recur_months ?? 12])]
	.sort((a, b) => a - b)
	.map(intervalOption))
const interval = ref(null)
// A reminder by counter alone has no interval in months to show.
const showsInterval = computed(() => inspected.value !== null && inspected.value.mode !== 'odo')

// Says what the click does: while the token is stale it overwrites somebody's change, whatever else
// failed on top.
const action = computed(() => {
	if (refused.value !== null) {
		return t('nextfleet', 'Save anyway')
	}

	if (failure.value) {
		return t('nextfleet', 'Try again')
	}

	return editing.value ? t('nextfleet', 'Save') : t('nextfleet', 'Add vehicle')
})

// An error is something the user has to answer for; a warning cost them nothing.
const note = computed(() => {
	// Only the second write of a create can be repeated: the vehicle is already there.
	if (failure.value) {
		return created.value
			? { type: 'warning', text: t('nextfleet', 'The vehicle was added, but its counter reading was not: {reason}', { reason: failure.value }) }
			: { type: 'error', text: failure.value }
	}

	// The server's English names a column; this says what happened and what repeating the click now
	// does, which differs for a save and a delete.
	if (refused.value === 'save') {
		return { type: 'warning', text: t('nextfleet', 'This vehicle was changed somewhere else while you had it open. Saving again writes your values over that change.') }
	}

	if (refused.value === 'delete') {
		return { type: 'warning', text: t('nextfleet', 'This vehicle was changed somewhere else while you had it open. Deleting again removes it as it now stands.') }
	}

	return { type: 'error', text: '' }
})

/**
 * The countries an edit picks from, read from the settings route. The vehicle's own key is added: a
 * country a later release dropped is still the one it is kept under, and what the dropdown says is
 * what the next save writes.
 */
onMounted(async () => {
	if (!editing.value) {
		return
	}

	readInspection()
	try {
		countries.value = (await getPreferences()).jurisdictions
	} catch {
		// A convenience; the vehicle's own country is added below.
	}

	const own = jurisdiction.value
	if (own !== '' && !countries.value.some((one) => one.key === own)) {
		countries.value = [...countries.value, { key: own, name: own }]
	}
})

/**
 * Reads whether the vehicle needs an HU/AU and which reminder carries it. It answers for the
 * vehicle as saved: a country changed in this sheet counts once it is.
 */
async function readInspection() {
	try {
		const [offered, reminders] = await Promise.all([reminderTemplates(props.vehicle.uuid), listReminders(props.vehicle.uuid)])
		inspection.value = offered.find((one) => one.key === INSPECTION) ?? null
		inspected.value = inspectionOf(reminders)
		interval.value = inspected.value?.recur_months ? intervalOption(inspected.value.recur_months) : null
	} catch {
		// A convenience; the vehicle's own fields are what the sheet is for.
	}
}

/** The first grant changes `ever_granted`, which the vehicle screen shows Bookings by. */
function granted() {
	if (!props.vehicle.ever_granted) {
		store.refresh(props.vehicle.uuid)
	}
}

/** The question in the sheet wrote the reminder; its interval now belongs here. */
function added() {
	adding.value = false
	readInspection()
}

/**
 * The interval is the HU/AU reminder's recurrence, so a change is an edit of that reminder - a
 * full replace, with the rest of it as it stands (docs/architecture.md#reminder-engine).
 */
async function writeInterval() {
	const reminder = inspected.value
	if (reminder === null || interval.value === null || interval.value.id === reminder.recur_months) {
		return
	}

	try {
		inspected.value = await store.revise(props.vehicle.uuid, 'reminder', reminder, { ...rewrite(reminder), recur_months: interval.value.id })
	} catch (error) {
		if (!(error instanceof ConflictError)) {
			throw error
		}
		// Read back so the next save goes out under the current token; the interval on screen wins,
		// as every other field of this sheet does.
		inspected.value = inspectionOf(await listReminders(props.vehicle.uuid))
		throw new Error(t('nextfleet', 'The HU/AU reminder was changed somewhere else while you had it open. Saving again writes this interval over that change.'))
	}
}

/**
 * Every writable column, as the API spells it. Sent whole rather than as a diff: `apply()` writes
 * what the payload names, so an emptied field travels empty to be cleared. `retention_months` is
 * not asked, since nothing purges yet (docs/legal.md); it travels as it was read.
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
		tank_ml: decimal(tank, 3, t('nextfleet', 'That is not a tank size.')),
		battery_wh: decimal(battery, 3, t('nextfleet', 'That is not a battery capacity.')),
		first_reg: formatDay(firstReg.value),
		// Cleared here rather than by a watcher, so that toggling the lifecycle twice does not
		// throw away a day the user typed in between.
		disposed_at: disposing.value ? formatDay(disposedAt.value) : '',
		vin: vin.value,
		odo_unit: odoUnit.value?.id ?? '',
		second_unit: offersHours.value && countsHours.value ? 'h' : '',
		purchase_price: decimal(purchasePrice, 2, t('nextfleet', 'That is not a purchase price.')),
		residual_est: decimal(residualEst, 2, t('nextfleet', 'That is not a residual value.')),
		currency: currencyCode(currency.value),
		jurisdiction: jurisdiction.value,
		logbook_mode: logbookMode.value,
		lifecycle: lifecycle.value?.id ?? '',
		color: color.value,
		notes: notes.value,
		reminder_mail: reminderMail.value,
	}
}

/**
 * @param {string} field - the field's label
 * @param {number} max - VehicleService::WRITABLE's length
 * @return {string} the refusal of a text too long for its column
 */
function tooLong(field, max) {
	return t('nextfleet', 'The field {field} takes {max} characters at most.', { field, max: formatCount(max) })
}

/**
 * VehicleService's refusals a person can run into, by its English words; the rest show as sent.
 *
 * @type {Record<string, () => string>}
 */
const REFUSALS = {
	'plate is longer than 32 characters': () => tooLong(t('nextfleet', 'Registration plate'), 32),
	'manufacturer is longer than 64 characters': () => tooLong(t('nextfleet', 'Manufacturer'), 64),
	'model is longer than 64 characters': () => tooLong(t('nextfleet', 'Model'), 64),
	'vin is longer than 32 characters': () => tooLong(t('nextfleet', 'VIN'), 32),
	'color is longer than 32 characters': () => tooLong(t('nextfleet', 'Colour'), 32),
	'notes is longer than 10000 characters': () => tooLong(t('nextfleet', 'Notes'), 10000),
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
		} else if (error instanceof RefusedError && error.reason === 'currency_in_use') {
			failure.value = t('nextfleet', 'The currency cannot change any more: costs are already recorded in it for this vehicle.')
		} else {
			failure.value = REFUSALS[error.message]?.() ?? error.message
		}
	} finally {
		saving.value = false
	}
}

/**
 * The edit or the create. An unanswered question holds it; the greyed button only shows this rule.
 */
function save() {
	if (askingOff.value) {
		return Promise.resolve()
	}

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
		// Not through the store: the answer may be a vehicle the overview no longer lists, and
		// storing it would take this screen away under the open sheet (src/App.vue).
		held.value = await getVehicle(held.value.uuid)
		refused.value = null
	}

	return held.value
}

/**
 * The edit: one write, checked against `updated_at`. What is on screen wins. The question is asked
 * again after current(): a vehicle read back may now be under the mode.
 */
async function write() {
	// Read first: a field nobody can read stops the save before the interval is written.
	const changed = fields()
	const vehicle = await current()
	if (askingOff.value) {
		return
	}

	await writeInterval()
	const saved = await store.save({ ...vehicle, ...changed })
	if ((vehicle.logbook_mode === true) !== (saved.logbook_mode === true)) {
		// A flip answers the overview's logbook question too (src/components/CompleteHint.vue).
		// Silent: an unstored answer costs one more question.
		preferences.dismissLogbook(saved.uuid).catch(() => {})
	}
	emit('saved', saved)
}

/**
 * The delete. It emits nothing: the vehicle leaving the fleet unmounts this sheet (src/App.vue),
 * and the way back is the store's undo (src/components/UndoToast.vue).
 */
async function erase() {
	await store.remove(await current())
}

/** The create: four fields, then the counter as a first Reading. */
async function add() {
	// Judged before anything is written: an unreadable counter is a question for the driver, not a
	// vehicle created without it.
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
			vehicle_type: vehicleType.value?.id ?? '',
			odo_unit: odoUnit.value?.id ?? '',
			client_uuid: clientUuids.vehicle,
		})
	}

	if (number !== null) {
		await store.record(created.value.uuid, {
			value: number,
			read_at: Math.floor(Date.now() / 1000),
			read_at_off: -new Date().getTimezoneOffset(),
			client_uuid: clientUuids.reading,
		})
	}

	emit('created', created.value)
}

/**
 * Carries the `.stop` for the Escape that dismisses a native date picker: Chromium also delivers
 * the keydown to the input, which would close the sheet. Nothing says whether a picker is open, so
 * the field always keeps the key.
 */
function keepPicker() {}

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
 * @param {number|null|undefined} value - the column's integer: millilitres, watt-hours, cents
 * @param {number} places - how many of its digits are decimals
 * @return {string} the field's text, in litres, kWh or euros
 */
function decimalText(value, places) {
	return value === null || value === undefined ? '' : formatDecimal(value, places)
}

/**
 * A decimal as typed, as the integer its column keeps. Checked here: the server would refuse `55,5`
 * in words about millilitres the field does not show.
 *
 * @param {import('vue').Ref<string>} input - the field
 * @param {number} places - how many decimals the column keeps
 * @param {string} complaint - what to say when it cannot be read
 * @return {number|string} the number, or the empty field that clears the column
 * @throws {Error} when the field says something that is not a number
 */
function decimal(input, places, complaint) {
	if (input.value.trim() === '') {
		return ''
	}

	const number = parseDecimal(input.value, places)
	if (number === null) {
		throw new Error(decimalComplaint(input.value, complaint))
	}

	return number
}

/**
 * The currency as the server keeps it (lib/Service/VehicleService.php), asked about here so a sign
 * gets words rather than the server's English. One stored before that check goes back untouched.
 *
 * @param {string} typed - the field
 * @return {string} the code in capitals, or the empty field that clears the column
 * @throws {Error} when the field is not three letters
 */
function currencyCode(typed) {
	if (typed !== '' && typed === text(props.vehicle?.currency)) {
		return typed
	}
	const code = typed.trim().toUpperCase()
	if (code !== '' && !/^[A-Z]{3}$/.test(code)) {
		throw new Error(t('nextfleet', 'That is not a currency code such as EUR.'))
	}

	return code
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
		<!-- NcDialog's Escape is a useHotKey, which skips keystrokes in text fields, and the sheet
		     opens with the caret in one. Caught here, it keeps the mid-save guard; an open
		     NcSelect stops it first. -->
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
			<!-- Type and unit sit with the counter on a create, because the number means nothing
			     without them. Both come prefilled, so they cost no field (docs/ui.md). -->
			<NcSelect :model-value="vehicleType"
				:options="types"
				:input-label="t('nextfleet', 'Vehicle type')"
				:disabled="saving || !!created"
				:clearable="false"
				label="label"
				@update:model-value="chooseType" />
			<NcSelect v-model="odoUnit"
				:options="units"
				:input-label="t('nextfleet', 'Counter unit')"
				:disabled="saving || !!created"
				:clearable="false"
				label="label" />
			<NcTextField v-if="!editing"
				v-model="counter"
				:label="t('nextfleet', 'Counter reading')"
				:disabled="saving"
				inputmode="numeric" />

			<template v-if="editing">
				<!-- Switching off hides the hour field and keeps the hour Readings. -->
				<NcFormBoxSwitch v-if="offersHours"
					v-model="countsHours"
					class="sheet__wide"
					:label="t('nextfleet', 'Also counts engine hours')"
					:disabled="saving" />
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
					:label="t('nextfleet', 'First registration')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />
				<!-- The interval is stored on the HU/AU reminder, so without one there is nothing to
				     set it on yet (docs/ui.md, the due banner). -->
				<NcSelect v-if="inspection !== null && showsInterval"
					v-model="interval"
					:options="intervals"
					:input-label="t('nextfleet', 'Inspection interval')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<div v-else-if="inspection !== null && inspected === null" class="sheet__wide">
					<InspectionSticker v-if="adding"
						:vehicle="held"
						:template="inspection"
						@saved="added" />
					<NcButton v-else :disabled="saving" @click="adding = true">
						{{ t('nextfleet', 'Add HU/AU reminder') }}
					</NcButton>
				</div>
				<!-- The list takes `edit` (docs/ui.md). Re-keyed on a revoke, which prunes it server
				     side. -->
				<ReminderRecipients v-if="may(props.vehicle, 'edit')"
					:key="recipientsRead"
					v-model:cadence="reminderMail"
					class="sheet__wide"
					:vehicle="props.vehicle.uuid"
					:disabled="saving" />
				<!-- Asked in litres, kWh and the vehicle's currency, kept as millilitres, watt-hours
				     and cents (docs/contributing.md) - decimal() converts. -->
				<NcTextField v-model="tank"
					:label="t('nextfleet', 'Tank size (l)')"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-model="battery"
					:label="t('nextfleet', 'Battery capacity (kWh)')"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-model="purchasePrice"
					:label="t('nextfleet', 'Purchase price')"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-model="residualEst"
					:label="t('nextfleet', 'Residual value')"
					:disabled="saving"
					inputmode="decimal" />
				<NcTextField v-model="currency"
					:label="t('nextfleet', 'Currency')"
					:disabled="saving"
					maxlength="3" />
				<!-- The jurisdiction is not a fifth create field: it defaults from the personal
				     setting and is changed here (docs/ui.md). -->
				<NcSelect :model-value="country"
					:options="jurisdictions"
					:input-label="t('nextfleet', 'Country')"
					:disabled="saving"
					:clearable="false"
					label="label"
					@update:model-value="jurisdiction = $event?.id ?? jurisdiction" />
				<!-- The switch the vehicle's logbook rules hang off (docs/features.md#logbook-mode),
				     beside the country whose ruleset it invokes. -->
				<NcFormBoxSwitch v-model="logbookMode"
					class="sheet__wide"
					:label="t('nextfleet', 'Logbook mode')"
					:description="`${t('nextfleet', 'Trips are recorded with an audit trail, and a delete voids the trip instead of removing it.')} ${t('nextfleet', 'Not reviewed by a lawyer.')}`"
					:disabled="saving" />
				<div v-if="askingOff" class="sheet__wide sheet__question">
					<NcNoteCard type="warning"
						:text="t('nextfleet', 'Switching Logbook mode off ends the audited period for this vehicle. What is already recorded stays as it is.')" />
					<div class="sheet__answers">
						<NcButton :disabled="saving" @click="logbookMode = true">
							{{ t('nextfleet', 'Keep it on') }}
						</NcButton>
						<NcButton variant="warning" :disabled="saving" @click="confirmedOff = true">
							{{ t('nextfleet', 'Switch it off') }}
						</NcButton>
					</div>
				</div>
				<NcSelect v-model="lifecycle"
					:options="lifecycles"
					:input-label="t('nextfleet', 'Lifecycle')"
					:disabled="saving"
					:clearable="false"
					label="label" />
				<NcDateTimePickerNative v-if="disposing"
					v-model="disposedAt"
					type="date"
					:label="t('nextfleet', 'Disposed on')"
					:disabled="saving"
					@keydown.esc.stop="keepPicker" />
				<NcTextField v-model="color"
					:label="t('nextfleet', 'Colour')"
					:disabled="saving" />
				<NcTextArea v-model="notes"
					class="sheet__wide"
					:label="t('nextfleet', 'Notes')"
					:disabled="saving" />
				<!-- Written on each change, like the recipients; the owner's alone. -->
				<VehicleGrants class="sheet__wide"
					:vehicle="props.vehicle"
					:disabled="saving"
					@granted="granted"
					@revoked="recipientsRead++" />
				<!-- Importing is `edit`, and a disposed vehicle takes none (docs/architecture.md#import).
				     The screen swaps this sheet for the import's (src/views/VehicleView.vue). -->
				<div v-if="may(props.vehicle, 'edit') && props.vehicle.lifecycle !== 'disposed'" class="sheet__wide sheet__import">
					<NcButton :disabled="saving || !pristine" @click="emit('import')">
						{{ t('nextfleet', 'Import from a file…') }}
					</NcButton>
					<span v-if="!pristine" class="sheet__hint">
						{{ t('nextfleet', 'Save or cancel your changes first.') }}
					</span>
				</div>
				<!-- Last in the body and away from *Cancel*, so a thumb aiming at one does not land on
				     the other; it has a way back all the same (docs/ui.md). The owner's alone. -->
				<div v-if="may(props.vehicle, 'own')" class="sheet__wide">
					<NcButton variant="error"
						:disabled="saving"
						@click="remove">
						{{ t('nextfleet', 'Delete vehicle') }}
					</NcButton>
				</div>
			</template>
		</div>

		<template #actions>
			<NcButton :disabled="saving" @click="requestClose">
				{{ t('nextfleet', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="saving || askingOff" @click="save">
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

/* The question and the two answers to it read as one block, whatever the grid does around them. */
.sheet__question {
	display: grid;
	gap: calc(var(--default-grid-baseline) * 2);
}

.sheet__answers {
	display: flex;
	flex-wrap: wrap;
	gap: calc(var(--default-grid-baseline) * 2);
	justify-content: end;
}

.sheet__import {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: calc(var(--default-grid-baseline) * 2);
}

.sheet__hint {
	color: var(--color-text-maxcontrast);
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
