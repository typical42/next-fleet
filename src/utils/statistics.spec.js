/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { blank, daysOf, RANGES, rangeWord } from './statistics.js'

const NOW = new Date(2026, 8, 19, 15, 30)

describe('the ranges', () => {
	/** The vehicle page's "Last 12 months" is rolling (period.js); these are whole months. */
	it('offers the calendar year first, as the screen opens on it', () => {
		expect(RANGES.map(rangeWord)).toEqual(['Calendar year', 'Last 12 calendar months', 'All time', 'Between two days'])
	})

	it('reads a calendar year as its first day and the next year\'s', () => {
		expect(daysOf('year', NOW, { year: 2025 })).toEqual({ from: '2025-01-01', to: '2026-01-01' })
	})

	it('reads the last 12 months as this month and the eleven before it', () => {
		expect(daysOf('last-12', NOW, {})).toEqual({ from: '2025-10-01', to: '2026-10-01' })
	})

	it('names neither end for all of it, which the server reads from the first entry', () => {
		expect(daysOf('all', NOW, {})).toEqual({})
	})

	it('reads two days as the first and the day after the last, so the last counts', () => {
		expect(daysOf('between', NOW, { from: new Date(2026, 1, 3), to: new Date(2026, 1, 28) }))
			.toEqual({ from: '2026-02-03', to: '2026-03-01' })
		expect(daysOf('between', NOW, { from: new Date(2026, 1, 3), to: new Date(2026, 1, 3) }))
			.toEqual({ from: '2026-02-03', to: '2026-02-04' })
	})

	it('has no range while a day is missing or the last comes before the first', () => {
		expect(daysOf('between', NOW, { from: new Date(2026, 1, 3), to: null })).toBeNull()
		expect(daysOf('between', NOW, { from: new Date(2026, 1, 3), to: new Date(2026, 1, 2) })).toBeNull()
	})
})

describe('a blank answer', () => {
	const none = { total: null, energy: null, maintenance: null, expenses: null, incomplete: false, unstated: false }
	/** @type {any} one vehicle's year with nothing recorded, loose so a case adds a bare series */
	const BLANK = {
		bucket: 'month',
		buckets: [],
		vehicles: [{ uuid: 'v-1', costs: true, drivers: true }],
		costs: [{ currency: 'EUR', buckets: [], range: none }],
		per_distance: [{ currency: 'EUR', per: 'km', buckets: [], range: { total: null, distance: null, value: null }, vehicles: [] }],
		consumption: [],
		distance: [],
		by_driver: [],
		co2: { grams: null, unstated: false, source: 'https://example.org/fuels', year: null, grid: null },
	}

	it('has no cost, no counter move, no consumption, no trip and no CO₂', () => {
		expect(blank(BLANK)).toBe(true)
		expect(blank({ ...BLANK, co2: null })).toBe(true)
	})

	it('has a figure in a fill-up without a price, or without an emission factor', () => {
		expect(blank({ ...BLANK, costs: [{ ...BLANK.costs[0], range: { ...none, incomplete: true } }] })).toBe(false)
		expect(blank({ ...BLANK, co2: { ...BLANK.co2, unstated: true } })).toBe(false)
	})

	it('has a figure in a consumption line or a trip series', () => {
		expect(blank({ ...BLANK, consumption: [{ uuid: 'v-1', energy: 'diesel' }] })).toBe(false)
		expect(blank({ ...BLANK, distance: [{ per: 'km' }] })).toBe(false)
	})
})
