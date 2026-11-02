/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { daysOf, RANGES, rangeWord } from './statistics.js'

const NOW = new Date(2026, 8, 19, 15, 30)

describe('the ranges', () => {
	it('offers the calendar year first, as the screen opens on it', () => {
		expect(RANGES.map(rangeWord)).toEqual(['Calendar year', 'Last 12 months', 'All time', 'Between two days'])
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
