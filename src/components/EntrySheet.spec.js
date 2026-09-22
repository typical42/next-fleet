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
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, deleteEntry, energyPrefill, expensePrefill, getVehicle, listReminders, maintenancePrefill, readEntry, recordEnergy, recordExpense, recordMaintenance, recordReading, recordTrip, updateEntry } from '../services/api.js'
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
	updateEntry: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_value: 148320 }
const TRUCK = { ...VEHICLE, odo_unit: 'km', second_unit: 'h', second_value: 5004, energy_types: ['diesel'] }
const HYBRID = { ...VEHICLE, odo_unit: 'km', energy_types: ['petrol', 'electric'] }

/** The two moments of one journey, an hour and a quarter apart. */
const DEPARTURE = new Date(2026, 0, 15, 8, 30)
const ARRIVAL = new Date(2026, 0, 15, 9, 45)

/**
 * The sheet, mounted. shallowMount renders no stub's slots and every field sits inside the dialog,
 * so that one component is rendered and the rest stay stubs (docs/development.md). The choosers
 * hold their choices in their own slot, so the stubs render theirs.
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
 * The sheet's own button: the last of the two the dialog's actions hold, because a failed save
 * renames it (docs/ui.md).
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
	/**
	 * `Esc` closes the sheet and NcDialog already gives it (docs/ui.md), so nothing here listens
	 * for the key. What is pinned is the one wire it travels along: the dialog reports itself
	 * closed and the sheet leaves. A sheet that bound `:open` and no listener would swallow the
	 * key silently and never reopen.
	 */
	it('closes when the dialog reports itself closed', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * The other half of the key, and the half NcDialog does not give: its own Escape handler is a
	 * useHotKey, and useHotKey passes over every keystroke aimed at a text field. This sheet opens
	 * with the caret in one, so Escape would otherwise reach nobody.
	 */
	it('closes when Esc is pressed in a field', async () => {
		const wrapper = sheet()

		await wrapper.find('.sheet').trigger('keydown.esc')

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * A journey is what a logbook is for and what a driver enters daily; the counter on its own is
	 * the escape hatch for everything not otherwise recorded (docs/ui.md). So the sheet opens on
	 * the trip and the other kinds are one tap away.
	 */
	it('opens on a trip and offers the other kinds beside it, the expense last', () => {
		const wrapper = sheet()

		expect(choices(wrapper, 'Entry type')).toEqual(['Trip', 'Maintenance', 'Odometer', 'Expense'])
		expect(chooser(wrapper, 'Entry type').props('modelValue')).toBe('trip')
	})

	/** The escape hatch is one number and stays one number (docs/ui.md). */
	it('asks for nothing but the counter under the odometer', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'odometer')

		expect(labels(wrapper)).toEqual(['Counter reading'])
		expect(moment(wrapper, 'Departure')).toBeUndefined()
		expect(chooser(wrapper, 'Counter or distance')).toBeUndefined()
		expect(chooser(wrapper, 'Which counter')).toBeUndefined()
	})

	/**
	 * A truck that also counts engine hours has two chains (docs/architecture.md#odometer-rules,
	 * rule 4), so the one number has to say which it is. Kilometres first: the chain trips run on.
	 */
	it('asks which counter it reads on a vehicle that counts engine hours', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'odometer')

		expect(choices(wrapper, 'Which counter')).toEqual(['Kilometres', 'Engine hours'])
		expect(chooser(wrapper, 'Which counter').props('modelValue')).toBe('main')
		expect(field(wrapper, 'Counter reading').props('modelValue')).toBe('148320')
	})

	/** The hours are prefilled as they stand, like the kilometres, and land on their own chain. */
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

	/**
	 * Everything that can be prefilled is prefilled (docs/ui.md), and the two counters are the one
	 * thing that cannot be: `start_odo` is a claim about what the dashboard read when the journey
	 * set off (docs/architecture.md#odometer-rules), and the vehicle's own counter is not that
	 * claim. Filling it in would answer the question gap detection exists to ask.
	 */
	it('prefills what it knows and claims nothing about the counter', () => {
		const wrapper = sheet()

		expect(field(wrapper, 'Start counter').props('modelValue')).toBe('')
		expect(field(wrapper, 'End counter').props('modelValue')).toBe('')
		expect(dropdown(wrapper, 'Category').props('modelValue').id).toBe('business')
		expect(moment(wrapper, 'Departure').props('modelValue')).toBeInstanceOf(Date)
		expect(moment(wrapper, 'Arrival').props('modelValue')).toBeInstanceOf(Date)
	})

	/**
	 * The driver knows one of the two, never both, and the toggle is which one they are being
	 * asked for. A counter the trip set off on is a claim rather than a reading
	 * (docs/architecture.md#odometer-rules), so it belongs to the counter half - a distance is
	 * counted from the chain and nothing is claimed about where the journey started.
	 */
	it('asks for a distance instead of the two counters when that is what the driver knows', async () => {
		const wrapper = sheet()
		expect(choices(wrapper, 'Counter or distance')).toEqual(['Counter', 'Distance'])

		await choose(wrapper, 'Counter or distance', 'distance')

		expect(field(wrapper, 'Distance').props('modelValue')).toBe('')
		expect(field(wrapper, 'Start counter')).toBeUndefined()
		expect(field(wrapper, 'End counter')).toBeUndefined()
	})

	/**
	 * Every instant is two facts - when it happened and the offset it was entered at
	 * (docs/architecture.md#time) - and the offset is the moment's own, so a January trip typed up
	 * in July is stated as it was driven. What the journey did to the counter is not computed here:
	 * the two counters travel as they were typed.
	 */
	it('writes the journey and the counter it ended on', async () => {
		const wrapper = sheet()

		await record(wrapper, {
			'Start counter': '148.320',
			'End counter': '148.402',
			Purpose: 'Kundentermin',
			'Starting point': 'München',
			Destination: 'Augsburg',
			'Business partner': 'Huber GmbH',
		})

		expect(recordTrip).toHaveBeenCalledWith('v-1', {
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
	 * A distance leaves the counter it set off on unsaid (docs/architecture.md#odometer-rules):
	 * the Reading is counted from the chain, and a claim the sheet invented would be spent before
	 * gap detection could measure it.
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
	 * An empty field is a question the driver did not answer, and the sheet never answers it for
	 * them: what the row is missing is the server's to judge and the timeline's to ask about
	 * (docs/features.md#logbook-mode).
	 */
	it('leaves out the counter the driver did not read', async () => {
		const wrapper = sheet()

		await record(wrapper, { 'End counter': '148402' })

		expect(vi.mocked(recordTrip).mock.calls[0][1]).not.toHaveProperty('start_odo')
		expect(recordTrip).toHaveBeenCalledWith('v-1', expect.objectContaining({ end_odo: 148402 }))
	})

	/**
	 * The screen behind the sheet is the one listing what was just written, and a sheet that only
	 * reports itself closed tells it nothing: a cancel closes too (src/views/VehicleView.vue).
	 */
	it('reports the write, which closing on a cancel does not', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)
		expect(wrapper.emitted('saved')).toBeUndefined()

		await record(wrapper, { 'End counter': '148402' })

		expect(wrapper.emitted('saved')?.length).toBe(1)
	})

	/** The escape hatch still writes one Reading, at the moment it was read (docs/ui.md). */
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

	/**
	 * A failed save is never lost (docs/ui.md): the sheet stays open with every value intact and
	 * offers the retry, because the open sheet is the queue and nothing is written anywhere else.
	 */
	it('stays open with every value intact when the write is refused', async () => {
		vi.mocked(recordTrip).mockRejectedValue(new Error('ended_at is not before started_at'))
		const wrapper = sheet()

		await record(wrapper, { 'End counter': '148402', Purpose: 'Kundentermin' })

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('ended_at is not before started_at')
		expect(field(wrapper, 'End counter').props('modelValue')).toBe('148402')
		expect(field(wrapper, 'Purpose').props('modelValue')).toBe('Kundentermin')
		expect(moment(wrapper, 'Departure').props('modelValue')).toBe(DEPARTURE)
		expect(saveButton(wrapper).text()).toBe('Try again')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/**
	 * A field nobody can read is a question for the driver rather than a number to round: `7,2` is
	 * not a counter (src/utils/format.js). Asked here rather than by the server, which would spend
	 * a round trip to answer in its own words.
	 */
	it('writes nothing when the kilometres are not kilometres', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Counter or distance', 'distance')
		await record(wrapper, { Distance: '7,2' })

		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not a distance.')
		expect(field(wrapper, 'Distance').props('modelValue')).toBe('7,2')
	})

	/**
	 * An Odometer Entry is the number and nothing else (CONTEXT.md), so an empty field is not an
	 * entry with a question left open - it is nothing to record, and asking here saves the driver
	 * a round trip that comes back in the server's own words.
	 */
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

	/**
	 * A date field the driver cleared leaves the picker holding null, and a journey with no moment
	 * is one no logbook can place: the instant is what every other row is ordered against
	 * (docs/architecture.md#time). Said in the sheet's own words rather than thrown as whatever a
	 * null does to the arithmetic.
	 */
	it('writes nothing when a trip has lost one of its two moments', async () => {
		const wrapper = sheet()

		await moment(wrapper, 'Departure').vm.$emit('update:modelValue', null)
		await field(wrapper, 'End counter').vm.$emit('update:modelValue', '148402')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordTrip).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text'))
			.toBe('A trip carries the moment it set off and the moment it arrived.')
	})

	/**
	 * A fill-up is of an energy the vehicle takes (docs/architecture.md#data-model), so a vehicle
	 * that names none is not offered one - there would be nothing to choose from.
	 */
	it('offers energy only to a vehicle that takes some', () => {
		expect(choices(sheet(), 'Entry type')).toEqual(['Trip', 'Maintenance', 'Odometer', 'Expense'])
		expect(choices(sheet(HYBRID), 'Entry type')).toEqual(['Trip', 'Energy', 'Maintenance', 'Odometer', 'Expense'])
	})

	/** A plug-in hybrid logs either of its two energies, and nothing else (docs/ui.md). */
	it('asks which of the vehicle energies a fill-up is of', async () => {
		const wrapper = sheet(HYBRID)

		await choose(wrapper, 'Entry type', 'energy')

		expect(dropdown(wrapper, 'Energy').props('options').map((/** @type {any} */ one) => one.label))
			.toEqual(['Petrol', 'Electric'])
		expect(dropdown(wrapper, 'Energy').props('modelValue').id).toBe('petrol')
		expect(field(wrapper, 'Amount (l)')).toBeDefined()
	})

	/**
	 * Amounts and money are typed as a pump shows them and sent as the integers their columns hold
	 * (docs/architecture.md#data-model). The VAT rate is the jurisdiction's on the day, asked of the
	 * server for the moment the sheet is on, and "full tank" is on because it usually is.
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

	/**
	 * The station completes from this vehicle's history and prefills the price it last charged
	 * for this energy (docs/ui.md). A total answers the price better than a guess does, so the
	 * guess is not sent beside one - the server derives it instead.
	 */
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

	/**
	 * The prefilled price belongs to one station and one energy. Another station, or another
	 * energy, is asked afresh, and one with no price leaves the field empty rather than carrying
	 * the last station's guess into this fill-up.
	 */
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

	/**
	 * A charge is at home or in public, and only a public charger offers direct current. The sheet
	 * does not answer where for the driver.
	 */
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
		expect(field(wrapper, 'Counter reading').props('helperText')).toBe('Consumption needs the counter reading.')

		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148402')
		expect(field(wrapper, 'Counter reading').props('helperText')).toBe('')
	})

	/**
	 * The rate changes on a day, so a fill-up dated back is asked about again - and a rate the
	 * driver typed is theirs and stays.
	 */
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

	/** The amount is the one field a fill-up requires (docs/ui.md), so it is asked for here. */
	it('writes nothing when a fill-up has no amount', async () => {
		const wrapper = sheet(TRUCK)

		await choose(wrapper, 'Entry type', 'energy')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordEnergy).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not an amount.')
	})

	/**
	 * A Maintenance Record asks for its title and cost (docs/ui.md), and sends money and rates as
	 * the integers their columns hold. The VAT is the jurisdiction's on the day, and the counters,
	 * as on a fill-up, are never prefilled.
	 */
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

	/** Everything but the title may be left out, and what was left out is not sent. */
	it('sends only what a maintenance record was given', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'maintenance')
		await flushPromises()
		await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Wipers')
		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(Object.keys(vi.mocked(recordMaintenance).mock.calls[0][1]).sort())
			.toEqual(['done_at', 'done_at_off', 'title'])
		expect(field(wrapper, 'Engine hours')).toBeUndefined()
	})

	/** The vendor completes from this vehicle's history, the latest first (docs/ui.md). */
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
		 * The open reminders, most urgent first. Nothing is picked until the work is a kind the most
		 * urgent one is: a guess past it closes a reminder nobody meant.
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
		 * An edit sends back the reminder the record closed - a full replace would otherwise take the
		 * link away - and offers it even when that occurrence is over.
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

	/** The title is the one field a Maintenance Record requires, so it is asked for here. */
	it('writes nothing when a maintenance record has no title', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'maintenance')
		await field(wrapper, 'Cost').vm.$emit('update:modelValue', '50')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordMaintenance).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('A maintenance record needs a title.')
	})

	/**
	 * An Expense asks for its amount, and a category, the VAT and notes beside it (docs/ui.md). The
	 * VAT is the jurisdiction's on the day, and it asks for no counter: an Expense has none.
	 */
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
			spent_at: at,
			spent_at_off: off,
			amount: 64000,
			category: 'insurance',
			vat_rate: 1900,
			notes: 'Full year',
		})
		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * A category the jurisdiction charges no VAT on is asked about, and opens the rate empty; the
	 * next category puts the day's rate back. A rate the person typed is theirs and stays.
	 */
	it('asks for the rate again when the category changes', async () => {
		vi.mocked(expensePrefill).mockImplementation(async (uuid, at, off, category) => ({ vat_rate: category === 'insurance' ? null : 1900 }))
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await flushPromises()
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await flushPromises()
		expect(vi.mocked(expensePrefill).mock.lastCall?.[3]).toBe('insurance')
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('')

		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'toll', label: 'Toll' })
		await flushPromises()
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('19')

		await field(wrapper, 'VAT rate (%)').vm.$emit('update:modelValue', '7')
		await dropdown(wrapper, 'Category').vm.$emit('update:modelValue', { id: 'insurance', label: 'Insurance' })
		await flushPromises()
		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('7')
	})

	/** Everything but the amount may be left out; no category is picked for the person. */
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
			.toEqual(['amount', 'spent_at', 'spent_at_off'])
	})

	/** The amount is the one field an Expense requires, so it is asked for here. */
	it('writes nothing when an expense has no amount', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'expense')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(recordExpense).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not an amount.')
	})

	/**
	 * Escape in a date field belongs to the picker the browser opened over it, not to the sheet.
	 * Chromium dismisses that picker on the key and delivers the keydown to the input all the same,
	 * so a sheet that took it would close over a typed-up journey.
	 */
	it('stays open when Esc is pressed in a date field', async () => {
		const wrapper = sheet()

		await moment(wrapper, 'Departure').trigger('keydown.esc')
		await moment(wrapper, 'Arrival').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
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
	const FILL_ROW = { type: 'energy', occurred_at: 1788391800, occurred_at_off: 120, energy: FILL, readings: [], flags: [] }

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

	/**
	 * The row is one Entry of one kind, so the sheet opens on that kind with what the Entry says,
	 * and offers no other kind: a fill-up does not become an expense.
	 */
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

	/**
	 * A rate left unstated is the person's word, and "not stated" is not a gap the jurisdiction
	 * fills on the next read (docs/architecture.md#data-model).
	 */
	it('keeps a rate that was not stated', async () => {
		const wrapper = sheet(TRUCK, { ...FILL_ROW, energy: { ...FILL, vat_rate: null } })
		await flushPromises()

		expect(field(wrapper, 'VAT rate (%)').props('modelValue')).toBe('')
	})

	/**
	 * The edit is the whole Entry under the token it was read with. The price the server derived
	 * from the total is derived again rather than pinned, so a corrected total is not contradicted
	 * by the price it replaced.
	 */
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

	/** An Odometer Entry keeps the moment it was read at: the sheet asks only for the number. */
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

	/**
	 * Somebody else changed the Entry while the sheet was open. The sheet says so, and the retry
	 * becomes _Save anyway_: it reads the Entry back and writes what is on screen under the token
	 * that came with it (docs/ui.md).
	 */
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

	/**
	 * Nothing asks "are you sure?": the delete goes, and the way back is the toast's
	 * (src/components/UndoToast.vue), which the store hands the token the delete answered with.
	 */
	it('deletes the Entry and leaves the way back with the store', async () => {
		const wrapper = sheet(TRUCK, FILL_ROW)

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(deleteEntry).toHaveBeenCalledWith('v-1', 'energy', FILL)
		expect(useVehiclesStore().struck).toEqual({ vehicle: 'v-1', type: 'energy', entry: { ...FILL, updated_at: 1750000001 } })
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/**
	 * Under Logbook Mode a trip is voided, not deleted (docs/features.md#logbook-mode); the button
	 * says what the click does. The journey opens as it was stated - here, as a distance.
	 */
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
		const wrapper = sheet({ ...VEHICLE, logbook_mode: true }, { type: 'trip', occurred_at: 1788391800, occurred_at_off: 120, trip, reading: null })

		expect(button(wrapper, 'Void trip')).toBeDefined()
		expect(button(wrapper, 'Delete')).toBeUndefined()
		expect(chooser(wrapper, 'Counter or distance').props('modelValue')).toBe('distance')
		expect(field(wrapper, 'Distance').props('modelValue')).toBe('82')
		expect(field(wrapper, 'Destination').props('modelValue')).toBe('Augsburg')
		expect(dropdown(wrapper, 'Category').props('modelValue').id).toBe('private')
	})
})
