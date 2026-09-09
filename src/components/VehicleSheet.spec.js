/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, createVehicle, deleteVehicle, getPreferences, getVehicle, recordReading, updateVehicle } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { formatDay } from '../utils/format.js'
import VehicleSheet from './VehicleSheet.vue'

// The network is the api client's own seam (api.spec.js), and the store is left real: this sheet
// is the first caller store.save() has, so the wiring through it is part of what is under test.
// ConflictError is the module's own class rather than a stand-in, because the sheet tells a
// refused write from every other failure by its type.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	createVehicle: vi.fn(),
	deleteVehicle: vi.fn(),
	getPreferences: vi.fn(),
	getVehicle: vi.fn(),
	recordReading: vi.fn(),
	updateVehicle: vi.fn(),
}))

/** A vehicle as the server hands it over, every writable column filled in. */
const VEHICLE = {
	uuid: 'v-1',
	updated_at: 1700000000,
	plate: 'B-XY 123',
	manufacturer: 'Volkswagen',
	model: 'Caddy',
	vehicle_type: 'van',
	engine: 'hybrid',
	energy_types: ['petrol', 'electric'],
	tank_ml: 55000,
	battery_wh: 13600,
	first_reg: '2019-03-07',
	disposed_at: null,
	vin: 'WVWZZZ1KZAW000001',
	odo_unit: 'km',
	purchase_price: 1850000,
	residual_est: 400000,
	currency: 'EUR',
	jurisdiction: 'de',
	lifecycle: 'active',
	retention_months: 120,
	color: 'blue',
	notes: 'two rows of seats',
}

/**
 * The sheet, mounted and past whatever it reads on the way up.
 *
 * @param {object|null} [vehicle] - the vehicle to edit, or nothing to create one
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted sheet
 */
async function sheet(vehicle = null) {
	const wrapper = shallowMount(VehicleSheet, {
		props: vehicle === null ? {} : { vehicle },
		// shallowMount renders no stub's slots, and every field of this sheet sits inside the
		// dialog - so that one component is rendered and the rest stay stubs. The buttons say
		// what they do in their own slot, so the stubs render theirs.
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()

	return wrapper
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
 * @param {string} label - the label above the dropdown
 * @return {any} the dropdown, or undefined when the sheet does not show it
 */
function dropdown(wrapper, label) {
	return wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
}

/**
 * The sheet's own button, whatever it says at the moment: the last of the two the dialog's actions
 * hold, because a failed save renames it (docs/ui.md).
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @return {any} the button that writes
 */
function saveButton(wrapper) {
	return wrapper.findAllComponents(NcButton).at(-1)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when the sheet has none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} name - the event the sheet emits
 * @return {any} what its first emission carried, or undefined when it never came
 */
function emitted(wrapper, name) {
	return wrapper.emitted(name)?.[0][0]
}

/**
 * A day field. It speaks Date rather than the API's `YYYY-MM-DD`, which is why format.js has the
 * two helpers that convert without losing a day to a timezone.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted sheet
 * @param {string} label - the label beside the picker
 * @return {any} the picker, or undefined when the sheet does not show it
 */
function day(wrapper, label) {
	return wrapper.findAllComponents(NcDateTimePickerNative).find((one) => one.props('label') === label)
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(getPreferences).mockResolvedValue({
		preferences: { jurisdiction: 'de', dismissed_hints: [] },
		jurisdictions: [{ key: 'de', name: 'Germany' }, { key: 'generic', name: 'Generic' }],
	})
	vi.mocked(createVehicle).mockImplementation(async (fields) => ({ ...fields, uuid: 'v-new', updated_at: 1 }))
	vi.mocked(updateVehicle).mockImplementation(async (vehicle) => ({ ...vehicle, updated_at: 1700000900 }))
	// The delete advances the token, and the one it answers with is the only one the undo is
	// accepted with (docs/architecture.md#concurrency).
	vi.mocked(deleteVehicle).mockImplementation(async (vehicle) => ({ ...vehicle, updated_at: 1700000800 }))
	// The server's answer, whole: `origin` and `flagged` are its own and never a field the sheet
	// fills in (src/services/api.js).
	vi.mocked(recordReading).mockResolvedValue({
		uuid: 'r-1',
		read_at: 1700000000,
		read_at_off: 60,
		value: 148320,
		origin: 'observed',
		flagged: false,
	})
})

describe('the vehicle sheet, editing', () => {
	/**
	 * `Esc` closes the sheet and NcDialog already gives it (docs/ui.md), so nothing here listens
	 * for the key. What is pinned is the one wire it travels along: the dialog reports itself
	 * closed and the sheet leaves. A sheet that bound `:open` and no listener would swallow the
	 * key silently and never reopen.
	 */
	it('closes when the dialog reports itself closed', async () => {
		const wrapper = await sheet(VEHICLE)

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * The other half of the key, and the half NcDialog does not give: its own Escape handler is a
	 * useHotKey, and useHotKey passes over every keystroke aimed at a text field. The sheet opens
	 * with the caret in one, so Escape at the moment somebody would press it reaches nobody.
	 */
	it('closes when Esc is pressed in a field', async () => {
		const wrapper = await sheet(VEHICLE)

		await wrapper.find('.sheet').trigger('keydown.esc')

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * Escape in a date field belongs to the picker the browser opened over it, not to the sheet.
	 * Chromium dismisses that picker on the key and delivers the keydown to the input all the same,
	 * so a sheet that took it would close over nineteen filled-in fields the moment somebody backed
	 * out of a calendar. The picker stops it where an open NcSelect stops it.
	 */
	it('stays open when Esc is pressed in a date field', async () => {
		const wrapper = await sheet({ ...VEHICLE, lifecycle: 'disposed', disposed_at: '2025-06-30' })

		await day(wrapper, 'First registration').trigger('keydown.esc')
		await day(wrapper, 'Disposed on').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/** Everything prefilled and visibly editable (docs/ui.md) - including what the create sheet never asked. */
	it('shows the vehicle it was given', async () => {
		const wrapper = await sheet(VEHICLE)

		expect(field(wrapper, 'Registration plate').props('modelValue')).toBe('B-XY 123')
		expect(field(wrapper, 'VIN').props('modelValue')).toBe('WVWZZZ1KZAW000001')
		expect(formatDay(day(wrapper, 'First registration').props('modelValue'))).toBe('2019-03-07')
		expect(field(wrapper, 'Purchase price (cents)').props('modelValue')).toBe('1850000')
		expect(dropdown(wrapper, 'Vehicle type').props('modelValue').id).toBe('van')
		expect(dropdown(wrapper, 'Energy types').props('modelValue').map((/** @type {{id: string}} */ o) => o.id))
			.toEqual(['petrol', 'electric'])
		expect(dropdown(wrapper, 'Lifecycle').props('modelValue').id).toBe('active')
	})

	/**
	 * Sent whole rather than as a diff, because `apply()` writes what the payload names: a field
	 * the user emptied has to travel as an empty one to be cleared. The token travels with it -
	 * this is the write docs/architecture.md#concurrency checks.
	 */
	it('writes every writable column back under the token it read', async () => {
		const wrapper = await sheet(VEHICLE)

		await field(wrapper, 'Registration plate').vm.$emit('update:modelValue', 'B-XY 999')
		await field(wrapper, 'Colour').vm.$emit('update:modelValue', '')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(updateVehicle).toHaveBeenCalledWith(expect.objectContaining({
			uuid: 'v-1',
			updated_at: 1700000000,
			plate: 'B-XY 999',
			manufacturer: 'Volkswagen',
			model: 'Caddy',
			vehicle_type: 'van',
			engine: 'hybrid',
			energy_types: ['petrol', 'electric'],
			tank_ml: '55000',
			battery_wh: '13600',
			first_reg: '2019-03-07',
			disposed_at: '',
			vin: 'WVWZZZ1KZAW000001',
			odo_unit: 'km',
			purchase_price: '1850000',
			residual_est: '400000',
			currency: 'EUR',
			jurisdiction: 'de',
			lifecycle: 'active',
			retention_months: '120',
			color: '',
			notes: 'two rows of seats',
		}))
		expect(emitted(wrapper, 'saved').updated_at).toBe(1700000900)
	})

	/** A disposal day is a fact about a disposed vehicle and about no other one. */
	it('asks for the disposal day only once the vehicle is disposed of', async () => {
		const wrapper = await sheet(VEHICLE)
		expect(day(wrapper, 'Disposed on')).toBeUndefined()

		await dropdown(wrapper, 'Lifecycle').vm.$emit('update:modelValue', { id: 'disposed', label: 'Disposed of' })

		expect(day(wrapper, 'Disposed on')).toBeDefined()
	})

	/**
	 * A vehicle that came back into service still carrying the day it was sold on would be a
	 * contradiction the reports would have to guess at.
	 */
	it('clears the disposal day when the vehicle is not disposed of', async () => {
		const wrapper = await sheet({ ...VEHICLE, lifecycle: 'disposed', disposed_at: '2025-06-30' })
		expect(formatDay(day(wrapper, 'Disposed on').props('modelValue'))).toBe('2025-06-30')

		await dropdown(wrapper, 'Lifecycle').vm.$emit('update:modelValue', { id: 'active', label: 'Active' })
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(updateVehicle).toHaveBeenCalledWith(expect.objectContaining({ lifecycle: 'active', disposed_at: '' }))
	})

	/**
	 * The country is changed here until the sidebar exists (docs/ui.md), and the list is the one
	 * lib/Jurisdiction/ registers - so a country added there reaches this dropdown without the
	 * frontend being touched.
	 */
	it('offers the countries the server registered, in a word the bundle translates', async () => {
		const wrapper = await sheet(VEHICLE)

		expect(dropdown(wrapper, 'Jurisdiction').props('options'))
			.toEqual([{ id: 'de', label: 'Germany' }, { id: 'generic', label: 'Generic' }])
		expect(dropdown(wrapper, 'Jurisdiction').props('modelValue').id).toBe('de')
	})

	/**
	 * A vehicle whose country left a later release must still open, and the dropdown may not
	 * quietly show another one: what it says is what the next save writes.
	 */
	it('keeps offering the country the vehicle is already kept under', async () => {
		const wrapper = await sheet({ ...VEHICLE, jurisdiction: 'zz' })

		expect(dropdown(wrapper, 'Jurisdiction').props('options').map((/** @type {{id: string}} */ o) => o.id))
			.toEqual(['de', 'generic', 'zz'])
		expect(dropdown(wrapper, 'Jurisdiction').props('modelValue').id).toBe('zz')
	})

	/** The list is a convenience; a sheet that cannot read it still edits the vehicle in hand. */
	it('edits a vehicle even when the registration list cannot be read', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await sheet(VEHICLE)

		expect(dropdown(wrapper, 'Jurisdiction').props('modelValue').id).toBe('de')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(updateVehicle).toHaveBeenCalledWith(expect.objectContaining({ jurisdiction: 'de' }))
	})

	/**
	 * The sheet never blocks on validation and a failed save is never lost (docs/ui.md): the
	 * server judges the field, the sheet stays open saying so, and nothing typed is discarded.
	 */
	it('stays open with every value intact when the write is refused', async () => {
		vi.mocked(updateVehicle).mockRejectedValue(new Error('tank_ml is a whole number'))
		const wrapper = await sheet(VEHICLE)

		await field(wrapper, 'Tank size (ml)').vm.$emit('update:modelValue', '55 litres')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('tank_ml is a whole number')
		expect(field(wrapper, 'Tank size (ml)').props('modelValue')).toBe('55 litres')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		// A field the server judged is not a token that moved: reading the vehicle back would
		// answer the same values and the retry would be refused for the same reason.
		expect(getVehicle).not.toHaveBeenCalled()
	})

	/**
	 * A refused write is the one failure that is not about what was typed: the values are fine
	 * and the token is not (docs/architecture.md#concurrency). So the sheet says which of the two
	 * happened, and the button says what saving again would do.
	 */
	it('names the conflict when the vehicle moved on since it was read', async () => {
		vi.mocked(updateVehicle).mockRejectedValue(new ConflictError('Changed since you read it'))
		const wrapper = await sheet(VEHICLE)

		await field(wrapper, 'Colour').vm.$emit('update:modelValue', 'green')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe(
			'This vehicle was changed somewhere else while you had it open. Saving again writes your values over that change.',
		)
		expect(wrapper.findComponent(NcNoteCard).props('type')).toBe('warning')
		expect(saveButton(wrapper).text()).toBe('Save anyway')
		expect(field(wrapper, 'Colour').props('modelValue')).toBe('green')
		expect(wrapper.emitted('saved')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	/**
	 * The one button the conflict offers: it reads the vehicle back and writes what is on screen
	 * onto the token that came with it. Nothing is retyped, and the values the other writer left
	 * behind do not creep into the fields - `color` is `red` in that answer and stays `green`.
	 */
	it('saves through the conflict under the token it re-read', async () => {
		vi.mocked(updateVehicle).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		// The typedef declares the columns a client reads by name, not all twenty (src/services/api.js).
		vi.mocked(getVehicle).mockResolvedValue(/** @type {any} */ ({ ...VEHICLE, updated_at: 1700009999, color: 'red' }))
		const wrapper = await sheet(VEHICLE)

		await field(wrapper, 'Colour').vm.$emit('update:modelValue', 'green')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(getVehicle).toHaveBeenCalledWith('v-1')
		expect(updateVehicle).toHaveBeenLastCalledWith(expect.objectContaining({
			uuid: 'v-1',
			updated_at: 1700009999,
			color: 'green',
		}))
		expect(emitted(wrapper, 'saved').updated_at).toBe(1700000900)
	})

	/**
	 * The re-read can fail on its own, and the token is stale either way when it does. So the
	 * message is the new failure, in the colour a failure gets, and the button still says what
	 * the next click would do rather than dropping back to a plain retry.
	 */
	it('stays on the conflict when the vehicle cannot be read back', async () => {
		vi.mocked(updateVehicle).mockRejectedValue(new ConflictError('Changed since you read it'))
		vi.mocked(getVehicle).mockRejectedValue(new Error('The server answered 500'))
		const wrapper = await sheet(VEHICLE)

		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(wrapper.findComponent(NcNoteCard).props('type')).toBe('error')
		expect(saveButton(wrapper).text()).toBe('Save anyway')
		expect(updateVehicle).toHaveBeenCalledTimes(1)
	})

	/**
	 * Nothing asks "are you sure?" (docs/ui.md): the vehicle is deleted under the token on screen,
	 * and the way back is what the store keeps of it - this sheet emits nothing, because the
	 * vehicle leaving the fleet unmounts it (src/components/UndoToast.vue).
	 */
	it('deletes the vehicle and leaves the way back with the store', async () => {
		const store = useVehiclesStore()
		store.upsert(/** @type {any} */ (VEHICLE))
		const wrapper = await sheet(VEHICLE)

		await button(wrapper, 'Delete vehicle').vm.$emit('click')
		await flushPromises()

		expect(deleteVehicle).toHaveBeenCalledWith(expect.objectContaining({ uuid: 'v-1', updated_at: 1700000000 }))
		expect(store.list).toEqual([])
		expect(store.deleted?.updated_at).toBe(1700000800)
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	/**
	 * The delete is checked against the same token a save is, so it loses the same race - and it
	 * takes the same way out: read the vehicle back, then delete the one that is actually there.
	 */
	it('deletes through a conflict under the token it re-read', async () => {
		vi.mocked(deleteVehicle).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		vi.mocked(getVehicle).mockResolvedValue(/** @type {any} */ ({ ...VEHICLE, updated_at: 1700009999 }))
		const wrapper = await sheet(VEHICLE)

		await button(wrapper, 'Delete vehicle').vm.$emit('click')
		await flushPromises()
		// The message names what the user asked for. Saying "saving again" here would point at
		// the one button that does something else with the click it describes.
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe(
			'This vehicle was changed somewhere else while you had it open. Deleting again removes it as it now stands.',
		)
		expect(wrapper.findComponent(NcNoteCard).props('type')).toBe('warning')
		expect(useVehiclesStore().deleted).toBeNull()

		await button(wrapper, 'Delete vehicle').vm.$emit('click')
		await flushPromises()

		expect(deleteVehicle).toHaveBeenLastCalledWith(expect.objectContaining({ updated_at: 1700009999 }))
		expect(useVehiclesStore().deleted?.uuid).toBe('v-1')
	})
})

describe('the vehicle sheet, creating', () => {
	/** Four fields, not twelve: the rest arrives through the edit sheet (docs/ui.md). */
	it('asks for four fields and the counter, and nothing else', async () => {
		const wrapper = await sheet()

		expect(wrapper.findAllComponents(NcTextField).map((one) => one.props('label')))
			.toEqual(['Registration plate', 'Manufacturer', 'Model', 'Counter reading'])
		expect(wrapper.findAllComponents(NcSelect).map((/** @type {any} */ one) => one.props('inputLabel')))
			.toEqual(['Engine'])
		expect(getPreferences).not.toHaveBeenCalled()
	})

	/** There is nothing to delete yet, and Cancel is what leaves a vehicle uncreated. */
	it('offers no delete', async () => {
		const wrapper = await sheet()

		expect(button(wrapper, 'Delete vehicle')).toBeUndefined()
	})

	it('adds the vehicle and its first reading', async () => {
		const wrapper = await sheet()

		await field(wrapper, 'Registration plate').vm.$emit('update:modelValue', 'M-EV 7')
		await dropdown(wrapper, 'Engine').vm.$emit('update:modelValue', { id: 'electric', label: 'Electric' })
		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', '148.320')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(createVehicle).toHaveBeenCalledWith({
			plate: 'M-EV 7',
			manufacturer: '',
			model: '',
			engine: 'electric',
		})
		expect(recordReading).toHaveBeenCalledWith('v-new', expect.objectContaining({ value: 148320 }))
		expect(emitted(wrapper, 'created').uuid).toBe('v-new')
	})

	/** A field nobody can read is a question for the driver, not a vehicle created without it. */
	it('writes nothing when the counter is not a counter', async () => {
		const wrapper = await sheet()

		await field(wrapper, 'Counter reading').vm.$emit('update:modelValue', 'full')
		await saveButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(createVehicle).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('That is not a counter reading.')
	})
})
