/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, savePreferences, updateVehicle } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { usePreferencesStore } from '../store/preferences.js'
import CompleteHint from './CompleteHint.vue'

// The store stays real: a dismissal is worth something only if it reaches the preferences.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	getPreferences: vi.fn(),
	savePreferences: vi.fn(),
	updateVehicle: vi.fn(),
}))

/** Created in the four fields the sheet asks for (docs/ui.md) and never finished. */
const NEW = {
	uuid: 'v-new',
	updated_at: 1700000000,
	plate: 'NF-NE 600',
	lifecycle: 'active',
	engine: 'petrol',
	energy_types: ['petrol'],
	may: ['view', 'log', 'edit', 'delete', 'own'],
}

/** The same vehicle with every question answered. */
const DONE = {
	...NEW,
	uuid: 'v-done',
	plate: 'NF-DE 100',
	vin: 'X',
	first_reg: '2019-03-14',
	tank_ml: 52000,
	currency: 'EUR',
}

/** A German vehicle with every detail in and the logbook question still open. */
const GERMAN = { ...DONE, uuid: 'v-de', plate: 'NF-DE 200', jurisdiction: 'de', logbook_mode: false }

/**
 * @param {string[]} dismissed - the hints this user has already answered
 * @param {string[]} [logbook] - the vehicles whose logbook question this user has answered
 * @return {any} the settings envelope the server answers with
 */
function settings(dismissed, logbook = []) {
	return {
		preferences: { jurisdiction: 'de', dismissed_hints: dismissed, dismissed_logbook_hints: logbook },
		// Only the ruled one has a tax office to keep a logbook for.
		jurisdictions: [{ key: 'de', logbook_rules: true }, { key: 'xx', logbook_rules: true }, { key: 'generic', logbook_rules: false }],
	}
}

/**
 * The hint over a fleet, with the preferences read as the app reads them on load (src/App.vue).
 *
 * @param {any[]} vehicles - the fleet the overview lists
 * @param {boolean} [read] - whether the preferences arrived at all
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted hint
 */
async function hint(vehicles, read = true) {
	if (read) {
		await usePreferencesStore().load()
	}

	// A button says what it does in its own slot, and that is how the two are told apart.
	return shallowMount(CompleteHint, {
		props: { vehicles },
		global: { renderStubDefaultSlot: true },
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted hint
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when nothing says that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted hint
 * @return {any} the card carrying a failure, or undefined when nothing failed
 */
function note(wrapper) {
	return wrapper.findAllComponents(NcNoteCard).find((one) => one.props('type') === 'error')
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(getPreferences).mockResolvedValue(settings([]))
})

describe('the complete-this-vehicle hint', () => {
	it('says nothing about a vehicle that has it all', async () => {
		const wrapper = await hint([DONE])

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	it('says nothing about a vehicle the reader may not edit', async () => {
		const wrapper = await hint([{ ...NEW, may: ['view', 'log'] }])

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	/** In the edit sheet's own words, so following the hint reads as one instruction. */
	it('names the vehicle and the fields nobody has filled in', async () => {
		const wrapper = await hint([NEW, DONE])

		expect(wrapper.text()).toContain('NF-NE 600')
		expect(wrapper.text()).toContain('VIN')
		expect(wrapper.text()).toContain('First registration')
		expect(wrapper.text()).toContain('Tank size (l)')
		expect(wrapper.text()).toContain('Currency')
		expect(wrapper.text()).not.toContain('NF-DE 100')
	})

	it('waits for the preferences before asking anything', async () => {
		const wrapper = await hint([NEW], false)

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	/** A hint answered on another machine is answered on this one (docs/ui.md). */
	it('leaves out a vehicle whose hint was dismissed elsewhere', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings([NEW.uuid]))

		const wrapper = await hint([NEW])

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	it('opens the vehicle it is asking about', async () => {
		const wrapper = await hint([NEW])

		await button(wrapper, 'NF-NE 600').trigger('click')

		expect(wrapper.emitted('select')?.[0]).toEqual([NEW.uuid])
	})

	it('says which vehicle each dismissal is for', async () => {
		const wrapper = await hint([NEW])

		// A stub takes it as the prop NcButton declares it as, not as an attribute on the element.
		expect(button(wrapper, 'Dismiss').props('ariaLabel')).toBe('Dismiss the hint for NF-NE 600')
	})

	it('stores a dismissal and stops asking', async () => {
		vi.mocked(savePreferences).mockResolvedValue(settings([NEW.uuid]))
		const wrapper = await hint([NEW])

		await button(wrapper, 'Dismiss').trigger('click')
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ dismissed_hints: [NEW.uuid] })
		expect(wrapper.text()).not.toContain('NF-NE 600')
	})

	it('keeps asking when the dismissal was refused, and says why', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('The server answered 503'))
		const wrapper = await hint([NEW])

		await button(wrapper, 'Dismiss').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('NF-NE 600')
		// A stub renders no props, so the card is read by what it was handed rather than by text.
		expect(note(wrapper)?.props('type')).toBe('error')
		expect(note(wrapper)?.props('text')).toBe('The server answered 503')
	})
})

describe('the logbook question', () => {
	const QUESTION = 'Keep a logbook for the tax office with this vehicle?'

	/** @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted hint @return {any} the card asking it */
	function asking(wrapper) {
		return wrapper.findAllComponents(NcNoteCard).find((one) => one.props('heading') === QUESTION)
	}

	it('asks about a German vehicle whose mode is off', async () => {
		const wrapper = await hint([GERMAN])

		expect(asking(wrapper)?.text()).toContain('NF-DE 200')
		expect(button(wrapper, 'Switch Logbook mode on')).toBeDefined()
	})

	/** The profile decides, not the key: any country with a logbook ruleset is asked about. */
	it('asks about a vehicle of any country with logbook rules', async () => {
		expect(asking(await hint([{ ...GERMAN, jurisdiction: 'xx' }]))).toBeDefined()
	})

	it.each([
		['another country', { ...GERMAN, jurisdiction: 'generic' }],
		['the mode on', { ...GERMAN, logbook_mode: true }],
		['no right to edit', { ...GERMAN, may: ['view', 'log'] }],
	])('does not ask about a vehicle with %s', async (_, vehicle) => {
		expect(asking(await hint([vehicle]))).toBeUndefined()
	})

	it('does not ask again once dismissed, and that leaves the missing details alone', async () => {
		vi.mocked(savePreferences).mockResolvedValue(settings([], [GERMAN.uuid]))
		const wrapper = await hint([GERMAN])

		await button(wrapper, 'Dismiss').trigger('click')
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ dismissed_logbook_hints: [GERMAN.uuid] })
		expect(asking(wrapper)).toBeUndefined()

		vi.mocked(getPreferences).mockResolvedValue(settings([], [GERMAN.uuid]))
		setActivePinia(createPinia())
		expect(asking(await hint([GERMAN]))).toBeUndefined()
	})

	it('switches the mode on and counts that as the answer', async () => {
		const switched = { ...GERMAN, logbook_mode: true, updated_at: 1700000001 }
		vi.mocked(updateVehicle).mockResolvedValue(switched)
		vi.mocked(savePreferences).mockResolvedValue(settings([], [GERMAN.uuid]))
		const wrapper = await hint([GERMAN])

		await button(wrapper, 'Switch Logbook mode on').trigger('click')
		await flushPromises()

		expect(updateVehicle).toHaveBeenCalledWith({ uuid: GERMAN.uuid, updated_at: GERMAN.updated_at, logbook_mode: true })
		expect(savePreferences).toHaveBeenCalledWith({ dismissed_logbook_hints: [GERMAN.uuid] })
		expect(useVehiclesStore().list).toEqual([switched])
		expect(asking(wrapper)).toBeUndefined()
	})

	it('takes one answer at a time', async () => {
		vi.mocked(updateVehicle).mockReturnValue(new Promise(() => {}))
		const wrapper = await hint([GERMAN])

		await button(wrapper, 'Switch Logbook mode on').trigger('click')

		expect(button(wrapper, 'Switch Logbook mode on').props('disabled')).toBe(true)
		expect(button(wrapper, 'Dismiss').props('disabled')).toBe(true)
	})

	it('keeps asking when the switch was refused, and says why', async () => {
		vi.mocked(updateVehicle).mockRejectedValue(new Error('Changed since you read it'))
		const wrapper = await hint([GERMAN])

		await button(wrapper, 'Switch Logbook mode on').trigger('click')
		await flushPromises()

		expect(asking(wrapper)).toBeDefined()
		expect(note(wrapper)?.props('text')).toBe('Changed since you read it')
		expect(savePreferences).not.toHaveBeenCalled()
	})
})
