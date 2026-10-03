/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { beforeEach, describe, expect, it, vi } from 'vitest'

import { listBookings, readTimeline } from '../services/api.js'
import { matching, readHistory } from './owners.js'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	listBookings: vi.fn(),
	readTimeline: vi.fn(),
}))

/** 2024-01-15 12:00 in Berlin. */
const OLD = 1705316400
/** 2026-09-01 10:00 in Berlin. */
const NEW = 1788249600

const INSPECTION = /** @type {any} */ ({ type: 'maintenance', occurred_at: OLD, occurred_at_off: 60, maintenance: { uuid: 'm-old', title: 'Inspektion' }, may: ['edit', 'delete'] })
const TYRES = /** @type {any} */ ({ type: 'maintenance', occurred_at: NEW, occurred_at_off: 120, maintenance: { uuid: 'm-new', title: 'Winter tyres' }, may: ['edit', 'delete'] })
/** Somebody else's, which the session may not file a paper on. */
const THEIRS = /** @type {any} */ ({ type: 'maintenance', occurred_at: NEW - 86400, occurred_at_off: 120, maintenance: { uuid: 'm-theirs', title: 'Inspektion' }, may: [] })
const FILL = /** @type {any} */ ({ type: 'energy', occurred_at: OLD, occurred_at_off: 60, energy: { uuid: 'e-old', energy: 'diesel' }, may: ['edit', 'delete'] })
const TAKEN = /** @type {any} */ ({ uuid: 'b-old', state: 'returned', purpose: 'Messe Hannover', starts_at: OLD, starts_at_off: 60, ends_at: OLD + 7200, ends_at_off: 60, may: ['attach'] })

beforeEach(() => {
	vi.resetAllMocks()
	// The newest maintenance page, then an older one; the other kinds hold one page each.
	vi.mocked(readTimeline).mockImplementation(async (uuid, { type, cursor }) => {
		if (type === 'maintenance') {
			return cursor ? { rows: [INSPECTION], next: null } : { rows: [TYRES, THEIRS], next: 'page-2' }
		}
		return { rows: type === 'energy' ? [FILL] : [], next: null }
	})
	vi.mocked(listBookings).mockResolvedValue([TAKEN])
})

describe('the history a paper may belong to', () => {
	/** An invoice filed late belongs to a record behind the newest page. */
	it('reads every page of each kind, and every booking since the first', async () => {
		const owners = await readHistory('v-1')

		expect(owners.map((one) => one.id)).toEqual(['m-new', 'e-old', 'm-old', 'b-old'])
		expect(listBookings).toHaveBeenCalledWith('v-1', { from: 0 })
	})

	it('names each row with its year, since the history spans years', async () => {
		const owners = await readHistory('v-1')

		expect(owners.find((one) => one.id === 'm-old')?.label).toBe('01/15/2024 · Inspektion')
	})
})

describe('searching it', () => {
	it('finds a row by a word of its title, whatever the case', async () => {
		const found = matching(await readHistory('v-1'), 'inspek')

		expect(found.map((one) => one.id)).toEqual(['m-old'])
	})

	it('finds rows by their date, as the label writes it or as an ISO day', async () => {
		const owners = await readHistory('v-1')

		expect(matching(owners, '01/15/2024').map((one) => one.id)).toEqual(['e-old', 'm-old', 'b-old'])
		expect(matching(owners, '2024-01').map((one) => one.id)).toEqual(['e-old', 'm-old', 'b-old'])
	})

	it('wants every word it is given', async () => {
		const found = matching(await readHistory('v-1'), '2024 diesel')

		expect(found.map((one) => one.id)).toEqual(['e-old'])
	})

	it('finds a booking by its purpose', async () => {
		const found = matching(await readHistory('v-1'), 'messe')

		expect(found.map((one) => one.id)).toEqual(['b-old'])
	})

	it('offers everything for an empty search', async () => {
		const owners = await readHistory('v-1')

		expect(matching(owners, '  ')).toEqual(owners)
	})
})
