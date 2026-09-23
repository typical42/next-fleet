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
	return { preferences: { jurisdiction: 'de', dismissed_hints: dismissed, reclaim_vat: false, kpi_period: 'last-12', grid_factor: null, ...more }, jurisdictions: [] }
}

describe('preferences store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	/**
	 * Until the preferences have arrived, nothing is known to be dismissed *or* undismissed - and
	 * showing a hint somebody has already answered is the one thing dismissing it has to prevent.
	 */
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

	/**
	 * The route replaces the whole list (lib/Service/PreferencesService.php), so a dismissal has
	 * to send what is already there with it or it would undo every earlier one.
	 */
	it('adds a dismissal to the ones already stored', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		vi.mocked(savePreferences).mockResolvedValue(settings(['a', 'b']))
		const store = usePreferencesStore()
		await store.load()

		await store.dismiss('b')

		expect(savePreferences).toHaveBeenCalledWith({ dismissed_hints: ['a', 'b'] })
		expect(store.isDismissed('b')).toBe(true)
	})

	/** Same reason: a write off an unread list would replace it with one entry. */
	it('reads the stored list before writing to it', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		vi.mocked(savePreferences).mockResolvedValue(settings(['a', 'b']))

		await usePreferencesStore().dismiss('b')

		expect(getPreferences).toHaveBeenCalled()
		expect(savePreferences).toHaveBeenCalledWith({ dismissed_hints: ['a', 'b'] })
	})

	/**
	 * Two hints answered before the first write comes back. The route replaces the whole list, so
	 * a second write built from the list the first one had not changed yet would drop the first
	 * dismissal for good - and the card it was clicked on is already gone from the screen.
	 */
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

	/** A hint dismissed twice is one dismissal, and the second click is not worth a request. */
	it('does not write a dismissal it already holds', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings(['a']))
		const store = usePreferencesStore()
		await store.load()

		await store.dismiss('a')

		expect(savePreferences).not.toHaveBeenCalled()
	})

	/**
	 * A refusal is reported to whoever asked for the write, as in the vehicles store: the screen
	 * that offered the click is what says so, and the hint stays until it really is dismissed.
	 */
	it('keeps the hint when the write is refused', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings([]))
		vi.mocked(savePreferences).mockRejectedValue(new Error('nope'))
		const store = usePreferencesStore()
		await store.load()

		await expect(store.dismiss('a')).rejects.toThrow('nope')
		expect(store.isDismissed('a')).toBe(false)
	})

	/** The header reads before anything arrives, so the defaults are the server's own. */
	it('reads gross over the last twelve months until told otherwise', async () => {
		const store = usePreferencesStore()
		expect(store.reclaimVat).toBe(false)
		expect(store.period).toBe('last-12')

		vi.mocked(getPreferences).mockResolvedValue(settings([], { reclaim_vat: true, kpi_period: 'this-year' }))
		await store.load()

		expect(store.reclaimVat).toBe(true)
		expect(store.period).toBe('this-year')
	})

	/**
	 * The header shows the chosen period at once: waiting for the write would read the figures
	 * twice, and a refused write costs only the next session's default.
	 */
	it('holds a chosen period before it is stored, and after a refusal', async () => {
		vi.mocked(savePreferences).mockRejectedValue(new Error('nope'))
		const store = usePreferencesStore()

		const writing = store.choosePeriod('last-year')
		expect(store.period).toBe('last-year')

		await expect(writing).rejects.toThrow('nope')
		expect(savePreferences).toHaveBeenCalledWith({ kpi_period: 'last-year' })
		expect(store.period).toBe('last-year')
	})

	/** Two picks in a row: the first one's answer must not flip the header back for a moment. */
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
