/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { BookingConflictError, ConflictError, RefusedError, deleteEntry, energyPrefill, expensePrefill, getVehicle, listReminders, maintenancePrefill, readEntry, recordEnergy, recordExpense, recordMaintenance, recordReading, recordTrip, tripPrefill, updateEntry } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import EntrySheet from './EntrySheet.vue'

// The network is the api client's own seam (api.spec.js), and the store is left real: this sheet
// is the only caller store.log() has, so the wiring through it is part of what is under test.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	deleteEntry: vi.fn(),
	energyPrefill: vi.fn(),
	expensePrefill: vi.fn(),
	getVehicle: vi.fn(),
	listReminders: vi.fn(),
	maintenancePrefill: vi.fn(),
	readEntry: vi.fn(),
	recordEnergy: vi.fn(),
	recordExpense: vi.fn(),
	recordMaintenance: vi.fn(),
	recordReading: vi.fn(),
	recordTrip: vi.fn(),
	tripPrefill: vi.fn(),
	updateEntry: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_value: 148320 }
const TRUCK = { ...VEHICLE, odo_unit: 'km', second_unit: 'h', second_value: 5004, energy_types: ['diesel'] }
const HYBRID = { ...VEHICLE, odo_unit: 'km', energy_types: ['petrol', 'electric'] }

const DEPARTURE = new Date(2026, 0, 15, 8, 30)
const ARRIVAL = new Date(2026, 0, 15, 9, 45)

/**
 * The sheet, mounted. Every field sits inside the dialog, so that one component is rendered and
 * the rest stay stubs, which render their slots for the choosers' choices (docs/development.md).
 *
 * @param {object} [vehicle] - the vehicle the sheet is on
 * @param {object|null} [entry] - the timeline row it was opened on, or null for a new entry
 * @return {import('@vue/test-utils').VueWrapper} the mounted sheet
 */
function sheet(vehicle = VEHICLE, entry = null) {
	return shallowMount(EntrySheet, {
		props: { vehicle, entry },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
		},
	})
}

/**
 * One chooser, addressed by the label above it.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label above the row of choices
 * @return {any} the chooser, or undefined when the sheet does not show it
 */
function chooser(wrapper, label) {
	return wrapper.findAllComponents(NcRadioGroup).find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label above the row of choices
 * @return {string[]} what that chooser offers, as it reads on screen
 */
function choices(wrapper, label) {
	return chooser(wrapper, label).findAllComponents(NcRadioGroupButton)
		.map((/** @type {any} */ one) => String(one.props('label')))
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label above the row of choices
 * @param {string} value - the choice to take
 * @return {Promise<void>} when the sheet has been rerendered
 */
async function choose(wrapper, label, value) {
	await chooser(wrapper, label).vm.$emit('update:modelValue', value)
}

/**
 * One text field, addressed the way a user does: by the label beside it.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label the field carries
 * @return {any} the field, or undefined when the sheet does not show it
 */
function field(wrapper, label) {
	return wrapper.findAllComponents(NcTextField).find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @return {string[]} every field the sheet asks for, in the order it asks
 */
function labels(wrapper) {
	return wrapper.findAllComponents(NcTextField).map((one) => String(one.props('label')))
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label beside the picker
 * @return {any} the picker, or undefined when the sheet does not show it
 */
function moment(wrapper, label) {
	return wrapper.findAllComponents(NcDateTimePickerNative).find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label above the dropdown
 * @return {any} the dropdown, or undefined when the sheet does not show it
 */
function dropdown(wrapper, label) {
	return wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label beside the switch
 * @return {any} the switch, or undefined when the sheet does not show it
 */
function toggle(wrapper, label) {
	return wrapper.findAllComponents(NcFormBoxSwitch).find((/** @type {any} */ one) => one.props('label') === label)
}

/**
 * The button that writes, found as the last one rather than by its words: a failed save renames it.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @return {any} the button that writes
 */
function saveButton(wrapper) {
	return wrapper.findAllComponents(NcButton).at(-1)
}

/**
 * One journey typed into the sheet, then saved.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {Record<string, string>} typed - the fields to fill in, by their labels
 * @return {Promise<void>} when the write has been through
 */
async function record(wrapper, typed) {
	await moment(wrapper, 'Departure').vm.$emit('update:modelValue', DEPARTURE)
	await moment(wrapper, 'Arrival').vm.$emit('update:modelValue', ARRIVAL)
	for (const [label, value] of Object.entries(typed)) {
		await field(wrapper, label).vm.$emit('update:modelValue', value)
	}

	await saveButton(wrapper).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(getVehicle).mockResolvedValue(/** @type {any} */ ({ ...VEHICLE, odo_value: 148402 }))
	// The server's answer, whole: `reconciled` is its own and never a field the sheet fills in
	// (src/services/api.js).
	vi.mocked(recordTrip).mockResolvedValue(/** @type {any} */ ({ uuid: 't-1', reconciled: false }))
	vi.mocked(recordReading).mockResolvedValue(/** @type {any} */ ({ uuid: 'r-1', value: 148402 }))
	vi.mocked(recordEnergy).mockResolvedValue(/** @type {any} */ ({ uuid: 'e-1', flags: [] }))
	vi.mocked(recordMaintenance).mockResolvedValue(/** @type {any} */ ({ uuid: 'm-1' }))
	vi.mocked(listReminders).mockResolvedValue([])
	vi.mocked(tripPrefill).mockResolvedValue({ places: ['Office', 'Müller GmbH'], purposes: ['Client visit'], partners: ['Müller GmbH'], category: 'business', last: null })
	vi.mocked(maintenancePrefill).mockResolvedValue({ vat_rate: 1900, vendors: ['ATU Nord', 'Reifen Müller'] })
	vi.mocked(recordExpense).mockResolvedValue(/** @type {any} */ ({ uuid: 'x-1' }))
	vi.mocked(expensePrefill).mockResolvedValue({ vat_rate: 1900 })
	vi.mocked(energyPrefill).mockResolvedValue({
		vat_rate: 1900,
		stations: [
			{ station: 'Aral Hauptstr.', energy: 'electric', unit_price: 530 },
			{ station: 'Aral Hauptstr.', energy: 'diesel', unit_price: 1799 },
		],
	})
})

describe('the entry sheet', () => {
	/** A sheet that bound `:open` and no listener would swallow the dialog's own close. */
	it('closes when the dialog reports itself closed', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/** NcDialog's own Escape skips text fields, and the sheet opens with the caret in one. */
	it('closes when Esc is pressed in a field', async () => {
		const wrapper = sheet()

		await wrapper.find('.sheet').trigger('keydown.esc')

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	it('opens on a trip and offers the other kinds beside it, the expense last', () => {
		const wrapper = sheet(HYBRID)

		expect(choices(wrapper, 'Entry type')).toEqual(['Trip', 'Energy', 'Maintenance', 'Odometer', 'Expense'])
		expect(chooser(wrapper, 'Entry type').props('modelValue')).toBe('trip')
	})

	it('asks for nothing but the counter under the odometer', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'odometer')

		expect(labels(wrapper)).toEqual(['Counter reading'])
		expect(moment(wrapper, 'Departure')).toBeUndefined()
		expect(chooser(wrapper, 'Counter or distance')).toBeUndefined()
		expect(chooser(wrapper, 'Which counter')).toBeUndefined()
	})

	/**
	 * Engine hours are a second chain (docs/architecture.md#odometer-rules, rule 4). Kilometres
	 * first: the chain trips run on.
	 */
	it('asks which counter it reads on a vehicle that counts engine hours', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'odometer')

		expect(choices(wrapper, 'Which counter')).toEqual(['Kilometres', 'Engine hours'])
		expect(chooser(wrapper, 'Which counter').props('modelValue')).toBe('main')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('148320')
	})

	/** The hours are prefilled as they stand, like the kilometres. */
	it('records engine hours on the hour chain', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'odometer')
		await choose(wrapper, 'Which counter', 'second')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('5004')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '5011')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordReading).toHaveBeenCalledWith('v-1', expect.objectContaining({ value: 5011, counter: 'second' }))
	})

	/** The counters are the one exception to prefilling (docs/ui.md#the-entry-sheet-in-detail). */
	it('prefills what it knows and claims nothing about the counter', async () => {
		const wrapper = sheet()
		await flushPromises()

		expect(field(wrapper, 'Odometer at departure').props('modelValue')).toBe('')
		expect(field(wrapper, 'Odometer at arrival').props('modelValue')).toBe('')
		expect(dropdown(wrapper, 'Category').props('modelValue').id).toBe('business')
		expect(moment(wrapper, 'Departure').props('modelValue')).toBeInstanceOf(Date)
		expect(moment(wrapper, 'Arrival').props('modelValue')).toBeInstanceOf(Date)
	})

	/** A kilometre counter is the odometer; one that counts hours is not, so it keeps "counter". */
	it('names the trip counters after what the vehicle counts', () => {
		expect(field(sheet({ ...VEHICLE, odo_unit: 'km' }), 'Odometer at departure')).toBeDefined()
		expect(field(sheet({ ...VEHICLE, odo_unit: 'km' }), 'Odometer at arrival')).toBeDefined()
		expect(field(sheet({ ...VEHICLE, odo_unit: 'h' }), 'Start counter')).toBeDefined()
		expect(field(sheet({ ...VEHICLE, odo_unit: 'h' }), 'End counter')).toBeDefined()
	})

	/** Completing from this vehicle's trips keeps six spellings of one client out of reports. */
	it('offers the places, purposes and partners of this vehicle\'s trips', async () => {
		const wrapper = sheet()
		await flushPromises()

		/** @param {string} label - the field whose completions are read */
		const offered = (label) => wrapper.find(`datalist#${field(wrapper, label).attributes('list')}`)
			.findAll('option').map((one) => one.attributes('value'))
		expect(vi.mocked(tripPrefill)).toHaveBeenCalledWith('v-1')
		expect(offered('Starting point')).toEqual(['Office', 'Müller GmbH'])
		expect(offered('Destination')).toEqual(['Office', 'Müller GmbH'])
		expect(offered('Purpose')).toEqual(['Client visit'])
		expect(offered('Business partner')).toEqual(['Müller GmbH'])
	})

	/** A prefill that fails leaves the fields as they were before it was asked for: plain. */
	it('still takes a trip when its completions cannot be read', async () => {
		vi.mocked(tripPrefill).mockRejectedValue(new Error('offline'))
		const wrapper = sheet()
		await flushPromises()

		expect(wrapper.findAll('datalist option')).toHaveLength(0)
		expect(field(wrapper, 'Purpose').props('modelValue')).toBe('')
	})

	/**
	 * The start counter is a claim (docs/architecture.md#odometer-rules), so it belongs to the
	 * counter half; a distance claims nothing about where the journey started.
	 */
	it('asks for a distance instead of the two counters when that is what the driver knows', async () => {
		const wrapper = sheet()
		expect(choices(wrapper, 'Counter or distance')).toEqual(['Counter', 'Distance'])

		await choose(wrapper, 'Counter or distance', 'distance')

		expect(field(wrapper, 'Distance').props('modelValue')).toBe('')
		expect(field(wrapper, 'Odometer at departure')).toBeUndefined()
		expect(field(wrapper, 'Odometer at arrival')).toBeUndefined()
	})

	/** Each instant carries its own offset (docs/architecture.md#time); counters go as typed. */
	it('writes the journey and the counter it ended on', async () => {
		const wrapper = sheet()

		await record(wrapper, {
			'Odometer at departure': '148.320',
			'Odometer at arrival': '148.402',
			Purpose: 'Kundentermin',
			'Starting point': 'München',
			Destination: 'Augsburg',
			'Business partner': 'Huber GmbH',
		})

		expect(recordTrip).toHaveBeenCalledWith('v-1', {
			client_uuid: expect.any(String),
			started_at: Math.floor(DEPARTURE.getTime() / 1000),
			started_at_off: -DEPARTURE.getTimezoneOffset(),
			ended_at: Math.floor(ARRIVAL.getTime() / 1000),
			ended_at_off: -ARRIVAL.getTimezoneOffset(),
			category: 'business',
			from_label: 'München',
			to_label: 'Augsburg',
			purpose: 'Kundentermin',
			partner: 'Huber GmbH',
			start_odo: 148320,
			end_odo: 148402,
		})
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * The Reading is counted from the chain, and a start counter the sheet invented would be spent
	 * before gap detection could measure it (docs/architecture.md#odometer-rules).
	 */
	it('writes the kilometres and no counter at all when that is what the driver knows', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Counter or distance', 'distance')
		await record(wrapper, { Distance: '82' })

		expect(recordTrip).toHaveBeenCalledWith('v-1', expect.objectContaining({ distance: 82 }))
		expect(vi.mocked(recordTrip).mock.calls[0][1]).not.toHaveProperty('start_odo')
		expect(vi.mocked(recordTrip).mock.calls[0][1]).not.toHaveProperty('end_odo')
	})

	/**
	 * What the row is missing is the server's to judge and the timeline's to ask about
	 * (docs/features.md#logbook-mode).
	 */
	it('leaves out the counter the driver did not read', async () => {
		const wrapper = sheet()

		await record(wrapper, { 'Odometer at arrival': '148402' })

		expect(vi.mocked(recordTrip).mock.calls[0][1]).not.toHaveProperty('start_odo')
		expect(recordTrip).toHaveBeenCalledWith('v-1', expect.objectContaining({ end_odo: 148402 }))
	})

	it('reports the write, which closing on a cancel does not', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)
		expect(wrapper.emitted('saved')).toBeUndefined()

		await record(wrapper, { 'Odometer at arrival': '148402' })

		expect(wrapper.emitted('saved')?.length).toBe(1)
		// "Saved.", with no way back: the new row is on the timeline to delete (UndoToast.vue).
		expect(useVehiclesStore().saved).toEqual({ vehicle: 'v-1', type: 'trip', entry: null, before: null })
	})

	it('records a plain counter reading under the odometer', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'odometer')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148.402')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordReading).toHaveBeenCalledWith('v-1', expect.objectContaining({ value: 148402 }))
		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/** A failed save is never lost (docs/ui.md#the-entry-sheet-in-detail). */
	it('stays open with every value intact when the write is refused', async () => {
		vi.mocked(recordTrip).mockRejectedValue(new Error('ended_at is not before started_at'))
		const wrapper = sheet()

		await record(wrapper, { 'Odometer at arrival': '148402', Purpose: 'Kundentermin' })

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('ended_at is not before started_at')
		expect(field(wrapper, 'Odometer at arrival').props('modelValue')).toBe('148402')
		expect(field(wrapper, 'Purpose').props('modelValue')).toBe('Kundentermin')
		expect(moment(wrapper, 'Departure').props('modelValue')).toBe(DEPARTURE)
		expect(saveButton(wrapper).text()).toBe('Try again')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/**
	 * A save whose answer was lost may have landed, so the retry names the same row
	 * (docs/api.md#retried-creates). The next sheet is another Entry.
	 */
	it('sends one client uuid per open sheet, the same on a retry', async () => {
		vi.mocked(recordTrip).mockRejectedValueOnce(new Error('No connection'))
		const wrapper = sheet()
		await record(wrapper, { 'Odometer at arrival': '148402' })
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		await record(sheet(), { 'Odometer at arrival': '148502' })

		const sent = vi.mocked(recordTrip).mock.calls.map(([, trip]) => /** @type {{client_uuid: string}} */ (trip).client_uuid)
		expect(sent).toHaveLength(3)
		expect(sent[0]).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
		expect(sent[1]).toBe(sent[0])
		expect(sent[2]).not.toBe(sent[0])
	})

	/** The server's arithmetic refusals name a reason, and the sheet says each in the driver's words. */
	it.each([
		['end_below_start', 'The counter at the end is below the start.'],
		['ends_in_future', 'The arrival is more than a day in the future.'],
	])('words the refusal %s', async (reason, words) => {
		vi.mocked(recordTrip).mockRejectedValue(new RefusedError('in English, for a log', reason))
		const wrapper = sheet()

		await record(wrapper, { 'Odometer at arrival': '148402' })

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe(words)
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/** `7,2` is not a whole number (src/utils/format.js), and the sheet asks rather than rounds. */
	it('writes nothing when the kilometres are not kilometres', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Counter or distance', 'distance')
		await record(wrapper, { Distance: '7,2' })

		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not a distance.')
		expect(field(wrapper, 'Distance').props('modelValue')).toBe('7,2')
	})

	it('writes nothing when the counter reading is empty', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'odometer')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordReading).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not a counter reading.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/** A cleared date field leaves the picker holding null, and no logbook can place that trip. */
	it('writes nothing when a trip has lost one of its two moments', async () => {
		const wrapper = sheet()

		await moment(wrapper, 'Departure').vm.$emit('update:modelValue', null)
		await field(wrapper, 'Odometer at arrival').vm.$emit('update:modelValue', '148402')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text'))
			.toBe('A trip needs a departure and an arrival.')
	})

	/** A vehicle with no energy is shown the kind anyway, and told where to unlock it. */
	it('offers energy only to a vehicle that takes some, and says how to get there', () => {
		const none = sheet()
		const energy = chooser(none, 'Entry type').findAllComponents(NcRadioGroupButton)
			.find((/** @type {any} */ one) => one.props('value') === 'energy')

		expect(choices(none, 'Entry type')).toEqual(['Trip', 'Energy', 'Maintenance', 'Odometer', 'Expense'])
		expect(energy.props('disabled')).toBe(true)
		expect(chooser(none, 'Entry type').props('description'))
			.toBe('Choose the energy this vehicle takes under Edit vehicle first.')

		const hybrid = sheet(HYBRID)
		expect(chooser(hybrid, 'Entry type').findAllComponents(NcRadioGroupButton)
			.find((/** @type {any} */ one) => one.props('value') === 'energy').props('disabled')).toBe(false)
		expect(chooser(hybrid, 'Entry type').props('description')).toBeUndefined()
	})

	it('asks which of the vehicle energies a fill-up is of', async () => {
		const wrapper = sheet(HYBRID)

		await choose(wrapper, 'Entry type', 'energy')

		expect(dropdown(wrapper, 'Energy').props('options').map((/** @type {any} */ one) => one.label))
			.toEqual(['Petrol', 'Electric'])
		expect(dropdown(wrapper, 'Energy').props('modelValue').id).toBe('petrol')
		expect(field(wrapper, 'Amount (l)')).toBeDefined()
	})

	/**
	 * Typed as a pump shows them, sent as the integers their columns hold
	 * (docs/architecture.md#data-model). The VAT rate is the server's for the day.
	 */
	it('writes a fill-up as the columns hold it', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		const [, at, off] = vi.mocked(energyPrefill).mock.calls[0]
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')
		await field(wrapper, 'Amount (l)').vm.$emit('update:modelValue', '48,2')
		await field(wrapper, 'Total price').vm.$emit('update:modelValue', '85,10')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148.402')
		await field(wrapper, 'Engine hours').vm.$emit('update:modelValue', '5011')
		await field(wrapper, 'Station').vm.$emit('update:modelValue', 'Shell Ring')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordEnergy).toHaveBeenCalledWith('v-1', {
			client_uuid: expect.any(String),
			filled_at: at,
			filled_at_off: off,
			energy: 'diesel',
			amount: 48200,
			total: 8510,
			vat_rate: 1900,
			full_tank: true,
			missed_previous: false,
			odo: 148402,
			second_odo: 5011,
			station: 'Shell Ring',
		})
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * Null means "not stated", never zero (docs/architecture.md#data-model): a receipt without a
	 * VAT line is a cleared field, and the sheet sends no rate rather than inventing one.
	 */
	it('states no VAT rate when the field is cleared', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		await field(wrapper, 'Amount (l)').vm.$emit('update:modelValue', '48,2')
		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(vi.mocked(recordEnergy).mock.calls[0][1]).not.toHaveProperty('vat_rate')
		expect(vi.mocked(recordEnergy).mock.calls[0][1]).not.toHaveProperty('total')
	})

	/** A typed 0 is a receipt that states no VAT was charged, which is not a cleared field. */
	it('sends a VAT rate of 0 the person typed', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		await field(wrapper, 'Amount (l)').vm.$emit('update:modelValue', '48,2')
		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '0')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(vi.mocked(recordEnergy).mock.calls[0][1]).toHaveProperty('vat_rate', 0)
	})

	/** A total answers the price better than the station's last one, so that guess is not sent. */
	it('prefills the price the station last charged, and lets a total overrule it', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		expect(wrapper.findAll('datalist option').map((one) => one.attributes('value'))).toEqual(['Aral Hauptstr.'])
		await field(wrapper, 'Station').vm.$emit('update:modelValue', 'Aral Hauptstr.')
		expect(field(wrapper, 'Price per litre').props('modelValue')).toBe('1.799')
		await field(wrapper, 'Amount (l)').vm.$emit('update:modelValue', '40')
		await field(wrapper, 'Total price').vm.$emit('update:modelValue', '70')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(vi.mocked(recordEnergy).mock.calls[0][1]).not.toHaveProperty('unit_price')
	})

	it('follows the station and the energy with the price it prefilled', async () => {
		const wrapper = sheet(HYBRID)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		await field(wrapper, 'Station').vm.$emit('update:modelValue', 'Aral Hauptstr.')
		expect(field(wrapper, 'Price per litre').props('modelValue')).toBe('')
		await dropdown(wrapper, 'Energy').vm.$emit('update:modelValue', { id: 'electric', label: 'Electric' })
		expect(field(wrapper, 'Price per kWh').props('modelValue')).toBe('0.53')
		await field(wrapper, 'Station').vm.$emit('update:modelValue', 'Shell Ring')
		expect(field(wrapper, 'Price per kWh').props('modelValue')).toBe('')
	})

	/** Without a total, the price is the one figure there is, and a typed price is the driver's word. */
	it('sends the price when there is no total, or when the driver typed it', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()
		await field(wrapper, 'Station').vm.$emit('update:modelValue', 'Aral Hauptstr.')
		await field(wrapper, 'Amount (l)').vm.$emit('update:modelValue', '40')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(recordEnergy).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ unit_price: 1799 }))

		await field(wrapper, 'Price per litre').vm.$emit('update:modelValue', '1,759')
		await field(wrapper, 'Total price').vm.$emit('update:modelValue', '70')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(recordEnergy).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ unit_price: 1759, total: 7000 }))
	})

	it('asks an electric charge where it was, and DC only in public', async () => {
		const wrapper = sheet(HYBRID)

		await choose(wrapper, 'Entry type', 'energy')
		expect(chooser(wrapper, 'Where')).toBeUndefined()
		await dropdown(wrapper, 'Energy').vm.$emit('update:modelValue', { id: 'electric', label: 'Electric' })
		expect(field(wrapper, 'Amount (kWh)')).toBeDefined()
		expect(choices(wrapper, 'Where')).toEqual(['Home', 'Public'])
		expect(toggle(wrapper, 'DC fast charging')).toBeUndefined()

		await choose(wrapper, 'Where', 'public')
		await toggle(wrapper, 'DC fast charging').vm.$emit('update:modelValue', true)
		await field(wrapper, 'Amount (kWh)').vm.$emit('update:modelValue', '30,5')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordEnergy).toHaveBeenCalledWith('v-1', expect.objectContaining({
			energy: 'electric',
			amount: 30500,
			location_kind: 'public',
			is_dc: true,
		}))
	})

	/** The counter is optional and never prefilled, so an empty one says what it costs. */
	it('says consumption needs the counter while it is empty', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('')
		expect(field(wrapper, 'Counter reading').props('helperText')).toBe('Consumption needs the odometer reading.')

		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148402')
		expect(field(wrapper, 'Counter reading').props('helperText')).toBe('')

		const hours = sheet({ ...TRUCK, odo_unit: 'h' })
		await choose(hours, 'Entry type', 'energy')
		expect(field(hours, 'Counter reading').props('helperText')).toBe('Consumption needs the counter reading.')
	})

	it('asks the rate again for another day, unless the driver stated one', async () => {
		const wrapper = sheet(TRUCK)
		await choose(wrapper, 'Entry type', 'energy')
		await flushPromises()

		vi.mocked(energyPrefill).mockResolvedValue({ vat_rate: 1600, stations: [] })
		const day = new Date(2020, 8, 1, 12, 0)
		await moment(wrapper, 'Date').vm.$emit('update:modelValue', day)
		await flushPromises()
		expect(energyPrefill).toHaveBeenLastCalledWith('v-1', Math.floor(day.getTime() / 1000), -day.getTimezoneOffset())
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('16')

		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '7')
		vi.mocked(energyPrefill).mockResolvedValue({ vat_rate: 1900, stations: [] })
		await moment(wrapper, 'Date').vm.$emit('update:modelValue', new Date(2026, 0, 1, 12, 0))
		await flushPromises()
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('7')
	})

	it('writes nothing when a fill-up has no amount', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordEnergy).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not an amount.')
	})

	/** As on a fill-up: integers as the columns hold them, and counters never prefilled. */
	it('writes a maintenance record as the columns hold it', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'maintenance')
		await flushPromises()
		const [, at, off] = vi.mocked(maintenancePrefill).mock.calls[0]
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('')
		await field(wrapper, 'Title').vm.$emit('update:modelValue', ' Oil change ')
		await field(wrapper, 'Cost').vm.$emit('update:modelValue', '189,90')
		await dropdown(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'service', label: 'Service' })
		await field(wrapper, 'Vendor').vm.$emit('update:modelValue', 'ATU Nord')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148.402')
		await field(wrapper, 'Engine hours').vm.$emit('update:modelValue', '5011')
		await wrapper.findComponent(NcTextArea).vm.$emit('update:modelValue', 'Filter too')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordMaintenance).toHaveBeenCalledWith('v-1', {
			client_uuid: expect.any(String),
			done_at: at,
			done_at_off: off,
			title: 'Oil change',
			type: 'service',
			vendor: 'ATU Nord',
			notes: 'Filter too',
			cost: 18990,
			vat_rate: 1900,
			odo: 148402,
			second_odo: 5011,
		})
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	it('sends only what a maintenance record was given', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'maintenance')
		await flushPromises()
		await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Wipers')
		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(Object.keys(vi.mocked(recordMaintenance).mock.calls[0][1]).sort())
			.toEqual(['client_uuid', 'done_at', 'done_at_off', 'title'])
		expect(field(wrapper, 'Engine hours')).toBeUndefined()
	})

	it('offers the vendors this vehicle has used', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'maintenance')
		await flushPromises()

		const list = wrapper.find(`datalist#${field(wrapper, 'Vendor').attributes('list')}`)
		expect(list.findAll('option').map((one) => one.attributes('value'))).toEqual(['ATU Nord', 'Reifen Müller'])
	})

	describe('closing a reminder', () => {
		const OIL = { uuid: 'rem-oil', template_key: 'oil_change', title: null, mode: 'either', due_date: '2026-10-31', due_odo: 150000, estimate: null, state: 'due' }
		const TYRES = { uuid: 'rem-tyres', template_key: 'tyre_swap', title: null, mode: 'date', due_date: '2026-10-15', due_odo: null, estimate: null, state: 'warned' }
		const TOWBAR = { uuid: 'rem-towbar', template_key: null, title: 'Towbar', mode: 'date', due_date: '2026-06-01', due_odo: null, estimate: null, state: 'done' }

		/**
		 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
		 * @return {any[]} the reminder chips, in the order they are offered
		 */
		function chips(wrapper) {
			return wrapper.findAll('.sheet__closes').flatMap((group) => group.findAllComponents(NcButton))
		}

		/**
		 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
		 * @return {string[]} the pressed chips, by their words
		 */
		function pressed(wrapper) {
			return chips(wrapper).filter((one) => one.props('pressed')).map((one) => one.text())
		}

		beforeEach(() => {
			vi.mocked(listReminders).mockResolvedValue(/** @type {any} */ ([TOWBAR, TYRES, OIL]))
		})

		/**
		 * Picked only when the work is the most urgent one's kind: a wider guess closes a reminder
		 * nobody meant.
		 */
		it('offers the open reminders and picks the most urgent once the work is its kind', async () => {
			const wrapper = sheet()

			await choose(wrapper, 'Entry type', 'maintenance')
			await flushPromises()
			expect(chips(wrapper).map((one) => one.text())).toEqual(['Oil change', 'Tyre swap'])
			expect(pressed(wrapper)).toEqual([])

			await dropdown(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'tyres', label: 'Tyres' })
			expect(pressed(wrapper)).toEqual([])
			await dropdown(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'service', label: 'Service' })
			expect(pressed(wrapper)).toEqual(['Oil change'])

			await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Oil change')
			await saveButton(wrapper).vm.$emit('click')
			await flushPromises()
			expect(vi.mocked(recordMaintenance).mock.calls[0][1]).toMatchObject({ type: 'service', closes: 'rem-oil' })
		})

		/** A chip somebody tapped is their word, and the type no longer moves it. */
		it('keeps the chip that was tapped, and sends none once it is tapped off', async () => {
			const wrapper = sheet()
			await choose(wrapper, 'Entry type', 'maintenance')
			await flushPromises()

			await chips(wrapper)[1].vm.$emit('update:pressed', true)
			await dropdown(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'service', label: 'Service' })
			expect(pressed(wrapper)).toEqual(['Tyre swap'])

			await chips(wrapper)[1].vm.$emit('update:pressed', false)
			await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Oil change')
			await saveButton(wrapper).vm.$emit('click')
			await flushPromises()
			expect(vi.mocked(recordMaintenance).mock.calls[0][1]).not.toHaveProperty('closes')
		})

		/** "Done" in the due banner opens the sheet on a Maintenance Record with that reminder picked. */
		it('opens on maintenance with the reminder it was asked to close', async () => {
			const wrapper = shallowMount(EntrySheet, {
				props: { vehicle: VEHICLE, entry: null, closes: 'rem-tyres' },
				global: { renderStubDefaultSlot: true, stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } } },
			})
			await flushPromises()

			expect(chooser(wrapper, 'Entry type').props('modelValue')).toBe('maintenance')
			expect(pressed(wrapper)).toEqual(['Tyre swap'])
			await dropdown(wrapper, 'Type').vm.$emit('update:modelValue', { id: 'service', label: 'Service' })
			expect(pressed(wrapper)).toEqual(['Tyre swap'])
		})

		/**
		 * An edit is a full replace, so it sends the link back, offered even when that occurrence
		 * is over.
		 */
		it('keeps what the record closed when it is edited', async () => {
			const wrapper = sheet(VEHICLE, /** @type {any} */ ({
				type: 'maintenance',
				occurred_at: 1780000000,
				occurred_at_off: 120,
				closes: 'rem-towbar',
				maintenance: { uuid: 'm-1', updated_at: 1780000000, title: 'Towbar fitted', type: 'upgrade', vendor: null, cost: null, vat_rate: null, odo: null, second_odo: null, notes: null },
			}))
			await flushPromises()

			expect(chips(wrapper).map((one) => one.text())).toEqual(['Towbar', 'Oil change', 'Tyre swap'])
			expect(pressed(wrapper)).toEqual(['Towbar'])
			await saveButton(wrapper).vm.$emit('click')
			await flushPromises()
			expect(vi.mocked(updateEntry).mock.calls[0][3]).toMatchObject({ closes: 'rem-towbar' })
		})
	})

	it('writes nothing when a maintenance record has no title', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'maintenance')
		await field(wrapper, 'Cost').vm.$emit('update:modelValue', '50')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordMaintenance).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('A maintenance record needs a title.')
	})

	/** An Expense asks for no counter: insurance or a toll says nothing about the dashboard. */
	it('writes an expense as the columns hold it', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'expense')
		await flushPromises()
		const [, at, off] = vi.mocked(expensePrefill).mock.calls[0]
		expect(labels(wrapper)).toEqual(['Amount', 'VAT rate (%)'])
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')
		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '640,00')
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await wrapper.findComponent(NcTextArea).vm.$emit('update:modelValue', ' Full year ')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordExpense).toHaveBeenCalledWith('v-1', {
			client_uuid: expect.any(String),
			spent_at: at,
			spent_at_off: off,
			amount: 64000,
			category: 'insurance',
			vat_rate: 1900,
			notes: 'Full year',
		})
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/** A VAT-free category opens the rate at 0, another restores the day's; a typed rate stays. */
	it('asks for the rate again when the category changes', async () => {
		vi.mocked(expensePrefill).mockImplementation(async (uuid, at, off, category) => ({ vat_rate: category === 'insurance' ? 0 : 1900 }))
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await flushPromises()
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await flushPromises()
		expect(vi.mocked(expensePrefill).mock.lastCall?.[3]).toBe('insurance')
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('0')

		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'toll', label: 'Toll' })
		await flushPromises()
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')

		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '7')
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await flushPromises()
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('7')
	})

	/** A prefilled 0 is a stated rate, and goes out as one rather than as "not stated". */
	it('sends the 0 a VAT-free category is prefilled with', async () => {
		vi.mocked(expensePrefill).mockImplementation(async (uuid, at, off, category) => ({ vat_rate: category === 'insurance' ? 0 : 1900 }))
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await flushPromises()
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await flushPromises()
		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '300')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(vi.mocked(recordExpense).mock.calls[0][1]).toHaveProperty('vat_rate', 0)
	})

	/** No category is picked for the person. */
	it('sends only what an expense was given', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await flushPromises()
		expect(dropdown(wrapper, 'Category').props('modelValue')).toBeNull()
		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '4.50')
		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(Object.keys(vi.mocked(recordExpense).mock.calls[0][1]).sort())
			.toEqual(['amount', 'client_uuid', 'spent_at', 'spent_at_off'])
	})

	it('writes nothing when an expense has no amount', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordExpense).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not an amount.')
	})

	/** Escape in a date field belongs to the browser's picker (EntrySheet.vue, keepPicker()). */
	it('stays open when Esc is pressed in a date field', async () => {
		const wrapper = sheet()

		await moment(wrapper, 'Departure').trigger('keydown.esc')
		await moment(wrapper, 'Arrival').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})
})

describe('the entry sheet on a phone', () => {
	/** The vehicle's last trip, as the prefill states it: ended on 148 320 at 09:45 on 15 January. */
	const LAST = { end_odo: 148320, ended_at: Math.floor(ARRIVAL.getTime() / 1000), ended_at_off: 60 }

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
	 * @return {string|undefined} what the sheet says above its fields
	 */
	function said(wrapper) {
		return wrapper.findComponent(NcNoteCard).props('text')
	}

	/** Said before sending: a phone's round trip is slow, and the server's words name columns. */
	it('says the arrival comes after the departure before it sends anything', async () => {
		const wrapper = sheet()

		await moment(wrapper, 'Departure').vm.$emit('update:modelValue', ARRIVAL)
		await moment(wrapper, 'Arrival').vm.$emit('update:modelValue', DEPARTURE)
		await field(wrapper, 'Odometer at arrival').vm.$emit('update:modelValue', '148402')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordTrip).not.toHaveBeenCalled()
		expect(said(wrapper)).toBe('The arrival comes after the departure.')
	})

	it('asks for the counter at the end or the distance', async () => {
		const wrapper = sheet()

		await record(wrapper, { 'Odometer at departure': '148320' })
		expect(said(wrapper)).toBe('Enter the counter at the end, or the distance.')

		await choose(wrapper, 'Counter or distance', 'distance')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(said(wrapper)).toBe('Enter the counter at the end, or the distance.')
		expect(recordTrip).not.toHaveBeenCalled()
	})

	it('says the counter at the end is below the start', async () => {
		const wrapper = sheet()

		await record(wrapper, { 'Odometer at departure': '148402', 'Odometer at arrival': '148320' })

		expect(recordTrip).not.toHaveBeenCalled()
		expect(said(wrapper)).toBe('The counter at the end is below the start.')
	})

	/** Offered, never filled in: the start counter is the driver's claim, and a tap makes it so. */
	it('offers the counter the last trip ended at, and takes it on one tap', async () => {
		vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: null, last: LAST })
		const wrapper = sheet()
		await flushPromises()

		expect(field(wrapper, 'Odometer at departure').props('modelValue')).toBe('')
		expect(wrapper.find('.sheet__last').text()).toContain('Last trip ended at 148,320')
		await wrapper.findAllComponents(NcButton).find((one) => one.text() === 'Use it')?.vm.$emit('click')

		expect(field(wrapper, 'Odometer at departure').props('modelValue')).toBe('148320')
	})

	it('offers nothing when the last trip named no counter', async () => {
		vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: null, last: { ...LAST, end_odo: null } })
		const wrapper = sheet()
		await flushPromises()

		expect(wrapper.find('.sheet__last').exists()).toBe(false)
	})

	/** The category this person last chose here, or none: the claim is theirs to make. */
	it('opens on the category this person last used on this vehicle, else on none', async () => {
		vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: 'private', last: null })
		const used = sheet()
		await flushPromises()
		expect(dropdown(used, 'Category').props('modelValue').id).toBe('private')

		vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: null, last: null })
		const first = sheet()
		await flushPromises()
		expect(dropdown(first, 'Category').props('modelValue')).toBeNull()
		await record(first, { 'Odometer at arrival': '148402' })
		expect(said(first)).toBe('Choose a category for the trip.')
	})

	describe('the departure', () => {
		beforeEach(() => {
			vi.useFakeTimers({ toFake: ['Date'] })
			vi.setSystemTime(new Date(2026, 0, 15, 12, 0))
		})

		afterEach(() => {
			vi.useRealTimers()
		})

		it('defaults to the last trip\'s arrival when that was today', async () => {
			vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: null, last: LAST })
			const wrapper = sheet()
			await flushPromises()

			expect(moment(wrapper, 'Departure').props('modelValue')).toEqual(ARRIVAL)
		})

		it('stays now when the last trip arrived on another day', async () => {
			const yesterday = Math.floor(new Date(2026, 0, 14, 18, 0).getTime() / 1000)
			vi.mocked(tripPrefill).mockResolvedValue({ places: [], purposes: [], partners: [], category: null, last: { ...LAST, ended_at: yesterday } })
			const wrapper = sheet()
			await flushPromises()

			expect(moment(wrapper, 'Departure').props('modelValue')).toEqual(new Date(2026, 0, 15, 12, 0))
		})
	})

	it('fills in the title and the type of the reminder it closes', async () => {
		vi.mocked(listReminders).mockResolvedValue(/** @type {any} */ ([
			{ uuid: 'rem-tyres', template_key: 'tyre_swap', title: null, mode: 'date', due_date: '2026-10-15', due_odo: null, estimate: null, state: 'warned' },
			{ uuid: 'rem-towbar', template_key: null, title: 'Towbar', mode: 'date', due_date: '2026-10-01', due_odo: null, estimate: null, state: 'due' },
		]))

		/** @param {string} closes - the reminder Done was tapped on */
		const done = (closes) => shallowMount(EntrySheet, {
			props: { vehicle: VEHICLE, entry: null, closes },
			global: { renderStubDefaultSlot: true, stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } } },
		})
		const tyres = done('rem-tyres')
		const towbar = done('rem-towbar')
		await flushPromises()

		expect(field(tyres, 'Title').props('modelValue')).toBe('Tyre swap')
		expect(dropdown(tyres, 'Type').props('modelValue').id).toBe('tyres')
		expect(field(towbar, 'Title').props('modelValue')).toBe('Towbar')
		expect(dropdown(towbar, 'Type').props('modelValue')).toBeNull()
	})

	it('asks a fill-up for the counter right after the total', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')

		expect(labels(wrapper)).toEqual(['Amount (l)', 'Total price', 'Counter reading', 'Engine hours', 'Station', 'Price per litre', 'VAT rate (%)'])
	})

	/** A counter is whole, so a phone opens its number pad, not the one with a decimal key. */
	it('opens the number pad for every whole-number counter', async () => {
		const wrapper = sheet(TRUCK)

		expect(field(wrapper, 'Odometer at departure').attributes('inputmode')).toBe('numeric')
		expect(field(wrapper, 'Odometer at arrival').attributes('inputmode')).toBe('numeric')
		await choose(wrapper, 'Counter or distance', 'distance')
		expect(field(wrapper, 'Distance').attributes('inputmode')).toBe('numeric')
		await choose(wrapper, 'Entry type', 'maintenance')
		expect(field(wrapper, 'Counter reading').attributes('inputmode')).toBe('numeric')
		expect(field(wrapper, 'Engine hours').attributes('inputmode')).toBe('numeric')
		expect(field(wrapper, 'Cost').attributes('inputmode')).toBe('decimal')
		await choose(wrapper, 'Entry type', 'odometer')
		expect(field(wrapper, 'Counter reading').attributes('inputmode')).toBe('numeric')
	})

	/** A receipt's `1.234,56` is read as printed; a grouping that cannot be read says how to type it. */
	it('reads a grouped amount, and says how to type one it cannot read', async () => {
		vi.mocked(recordExpense).mockResolvedValue(/** @type {any} */ ({ uuid: 'x-1' }))
		const wrapper = sheet()
		await choose(wrapper, 'Entry type', 'expense')

		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '1,234.56')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(recordExpense).not.toHaveBeenCalled()
		expect(said(wrapper)).toBe('Type the amount without a thousands separator, e.g. 1234,56.')

		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '1.234,56')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		expect(recordExpense).toHaveBeenCalledWith('v-1', expect.objectContaining({ amount: 123456 }))
	})
})

describe('the entry sheet on an Entry from the timeline', () => {
	/** A fill-up as its timeline row carries it: partial, with a price, a rate and a counter. */
	const FILL = {
		uuid: 'e-1',
		updated_at: 1750000000,
		filled_at: 1788391800,
		filled_at_off: 120,
		energy: 'diesel',
		amount: 48200,
		total: 8210,
		unit_price: 1703,
		vat_rate: 1900,
		odo: 148400,
		second_odo: null,
		full_tank: false,
		missed_previous: false,
		station: 'Aral Hauptstr.',
		is_dc: false,
		location_kind: null,
	}
	const FILL_ROW = { type: 'energy', occurred_at: 1788391800, occurred_at_off: 120, energy: FILL, readings: [], flags: [], may: ['edit', 'delete'] }

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
	 * @param {string} text - what the button says
	 * @return {any} the button, or undefined when the sheet has none saying that
	 */
	function button(wrapper, text) {
		return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
	}

	beforeEach(() => {
		vi.mocked(updateEntry).mockImplementation(async (uuid, type, entry, fields) => ({ ...entry, ...fields, updated_at: entry.updated_at + 1 }))
		vi.mocked(deleteEntry).mockImplementation(async (uuid, type, entry) => ({ ...entry, updated_at: entry.updated_at + 1 }))
	})

	it('opens on the Entry with what it says, and offers no other kind', async () => {
		const wrapper = sheet(TRUCK, FILL_ROW)
		await flushPromises()

		expect(wrapper.findComponent(NcDialog).attributes('name')).toBe('Edit entry')
		expect(chooser(wrapper, 'Entry type')).toBeUndefined()
		expect(field(wrapper, 'Amount (l)').props('modelValue')).toBe('48.2')
		expect(field(wrapper, 'Total price').props('modelValue')).toBe('82.1')
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('148400')
		expect(field(wrapper, 'Engine hours').props('modelValue')).toBe('')
		expect(field(wrapper, 'Station').props('modelValue')).toBe('Aral Hauptstr.')
		expect(toggle(wrapper, 'Full tank').props('modelValue')).toBe(false)
		expect(moment(wrapper, 'Date').props('modelValue')).toEqual(new Date(1788391800 * 1000))
	})

	/** "Not stated" is the person's word, not a gap the jurisdiction fills on the next read. */
	it('keeps a rate that was not stated', async () => {
		const wrapper = sheet(TRUCK, { ...FILL_ROW, energy: { ...FILL, vat_rate: null } })
		await flushPromises()

		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('')
	})

	/** A stored 0 is a stated rate: it opens as 0 and goes back as 0, not as "not stated". */
	it('keeps a rate of 0 through an edit', async () => {
		const wrapper = sheet(TRUCK, { ...FILL_ROW, energy: { ...FILL, vat_rate: 0 } })
		await flushPromises()

		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('0')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(vi.mocked(updateEntry).mock.calls[0][3]).toHaveProperty('vat_rate', 0)
	})

	/** The derived price is not sent, so a corrected total is not contradicted by the old price. */
	it('writes the whole Entry back under the token it was read with', async () => {
		const wrapper = sheet(TRUCK, FILL_ROW)
		await field(wrapper, 'Total price').vm.$emit('update:modelValue', '84,10')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(updateEntry).toHaveBeenCalledWith('v-1', 'energy', FILL, {
			filled_at: 1788391800,
			filled_at_off: -new Date(1788391800 * 1000).getTimezoneOffset(),
			energy: 'diesel',
			full_tank: false,
			missed_previous: false,
			amount: 48200,
			total: 8410,
			vat_rate: 1900,
			odo: 148400,
			station: 'Aral Hauptstr.',
		})
		expect(recordEnergy).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/** Undo writes the Entry as opened, price included, under the token the edit answered with. */
	it('leaves the way back to what the Entry said with the store', async () => {
		const wrapper = sheet(TRUCK, FILL_ROW)
		await field(wrapper, 'Total price').vm.$emit('update:modelValue', '84,10')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(useVehiclesStore().saved).toEqual({
			vehicle: 'v-1',
			type: 'energy',
			entry: expect.objectContaining({ uuid: 'e-1', updated_at: 1750000001 }),
			before: expect.objectContaining({ total: 8210, unit_price: 1703, amount: 48200, full_tank: false }),
		})
	})

	it('corrects an Odometer Entry at the moment it was read', async () => {
		const reading = { uuid: 'r-1', updated_at: 1750000000, read_at: 1788217200, read_at_off: 60, value: 148320, counter: 'second', flagged: true }
		const wrapper = sheet(TRUCK, { type: 'odometer', occurred_at: 1788217200, occurred_at_off: 60, odometer: reading })

		expect(chooser(wrapper, 'Which counter').props('modelValue')).toBe('second')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('148320')
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '5011')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(updateEntry).toHaveBeenCalledWith('v-1', 'odometer', reading, { value: 5011, counter: 'second', read_at: 1788217200, read_at_off: 60 })
	})

	/** What is on screen wins, under the token read back (docs/ui.md#the-entry-sheet-in-detail). */
	it('offers to save anyway when the Entry moved on, under the token read back', async () => {
		vi.mocked(updateEntry).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		vi.mocked(readEntry).mockResolvedValue(/** @type {any} */ ({ ...FILL_ROW, energy: { ...FILL, amount: 50000, updated_at: 1750000900 } }))
		const wrapper = sheet(TRUCK, FILL_ROW)
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('changed somewhere else')
		expect(saveButton(wrapper).text()).toBe('Save anyway')

		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(readEntry).toHaveBeenCalledWith('v-1', 'energy', 'e-1')
		expect(updateEntry).toHaveBeenLastCalledWith('v-1', 'energy', expect.objectContaining({ updated_at: 1750000900 }), expect.objectContaining({ amount: 48200 }))
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/** Nothing asks "are you sure?": the way back is the toast's (src/components/UndoToast.vue). */
	it('deletes the Entry and leaves the way back with the store', async () => {
		const wrapper = sheet(TRUCK, FILL_ROW)

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(deleteEntry).toHaveBeenCalledWith('v-1', 'energy', FILL)
		expect(useVehiclesStore().struck).toEqual({ vehicle: 'v-1', type: 'energy', entry: { ...FILL, updated_at: 1750000001 } })
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/** The row says what the reader may do to it (TimelineService::withMay()); the sheet offers no more. */
	it('offers no delete on a row that does not carry it', () => {
		const wrapper = sheet(TRUCK, { ...FILL_ROW, may: ['edit'] })

		expect(button(wrapper, 'Delete')).toBeUndefined()
		expect(saveButton(wrapper)).toBeDefined()
	})

	/** The journey opens as it was stated - here, as a distance. */
	it('voids a trip under Logbook Mode, and opens it as it was stated', () => {
		const trip = {
			uuid: 't-1',
			updated_at: 1750000000,
			started_at: 1788391800,
			started_at_off: 120,
			ended_at: 1788397200,
			ended_at_off: 120,
			start_odo: null,
			end_odo: null,
			distance: 82,
			from_label: 'Munich',
			to_label: 'Augsburg',
			purpose: 'Client visit',
			partner: 'ACME',
			category: 'private',
		}
		const wrapper = sheet({ ...VEHICLE, logbook_mode: true }, { type: 'trip', occurred_at: 1788391800, occurred_at_off: 120, trip, reading: null, may: ['edit', 'delete'] })

		expect(button(wrapper, 'Void trip')).toBeDefined()
		expect(button(wrapper, 'Delete')).toBeUndefined()
		expect(chooser(wrapper, 'Counter or distance').props('modelValue')).toBe('distance')
		expect(field(wrapper, 'Distance').props('modelValue')).toBe('82')
		expect(field(wrapper, 'Destination').props('modelValue')).toBe('Augsburg')
		expect(dropdown(wrapper, 'Category').props('modelValue').id).toBe('private')
	})
})

describe('the entry sheet on a returned booking', () => {
	const BACK = {
		uuid: 'b-8',
		state: 'returned',
		purpose: 'Client visit',
		trip_draft: { started_at: 1790935200, started_at_off: 120, ended_at: 1790940000, ended_at_off: 120, start_odo: 52000, end_odo: 52140, purpose: 'Client visit' },
		may: ['log_trip'],
	}

	/**
	 * @return {import('@vue/test-utils').VueWrapper} the sheet, opened on the trip of BACK
	 */
	function fromBooking() {
		return shallowMount(EntrySheet, {
			props: { vehicle: VEHICLE, booking: BACK },
			global: {
				renderStubDefaultSlot: true,
				stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
			},
		})
	}

	/** Whether it was business is the driver's word, so the handover does not choose it. */
	it('opens on the trip the handover describes, and leaves the category to the driver', () => {
		const wrapper = fromBooking()

		expect(chooser(wrapper, 'Entry type')).toBeUndefined()
		expect(moment(wrapper, 'Departure').props('modelValue')).toEqual(new Date(1790935200 * 1000))
		expect(moment(wrapper, 'Arrival').props('modelValue')).toEqual(new Date(1790940000 * 1000))
		expect(field(wrapper, 'Odometer at departure').props('modelValue')).toBe('52000')
		expect(field(wrapper, 'Odometer at arrival').props('modelValue')).toBe('52140')
		expect(field(wrapper, 'Purpose').props('modelValue')).toBe('Client visit')
		expect(dropdown(wrapper, 'Category').props('modelValue')).toBeNull()
	})

	it('asks for the category before it writes', async () => {
		const wrapper = fromBooking()

		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('category')
	})

	it('writes the trip tied to the booking', async () => {
		const wrapper = fromBooking()

		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'business', label: 'Business' })
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordTrip).toHaveBeenCalledWith('v-1', expect.objectContaining({
			booking_uuid: 'b-8',
			started_at: 1790935200,
			ended_at: 1790940000,
			start_odo: 52000,
			end_odo: 52140,
			category: 'business',
		}))
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/** Logged meanwhile, in another tab or by a manager. */
	it('says so when the booking has its trip already', async () => {
		vi.mocked(recordTrip).mockRejectedValue(new BookingConflictError('the booking has its trip already', /** @type {any} */ ({ uuid: 'b-8', state: 'returned' })))
		const wrapper = fromBooking()

		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'private', label: 'Private' })
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('logged as a trip already')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/**
	 * The row never offers the trip of a car not back, so only the server can say what happened.
	 */
	it('passes on any other refusal of the booking as the server words it', async () => {
		vi.mocked(recordTrip).mockRejectedValue(new BookingConflictError('only a booking whose car is back is logged as a trip', /** @type {any} */ ({ uuid: 'b-8', state: 'out' })))
		const wrapper = fromBooking()

		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'private', label: 'Private' })
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('only a booking whose car is back is logged as a trip')
	})
})

describe('the entry sheet on a receipt from the inbox', () => {
	/** When the phone saved the photo: 15 January 2026, 08:30 local. */
	const SAVED = Math.floor(new Date(2026, 0, 15, 8, 30).getTime() / 1000)

	/**
	 * @param {'energy'|'maintenance'|'expense'} kind - the cost the receipt is logged as
	 * @return {import('@vue/test-utils').VueWrapper} the sheet, opened from that receipt
	 */
	function fromReceipt(kind) {
		return shallowMount(EntrySheet, {
			props: { vehicle: TRUCK, receipt: { kind, at: SAVED } },
			global: {
				renderStubDefaultSlot: true,
				stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
			},
		})
	}

	it('opens on the cost it was asked for, dated when the file was saved', () => {
		const wrapper = fromReceipt('maintenance')

		expect(chooser(wrapper, 'Entry type')).toBeUndefined()
		expect(field(wrapper, 'Title')).toBeDefined()
		expect(moment(wrapper, 'Date').props('modelValue')).toEqual(new Date(SAVED * 1000))
	})

	/** The VAT rate is the one on the receipt's day, not today's. */
	it('asks for the prefill of that day', async () => {
		fromReceipt('energy')
		await flushPromises()

		expect(energyPrefill).toHaveBeenCalledWith('v-1', SAVED, -new Date(SAVED * 1000).getTimezoneOffset())
	})

	/** The screen behind files the receipt on it, and reads back only what that kind can change. */
	it('tells which entry it wrote, and its kind', async () => {
		vi.mocked(recordExpense).mockResolvedValue({ uuid: 'x-5', amount: 4250 })
		const wrapper = fromReceipt('expense')

		await field(wrapper, 'Amount').vm.$emit('update:modelValue', '42,50')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.emitted('saved')).toEqual([[{ uuid: 'x-5', amount: 4250 }, 'expense']])
	})
})
