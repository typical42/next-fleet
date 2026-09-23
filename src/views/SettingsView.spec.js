/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, savePreferences } from '../services/api.js'
import SettingsView from './SettingsView.vue'

// The network is the api client's own seam (api.spec.js); what is under test here is what the
// screen does with the two answers it can get.
vi.mock('../services/api.js', () => ({
	getPreferences: vi.fn(),
	savePreferences: vi.fn(),
}))

// @nextcloud/l10n is left alone: no page registers a catalogue in a spec, so `t()` answers with
// the source string - and @nextcloud/vue's own components load the module too, so a mock of it
// would have to stand in for the whole library's use of it as well.

const settings = {
	// The whole envelope, dismissed hints included: this screen reads only the jurisdiction, but
	// what the route answers with is one shape (lib/Service/PreferencesService.php).
	preferences: { jurisdiction: 'de', dismissed_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null },
	jurisdictions: [
		{ key: 'de', name: 'Germany', logbook_export: true, mileage_claim: true, grid_factor: { grams: 363, year: 2024, source: 'https://example.org/grid' } },
		{ key: 'generic', name: 'Generic', logbook_export: false, mileage_claim: false, grid_factor: null },
	],
}

/**
 * The screen, mounted and loaded.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper, past its first read
 */
async function screen() {
	const wrapper = shallowMount(SettingsView, {
		// shallowMount renders no stub's slots, and everything this screen shows sits inside the
		// section - so that one component is rendered and the rest stay stubs.
		global: { stubs: { NcSettingsSection: { template: '<div><slot /></div>' } } },
	})
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the jurisdiction dropdown
 */
function dropdown(wrapper) {
	return wrapper.findComponent(NcSelect)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the "I reclaim VAT" switch
 */
function vatSwitch(wrapper) {
	return wrapper.findComponent(NcCheckboxRadioSwitch)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the grid factor field
 */
function gridField(wrapper) {
	return wrapper.findComponent(NcTextField)
}

/**
 * What the screen is telling the user went wrong. Read off the note rather than out of the
 * rendered text: a stubbed component renders its props, not its own markup.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {string} the message, or the empty string when there is no note
 */
function note(wrapper) {
	const card = wrapper.findComponent(NcNoteCard)

	return card.exists() ? card.props('text') ?? '' : ''
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(getPreferences).mockResolvedValue(settings)
	vi.mocked(savePreferences).mockImplementation(
		async (/** @type {Partial<import('../services/api.js').Settings['preferences']>} */ fields) =>
			({ ...settings, preferences: { ...settings.preferences, ...fields } }),
	)
})

describe('settings screen', () => {
	/**
	 * The list comes from lib/Jurisdiction/, so a country added there reaches this dropdown
	 * without the frontend being touched (docs/contributing.md).
	 */
	it('offers the jurisdictions the server registered', async () => {
		const wrapper = await screen()

		expect(dropdown(wrapper).props('options').map((/** @type {{ id: string }} */ option) => option.id))
			.toEqual(['de', 'generic'])
	})

	/**
	 * A country is a code in the config and a word on screen (docs/ui.md#languages). The server's
	 * name is English and untranslated, so the bundle carries the word and falls back to it.
	 */
	it('names a country in a word the bundle translates', async () => {
		const wrapper = await screen()

		expect(dropdown(wrapper).props('options')[0].label).toBe('Germany')
	})

	it('shows the jurisdiction the user already has', async () => {
		const wrapper = await screen()

		expect(dropdown(wrapper).props('modelValue').id).toBe('de')
	})

	/**
	 * A country this release no longer offers is still what the next vehicle would be written
	 * under. Selecting nothing says so; quietly showing the default would not.
	 */
	it('selects nothing when the stored jurisdiction is not on offer', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, jurisdiction: 'zz' } })

		const wrapper = await screen()

		expect(dropdown(wrapper).props('modelValue')).toBe(null)
	})

	/** A personal setting saves when it is changed - a settings page has no Save button. */
	it('saves the choice as it is made', async () => {
		const wrapper = await screen()

		await dropdown(wrapper).vm.$emit('update:modelValue', { id: 'generic', label: 'Generic' })
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ jurisdiction: 'generic' })
		expect(dropdown(wrapper).props('modelValue').id).toBe('generic')
	})

	/**
	 * A refused write leaves the dropdown showing what is really stored: a screen that keeps the
	 * new value would tell the user their vehicles are written under a country the server never
	 * accepted.
	 */
	it('falls back to the stored jurisdiction when the write is refused', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('jurisdiction is one of de, generic'))
		const wrapper = await screen()

		await dropdown(wrapper).vm.$emit('update:modelValue', { id: 'generic', label: 'Generic' })
		await flushPromises()

		expect(dropdown(wrapper).props('modelValue').id).toBe('de')
		expect(note(wrapper)).toBe('jurisdiction is one of de, generic')
	})

	it('shows that this user reclaims VAT, and saves a change as it is made', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, reclaim_vat: true } })
		const wrapper = await screen()
		expect(vatSwitch(wrapper).props('modelValue')).toBe(true)

		await vatSwitch(wrapper).vm.$emit('update:modelValue', false)
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ reclaim_vat: false })
		expect(vatSwitch(wrapper).props('modelValue')).toBe(false)
	})

	/** Same as the country: a refused write shows what is really stored. */
	it('falls back to the stored VAT answer when the write is refused', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('reclaim_vat is true or false'))
		const wrapper = await screen()

		await vatSwitch(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(vatSwitch(wrapper).props('modelValue')).toBe(false)
		expect(note(wrapper)).toBe('reclaim_vat is true or false')
	})

	/** Empty means the country's average, and the screen says what that is. */
	it('shows this user\'s grid factor, and the country average an empty field stands for', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, grid_factor: 120 } })
		const wrapper = await screen()

		expect(gridField(wrapper).props('modelValue')).toBe('120')
		expect(gridField(wrapper).props('helperText')).toBe('Empty for the average of the country each vehicle is kept under: Germany 363 g/kWh in 2024')
	})

	/**
	 * An empty field is read per vehicle, at the vehicle's country and not at this user's default:
	 * a generic default says nothing about the German car they share.
	 */
	it('names every country\'s average, whichever this user defaults to', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, jurisdiction: 'generic' } })
		const wrapper = await screen()

		expect(gridField(wrapper).props('helperText')).toBe('Empty for the average of the country each vehicle is kept under: Germany 363 g/kWh in 2024')
	})

	it('saves the grid factor when the field is left, and clears it when emptied', async () => {
		const wrapper = await screen()

		await gridField(wrapper).vm.$emit('update:modelValue', '95')
		await gridField(wrapper).vm.$emit('change')
		await flushPromises()
		expect(savePreferences).toHaveBeenLastCalledWith({ grid_factor: 95 })

		await gridField(wrapper).vm.$emit('update:modelValue', ' ')
		await gridField(wrapper).vm.$emit('change')
		await flushPromises()
		expect(savePreferences).toHaveBeenLastCalledWith({ grid_factor: null })
	})

	/** A figure the field cannot read is a question, not a cleared setting. */
	it('saves nothing for a grid factor it cannot read, and says so', async () => {
		const wrapper = await screen()

		await gridField(wrapper).vm.$emit('update:modelValue', '12,5')
		await gridField(wrapper).vm.$emit('change')
		await flushPromises()

		expect(savePreferences).not.toHaveBeenCalled()
		expect(gridField(wrapper).props('error')).toBe(true)
	})

	/** A settings page that cannot be read says why, rather than showing an empty dropdown. */
	it('says so when the settings cannot be read', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await screen()

		expect(note(wrapper)).toBe('The server answered 500')
	})
})
