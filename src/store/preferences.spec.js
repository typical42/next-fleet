/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, savePreferences } from '../services/api.js'
import { usePreferencesStore } from './preferences.js'

vi.mock('../services/api.js', () => ({
	getPreferences: vi.fn(),
	savePreferences: vi.fn(),
}))

/**
 * The settings envelope as the server hands it over.
 *
 * @param {string[]} dismissed - the hints this user has answered
 * @param {Partial<import('../services/api.js').Settings['preferences']>} [more] - the other choices
 * @return {import('../services/api.js').Settings} the whole state of the screen
 */
function settings(dismissed, more = {}) {
	return { preferences: { jurisdiction: 'de', dismissed_hints: dismissed, dismissed_logbook_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null, inbox_folder: null, ...more }, jurisdictions: [] }
}

describe('preferences store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('knows nothing until the preferences have been read', () => {
		expect(usePreferencesStore().loaded).toBe(false)
	})

	it('holds what a user has dismissed', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		const store = usePreferencesStore()

		await store.load()

		expect(store.loaded).toBe(true)
		expect(store.isDismissed('a')).toBe(true)
		expect(store.isDismissed('b')).toBe(false)
	})

	it('adds a dismissal to the ones already stored', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		vi.mocked(savePreferences).mockResolvedValue(settings(['a', 'b']))
		const store = usePreferencesStore()
		await store.load()

		await store.dismiss('b')

		expect(savePreferences).toHaveBeenCalledWith({ dismissed_hints: ['a', 'b'] })
		expect(store.isDismissed('b')).toBe(true)
	})

	it('reads the stored list before writing to it', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		vi.mocked(savePreferences).mockResolvedValue(settings(['a', 'b']))

		await usePreferencesStore().dismiss('b')

		expect(getPreferences).toHaveBeenCalled()
		expect(savePreferences).toHaveBeenCalledWith({ dismissed_hints: ['a', 'b'] })
	})

	it('does not lose a dismissal to the one clicked right after it', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings([]))
		vi.mocked(savePreferences).mockImplementation(async (fields) => settings(fields.dismissed_hints ?? []))
		const store = usePreferencesStore()
		await store.load()

		await Promise.all([store.dismiss('a'), store.dismiss('b')])

		expect(savePreferences).toHaveBeenLastCalledWith({ dismissed_hints: ['a', 'b'] })
		expect(store.isDismissed('a')).toBe(true)
		expect(store.isDismissed('b')).toBe(true)
	})

	it('does not write a dismissal it already holds', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		const store = usePreferencesStore()
		await store.load()

		await store.dismiss('a')

		expect(savePreferences).not.toHaveBeenCalled()
	})

	it('keeps the hint when the write is refused', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings([]))
		vi.mocked(savePreferences).mockRejectedValue(new Error('nope'))
		const store = usePreferencesStore()
		await store.load()

		await expect(store.dismiss('a')).rejects.toThrow('nope')
		expect(store.isDismissed('a')).toBe(false)
	})

	it('reads gross over the last twelve months until told otherwise', async () => {
		const store = usePreferencesStore()
		expect(store.reclaimVat).toBe(false)
		expect(store.period).toBe('last-12')

		vi.mocked(getPreferences).mockResolvedValue(settings([], { reclaim_vat: true, kpi_period: 'this-year' }))
		await store.load()

		expect(store.reclaimVat).toBe(true)
		expect(store.period).toBe('this-year')
	})

	it('holds a chosen period before it is stored, and after a refusal', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('nope'))
		const store = usePreferencesStore()

		const writing = store.choosePeriod('last-year')
		expect(store.period).toBe('last-year')

		await expect(writing).rejects.toThrow('nope')
		expect(savePreferences).toHaveBeenCalledWith({ kpi_period: 'last-year' })
		expect(store.period).toBe('last-year')
	})

	it('keeps the latest pick while an earlier one is answered', async () => {
		/** @type {(() => void)[]} */
		const pending = []
		vi.mocked(savePreferences).mockImplementation((fields) => new Promise((resolve) => {
			pending.push(() => resolve(settings([], fields)))
		}))
		const store = usePreferencesStore()

		store.choosePeriod('last-year')
		const latest = store.choosePeriod('this-year')
		await vi.waitFor(() => expect(pending).toHaveLength(1))
		pending[0]()
		await vi.waitFor(() => expect(pending).toHaveLength(2))

		expect(store.period).toBe('this-year')
		pending[1]()
		await latest
		expect(store.period).toBe('this-year')
	})
})
