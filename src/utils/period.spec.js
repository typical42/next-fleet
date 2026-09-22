/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { periodOf } from './period.js'

// Local midnights, as the person's calendar has them: the periods are theirs, not UTC's.
/** @type {(year: number, month: number, day: number) => number} */
const at = (year, month, day) => new Date(year, month - 1, day).getTime() / 1000
const NOW = new Date(2026, 8, 19, 15, 30)

describe('the header period', () => {
	it('is the last twelve months up to the end of today by default, after the twelve before', () => {
		expect(periodOf('last-12', NOW)).toEqual({
			from: at(2025, 9, 20),
			to: at(2026, 9, 20),
			before: { from: at(2024, 9, 20), to: at(2025, 9, 20) },
		})
	})

	it('compares this year so far with last year as far', () => {
		expect(periodOf('this-year', NOW)).toEqual({
			from: at(2026, 1, 1),
			to: at(2026, 9, 20),
			before: { from: at(2025, 1, 1), to: at(2025, 9, 20) },
		})
	})

	it('compares last year with the one before it', () => {
		expect(periodOf('last-year', NOW)).toEqual({
			from: at(2025, 1, 1),
			to: at(2026, 1, 1),
			before: { from: at(2024, 1, 1), to: at(2025, 1, 1) },
		})
	})

	it('compares one month with the month before, across a year', () => {
		expect(periodOf('month', NOW, '2026-01')).toEqual({
			from: at(2026, 1, 1),
			to: at(2026, 2, 1),
			before: { from: at(2025, 12, 1), to: at(2026, 1, 1) },
		})
	})

	it('takes this month when no month was picked', () => {
		expect(periodOf('month', NOW).from).toBe(at(2026, 9, 1))
	})
})
