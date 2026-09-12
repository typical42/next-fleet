/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getVehicle, recordReading, recordTrip } from '../services/api.js'
import EntrySheet from './EntrySheet.vue'

// The network is the api client's own seam (api.spec.js), and the store is left real: this sheet
// is the only caller store.log() has, so the wiring through it is part of what is under test.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	getVehicle: vi.fn(),
	recordReading: vi.fn(),
	recordTrip: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_value: 148320 }

/** The two moments of one journey, an hour and a quarter apart. */
const DEPARTURE = new Date(2026, 0, 15, 8, 30)
const ARRIVAL = new Date(2026, 0, 15, 9, 45)

/**
 * The sheet, mounted. shallowMount renders no stub's slots and every field sits inside the dialog,
 * so that one component is rendered and the rest stay stubs (docs/development.md). The choosers
 * hold their choices in their own slot, so the stubs render theirs.
 *
 * @return {import('@vue/test-utils').VueWrapper} the mounted sheet
 */
function sheet() {
	return shallowMount(EntrySheet, {
		props: { vehicle: VEHICLE },
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
	 * the trip and the other kind is one tap away.
	 */
	it('opens on a trip and offers the odometer beside it', () => {
		const wrapper = sheet()

		expect(choices(wrapper, 'Entry type')).toEqual(['Trip', 'Odometer'])
		expect(chooser(wrapper, 'Entry type').props('modelValue')).toBe('trip')
	})

	/** The escape hatch is one number and stays one number (docs/ui.md). */
	it('asks for nothing but the counter under the odometer', async () => {
		const wrapper = sheet()

		await choose(wrapper, 'Entry type', 'odometer')

		expect(labels(wrapper)).toEqual(['Counter reading'])
		expect(moment(wrapper, 'Departure')).toBeUndefined()
		expect(chooser(wrapper, 'Counter or distance')).toBeUndefined()
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
