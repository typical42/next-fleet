/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { formatCount, formatOdometer, parseCount } from './format.js'

vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-GB' }))

describe('formatCount', () => {
	/**
	 * A separator is the locale's, not the language's (docs/ui.md#languages): a German-speaking
	 * user reading an English UI still counts in thousands with a dot.
	 */
	it('groups thousands the way the locale does', () => {
		expect(formatCount(148320, 'de-DE')).toBe('148.320')
		expect(formatCount(148320, 'en-GB')).toBe('148,320')
	})
})

describe('formatOdometer', () => {
	/**
	 * The unit is the vehicle's, never a global switch: a generator is counted in hours and a car
	 * in kilometres, and a figure without its unit means nothing (docs/ui.md).
	 */
	it('takes its unit from the vehicle', () => {
		expect(formatOdometer({ odo_value: 148320, odo_unit: 'km' }, 'de-DE')).toBe('148.320 km')
		expect(formatOdometer({ odo_value: 1200, odo_unit: 'h' }, 'de-DE')).toBe('1.200 h')
	})

	/**
	 * A vehicle nobody has read yet has no odometer, which is a different fact from zero - and the
	 * overview shows a row for it either way.
	 */
	it('says nothing about a vehicle that has never been read', () => {
		expect(formatOdometer({ odo_value: null, odo_unit: 'km' }, 'de-DE')).toBe('')
	})
})

describe('parseCount', () => {
	/**
	 * The sheet is filled at a pump, one-handed, on whatever keyboard the phone offers, so both
	 * separators are the same number (docs/ui.md).
	 */
	it('reads a comma and a dot as the same decimal separator', () => {
		expect(parseCount('7,2')).toBe(7.2)
		expect(parseCount('7.2')).toBe(7.2)
	})
})
