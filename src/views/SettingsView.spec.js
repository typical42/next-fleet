/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, readInbox, savePreferences } from '../services/api.js'
import SettingsView from './SettingsView.vue'

vi.mock('../services/api.js', () => ({
	getPreferences: vi.fn(),
	readInbox: vi.fn(),
	savePreferences: vi.fn(),
}))

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

/** What the picker hands back next, or the close it rejects with. */
let picked = /** @type {Promise<any>} */ (Promise.resolve([]))
/** Whether the picker was asked for folders. */
let folders = false

const INBOX = { folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 }
const NO_INBOX = { folder: null, files: [], count: 0 }

// @nextcloud/l10n stays real: with no catalogue registered, `t()` answers with the source string,
// and @nextcloud/vue loads it too.

const settings = {
	// The route's whole shape (lib/Service/PreferencesService.php), though the screen reads less.
	preferences: { jurisdiction: 'de', dismissed_hints: [], dismissed_logbook_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null, inbox_folder: null },
	jurisdictions: [
		{ key: 'de', name: 'Germany', logbook_export: true, mileage_claim: true, logbook_rules: true, grid_factor: { grams: 363, year: 2024, source: 'https://example.org/grid' } },
		{ key: 'generic', name: 'Generic', logbook_export: false, mileage_claim: false, logbook_rules: false, grid_factor: null },
	],
}

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper, past its first read
 */
async function screen() {
	const wrapper = shallowMount(SettingsView, {
		// Everything sits inside the section, so that one renders; the rest stay stubs.
		global: { renderStubDefaultSlot: true, stubs: { NcSettingsSection: { template: '<div><slot /></div>' } } },
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
 * Read off the note's prop: a stub renders its props, not its own markup.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {string} the message, or the empty string when there is no note
 */
function note(wrapper) {
	const card = wrapper.findComponent(NcNoteCard)

	return card.exists() ? card.props('text') ?? '' : ''
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(getPreferences).mockResolvedValue(settings)
	vi.mocked(savePreferences).mockImplementation(
		async (/** @type {Partial<import('../services/api.js').Settings['preferences']>} */ fields) =>
			({ ...settings, preferences: { ...settings.preferences, ...fields } }),
	)
	vi.mocked(readInbox).mockResolvedValue(NO_INBOX)
	folders = false
	const builder = {
		setMultiSelect: () => builder,
		allowDirectories: (/** @type {boolean} */ allowed) => {
			folders = allowed
			return builder
		},
		setMimeTypeFilter: () => builder,
		setButtonFactory: () => builder,
		build: () => ({ pickNodes: () => picked }),
	}
	vi.mocked(getFilePickerBuilder).mockReturnValue(/** @type {any} */ (builder))
})

describe('settings screen', () => {
	/** A country added in lib/Jurisdiction/ needs no frontend change (docs/contributing.md). */
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

	it('selects nothing when the stored jurisdiction is not on offer', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, jurisdiction: 'zz' } })

		const wrapper = await screen()

		expect(dropdown(wrapper).props('modelValue')).toBe(null)
	})

	it('saves the choice as it is made', async () => {
		const wrapper = await screen()

		await dropdown(wrapper).vm.$emit('update:modelValue', { id: 'generic', label: 'Generic' })
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ jurisdiction: 'generic' })
		expect(dropdown(wrapper).props('modelValue').id).toBe('generic')
	})

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

	it('falls back to the stored VAT answer when the write is refused', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('reclaim_vat is true or false'))
		const wrapper = await screen()

		await vatSwitch(wrapper).vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(vatSwitch(wrapper).props('modelValue')).toBe(false)
		expect(note(wrapper)).toBe('reclaim_vat is true or false')
	})

	it('shows this user\'s grid factor, and the country average an empty field stands for', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, grid_factor: 120 } })
		const wrapper = await screen()

		expect(gridField(wrapper).props('modelValue')).toBe('120')
		expect(gridField(wrapper).props('helperText')).toBe('Empty for the average of the country each vehicle is kept under: Germany 363 g/kWh in 2024')
	})

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

	it('saves nothing for a grid factor it cannot read, and says so', async () => {
		const wrapper = await screen()

		await gridField(wrapper).vm.$emit('update:modelValue', '12,5')
		await gridField(wrapper).vm.$emit('change')
		await flushPromises()

		expect(savePreferences).not.toHaveBeenCalled()
		expect(gridField(wrapper).props('error')).toBe(true)
	})

	/** The preference holds an id; the inbox reads its path (docs/architecture.md#the-inbox). */
	it('names the inbox folder by its path in Files', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, inbox_folder: 7 } })
		vi.mocked(readInbox).mockResolvedValue(INBOX)

		const wrapper = await screen()

		expect(wrapper.find('.inbox__folder').text()).toBe('/Belege')
		expect(button(wrapper, 'Stop using it')).toBeDefined()
	})

	it('says how to use an inbox while none is chosen', async () => {
		const wrapper = await screen()

		expect(wrapper.find('.inbox__folder').text()).toBe('None chosen')
		expect(wrapper.text()).toContain('auto-upload')
		expect(button(wrapper, 'Stop using it')).toBeUndefined()
	})

	/** A folder deleted in Files is still the preference. */
	it('says the chosen folder is gone', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, inbox_folder: 7 } })

		const wrapper = await screen()

		expect(wrapper.find('.inbox__folder').text()).toBe('The folder is gone, or no longer yours alone')
	})

	it('saves the folder picked, and names it', async () => {
		picked = Promise.resolve([{ fileid: 7, basename: 'Belege' }])
		const wrapper = await screen()
		vi.mocked(readInbox).mockResolvedValue(INBOX)

		await button(wrapper, 'Choose folder').vm.$emit('click')
		await flushPromises()

		expect(folders).toBe(true)
		expect(savePreferences).toHaveBeenCalledWith({ inbox_folder: 7 })
		expect(wrapper.find('.inbox__folder').text()).toBe('/Belege')
	})

	it('says the folder is saved but unnamed when the name cannot be read', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, inbox_folder: 7 } })
		vi.mocked(readInbox).mockResolvedValue(INBOX)
		picked = Promise.resolve([{ fileid: 8, basename: 'Quittungen' }])
		const wrapper = await screen()
		vi.mocked(readInbox).mockRejectedValue(new Error('The server answered 503'))

		await button(wrapper, 'Choose folder').vm.$emit('click')
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ inbox_folder: 8 })
		expect(wrapper.find('.inbox__folder').text()).toBe('Chosen, but its name could not be read: The server answered 503')
		expect(note(wrapper)).toBe('')
	})

	/** A shared folder is refused (docs/architecture.md#the-inbox); the server's words say why. */
	it('keeps the folder it had when the pick is refused', async () => {
		picked = Promise.resolve([{ fileid: 8, basename: 'Shared' }])
		vi.mocked(savePreferences).mockRejectedValue(new Error('inbox_folder is a folder of your own'))
		const wrapper = await screen()

		await button(wrapper, 'Choose folder').vm.$emit('click')
		await flushPromises()

		expect(note(wrapper)).toBe('inbox_folder is a folder of your own')
		expect(wrapper.find('.inbox__folder').text()).toBe('None chosen')
	})

	it('asks nothing when the picker is closed', async () => {
		const wrapper = await screen()
		// Made after the mount: a rejection nobody awaits yet is reported as unhandled.
		picked = Promise.reject(new FilePickerClosed())

		await button(wrapper, 'Choose folder').vm.$emit('click')
		await flushPromises()

		expect(savePreferences).not.toHaveBeenCalled()
		expect(note(wrapper)).toBe('')
	})

	it('stops using the folder; the files in it stay where they are', async () => {
		vi.mocked(getPreferences).mockResolvedValue({ ...settings, preferences: { ...settings.preferences, inbox_folder: 7 } })
		vi.mocked(readInbox).mockResolvedValue(INBOX)
		const wrapper = await screen()

		await button(wrapper, 'Stop using it').vm.$emit('click')
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ inbox_folder: null })
		expect(wrapper.find('.inbox__folder').text()).toBe('None chosen')
	})

	it('says so when the settings cannot be read', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await screen()

		expect(note(wrapper)).toBe('The server answered 500')
	})
})
