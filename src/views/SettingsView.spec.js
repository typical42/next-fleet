/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
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
	preferences: { jurisdiction: 'de' },
	jurisdictions: [{ key: 'de', name: 'Germany' }, { key: 'generic', name: 'Generic' }],
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
			({ ...settings, preferences: { jurisdiction: fields.jurisdiction ?? 'de' } }),
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
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { jurisdiction: 'zz' } })

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

	/** A settings page that cannot be read says why, rather than showing an empty dropdown. */
	it('says so when the settings cannot be read', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await screen()

		expect(note(wrapper)).toBe('The server answered 500')
	})
})
