/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { categoryWord, formatCount, formatDay, formatMonth, formatOdometer, isoInstant, monthKey, nameOf, parseDay, parseWhole, shortDate, subtitleOf } from './format.js'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-GB',
	t: (/** @type {string} */ app, /** @type {string} */ text) => text,
}))

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

describe('nameOf', () => {
	/**
	 * The plate is what a driver calls the vehicle, so it is the label wherever one is needed - and
	 * it stays a label: identity is the uuid (CONTEXT.md), which is why a plate may be missing.
	 */
	it('calls a vehicle by its plate, and by its make when it has none', () => {
		expect(nameOf({ plate: 'M-AB 1234', manufacturer: 'VW', model: 'Passat' })).toBe('M-AB 1234')
		expect(nameOf({ manufacturer: 'VW', model: 'Passat' })).toBe('VW Passat')
		expect(nameOf({ model: 'Passat' })).toBe('Passat')
	})

	/** A vehicle with nothing filled in is still a row somebody has to be able to click. */
	it('names a vehicle that says nothing about itself', () => {
		expect(nameOf({})).toBe('Unnamed vehicle')
	})
})

describe('subtitleOf', () => {
	/** The second line says what the first one could not; repeating the name teaches nothing. */
	it('says the make under the plate, and nothing under the make', () => {
		expect(subtitleOf({ plate: 'M-AB 1234', manufacturer: 'VW', model: 'Passat', lifecycle: 'active' }))
			.toBe('VW Passat')
		expect(subtitleOf({ manufacturer: 'VW', model: 'Passat', lifecycle: 'active' })).toBe('')
	})

	/**
	 * A lifecycle is a code in the database and a word on screen (docs/ui.md#languages), and it is
	 * only worth a line when it is not the ordinary one: an off-the-road vehicle explains why it
	 * sank down the list.
	 */
	it('spells out a lifecycle that is not the ordinary one', () => {
		expect(subtitleOf({ plate: 'M-EV 7', lifecycle: 'laid_up' })).toBe('Laid up')
		expect(subtitleOf({ plate: 'M-EV 7', manufacturer: 'Kia', lifecycle: 'laid_up' }))
			.toBe('Kia · Laid up')
	})
})

describe('parseWhole', () => {
	/**
	 * The counter is what the app itself wrote out a moment earlier, and in German that reads
	 * `148.320` (docs/ui.md#languages). Reading the dot as a decimal point would turn it into 148
	 * and cache that as the vehicle's odometer, with nothing on screen to say so.
	 */
	it('reads a grouped number back the way any locale writes it', () => {
		expect(parseWhole('148.320')).toBe(148320)
		expect(parseWhole('148,320')).toBe(148320)
		expect(parseWhole('148 320')).toBe(148320)
		expect(parseWhole('148320')).toBe(148320)
	})

	/**
	 * A counter reads in whole kilometres or whole hours (docs/architecture.md#data-model). A
	 * field that says something else is a question for the driver, not a number to round.
	 */
	it('says nothing about a field that is not a whole number', () => {
		expect(parseWhole('7,2')).toBeNull()
		expect(parseWhole('full')).toBeNull()
		expect(parseWhole('-5')).toBeNull()
		expect(parseWhole('')).toBeNull()
	})
})

describe('a calendar day', () => {
	/**
	 * A day is one fact and carries no time of day (docs/architecture.md#time). The date picker
	 * speaks Date, so the day has to survive the trip through one - and a UTC midnight read back
	 * with a local getter is the day before, west of Greenwich.
	 */
	it('survives the trip through the picker', () => {
		expect(formatDay(parseDay('2019-03-07'))).toBe('2019-03-07')
		expect(formatDay(parseDay('2024-01-01'))).toBe('2024-01-01')
		expect(parseDay('2019-03-07')?.getDate()).toBe(7)
	})

	/** A vehicle that was never disposed of has no disposal day, and says so. */
	it('is nothing when there is no day', () => {
		expect(parseDay(null)).toBeNull()
		expect(parseDay('')).toBeNull()
		expect(formatDay(null)).toBe('')
	})
})

/**
 * Half past midnight in Berlin on the 3rd, which is half past eleven the evening before in UTC. A
 * Fahrtenbuch is judged on local calendar dates (docs/architecture.md#time), so the pair is what
 * says which day a row belongs to - the instant on its own says the wrong one.
 */
const BERLIN_NIGHT = { at: 1788391800, off: 120 }

/** An hour into September in Berlin, still August in UTC: the same question at a month boundary. */
const BERLIN_FIRST = { at: 1788217200, off: 120 }

describe('an instant on screen', () => {
	/** The day the driver was living in, with the separators the reader's locale writes. */
	it('is the day the offset it was entered at puts it on', () => {
		expect(shortDate(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'de-DE')).toBe('03.09.')
		expect(shortDate(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'en-GB')).toBe('03/09')
	})

	/**
	 * The same instant read without its offset is the 2nd, which is the whole reason the offset is
	 * stored beside it (docs/adr/0007-time-is-an-instant-plus-an-offset.md).
	 */
	it('is a different day without its offset', () => {
		expect(shortDate(BERLIN_NIGHT.at, 0, 'de-DE')).toBe('02.09.')
	})

	/**
	 * What a machine reads off the markup, and it has to be the moment the text beside it states -
	 * the plain UTC instant names the day before, which is what anyone not reading the rendered
	 * text would be told.
	 */
	it('states the same moment to a machine as to a reader', () => {
		expect(isoInstant(BERLIN_NIGHT.at, BERLIN_NIGHT.off)).toBe('2026-09-03T01:30:00+02:00')
		expect(isoInstant(BERLIN_NIGHT.at, 0)).toBe('2026-09-02T23:30:00+00:00')
		// Newfoundland is half an hour off the hour, and west of Greenwich.
		expect(isoInstant(BERLIN_NIGHT.at, -150)).toBe('2026-09-02T21:00:00-02:30')
	})

	/** The sticky header's word, and the key that groups the rows under it (docs/ui.md). */
	it('names its month and groups by it', () => {
		expect(formatMonth(BERLIN_FIRST.at, BERLIN_FIRST.off, 'de-DE')).toBe('September 2026')
		expect(monthKey(BERLIN_FIRST.at, BERLIN_FIRST.off)).toBe('2026-09')
		expect(monthKey(BERLIN_FIRST.at, 0)).toBe('2026-08')
	})
})

describe('categoryWord', () => {
	/** A category is a code in the database and a word on screen (docs/ui.md#languages). */
	it('has a word for every category a trip is entered under', () => {
		expect(categoryWord('business')).toBe('Business')
		expect(categoryWord('private')).toBe('Private')
		expect(categoryWord('commute')).toBe('Commute')
	})

	/** Untranslated beats missing: the row still says what the journey was driven for. */
	it('falls back to the code it was given', () => {
		expect(categoryWord('something-else')).toBe('something-else')
	})
})
