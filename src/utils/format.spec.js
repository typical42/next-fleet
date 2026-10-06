/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { categoryWord, formatConsumption, formatCount, formatDecimal, formatDay, formatEnergyAmount, formatMoney, formatWallSide, fullMoment, formatMonth, formatOdometer, formatSpan, formatWhen, isoInstant, monthKey, nameOf, parseDay, parseDecimal, parseWhole, shortDate, subtitleOf } from './format.js'

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

describe('formatEnergyAmount', () => {
	it('says litres for a fuel and kilowatt-hours for electricity', () => {
		expect(formatEnergyAmount(48200, 'diesel', 'de-DE')).toBe('48,2 l')
		expect(formatEnergyAmount(13456, 'electric', 'en-GB')).toBe('13.46 kWh')
		expect(formatEnergyAmount(40000, 'petrol', 'en-GB')).toBe('40 l')
	})
})

describe('formatConsumption', () => {
	it('says litres or kWh per 100 km, or per hour on an hour counter', () => {
		expect(formatConsumption({ value: 6.125, per: 'km' }, 'diesel', 'de-DE')).toBe('6,1 l/100 km')
		expect(formatConsumption({ value: 18, per: 'km' }, 'electric', 'en-GB')).toBe('18.0 kWh/100 km')
		expect(formatConsumption({ value: 4.5, per: 'h' }, 'diesel', 'en-GB')).toBe('4.5 l/h')
	})
})

describe('formatWallSide', () => {
	it('marks the figure approximate and says the losses are in it', () => {
		const { figure, note } = formatWallSide({ value: 19.04, per: 'km' }, 'de-DE')

		expect(figure).toBe('≈ 19,0 kWh/100 km')
		expect(note).toMatch(/charging losses/)
	})
})

describe('formatMoney', () => {
	it('writes cents in the vehicle\'s currency, the way the locale does', () => {
		// Intl keeps the symbol on the amount's line with a no-break space.
		expect(formatMoney(8210, 'EUR', 'de-DE')).toBe('82,10 €')
		expect(formatMoney(8210, 'EUR', 'en-GB')).toBe('€82.10')
	})

	it('writes the bare amount when the vehicle has no currency', () => {
		expect(formatMoney(8210, null, 'de-DE')).toBe('82,10')
	})

	/** The currency is free text of three characters (lib/Service/VehicleService.php). */
	it('writes a code Intl does not know beside the amount rather than failing', () => {
		expect(formatMoney(8210, 'X!', 'en-GB')).toBe('82.10 X!')
	})
})

describe('formatOdometer', () => {
	it('takes its unit from the vehicle', () => {
		expect(formatOdometer({ odo_value: 148320, odo_unit: 'km' }, 'de-DE')).toBe('148.320 km')
		expect(formatOdometer({ odo_value: 1200, odo_unit: 'h' }, 'de-DE')).toBe('1.200 h')
	})

	/** A vehicle never read has no odometer, which is a different fact from zero. */
	it('says nothing about a vehicle that has never been read', () => {
		expect(formatOdometer({ odo_value: null, odo_unit: 'km' }, 'de-DE')).toBe('')
	})
})

describe('nameOf', () => {
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

	it('spells out a lifecycle that is not the ordinary one', () => {
		expect(subtitleOf({ plate: 'M-EV 7', lifecycle: 'laid_up' })).toBe('Laid up')
		expect(subtitleOf({ plate: 'M-EV 7', manufacturer: 'Kia', lifecycle: 'laid_up' }))
			.toBe('Kia · Laid up')
	})
})

describe('parseWhole', () => {
	/** Reading German `148.320` with a decimal point would silently cache 148 as the odometer. */
	it('reads a grouped number back the way any locale writes it', () => {
		expect(parseWhole('148.320')).toBe(148320)
		expect(parseWhole('148,320')).toBe(148320)
		expect(parseWhole('148 320')).toBe(148320)
		expect(parseWhole('148320')).toBe(148320)
	})

	it('says nothing about a field that is not a whole number', () => {
		expect(parseWhole('7,2')).toBeNull()
		expect(parseWhole('full')).toBeNull()
		expect(parseWhole('-5')).toBeNull()
		expect(parseWhole('')).toBeNull()
	})
})

describe('formatDecimal', () => {
	/** Reads back via parseDecimal(): 1 900 basis points is 19 %, 1 799 tenths of a cent 1.799. */
	it('writes a scaled integer as the field would hold it', () => {
		expect(formatDecimal(1900, 2, 'de-DE')).toBe('19')
		expect(formatDecimal(1650, 2, 'de-DE')).toBe('16,5')
		expect(formatDecimal(1799, 3, 'de-DE')).toBe('1,799')
		expect(formatDecimal(123456, 3, 'en-GB')).toBe('123.456')
		expect(parseDecimal(formatDecimal(1799, 3, 'de-DE'), 3)).toBe(1799)
		// A locale with digits of its own still writes what parseDecimal() reads back.
		expect(parseDecimal(formatDecimal(1650, 2, 'fa'), 2)).toBe(1650)
	})
})

describe('parseDecimal', () => {
	/** 48,2 litres is 48 200 ml, 85,10 euros 8 510 cents, 1,799 a litre 1 799 tenths of a cent. */
	it('reads either decimal mark into the integer the column holds', () => {
		expect(parseDecimal('48,2', 3)).toBe(48200)
		expect(parseDecimal('48.2', 3)).toBe(48200)
		expect(parseDecimal('85,10', 2)).toBe(8510)
		expect(parseDecimal('1,799', 3)).toBe(1799)
		expect(parseDecimal('19', 2)).toBe(1900)
		expect(parseDecimal(' 7 ', 2)).toBe(700)
		expect(parseDecimal(',5', 3)).toBe(500)
	})

	/** In a field that keeps three decimals, `25.000` is twenty-five litres. */
	it('reads a point that groups thousands where nothing else can be meant', () => {
		expect(parseDecimal('1.234,56', 2)).toBe(123456)
		expect(parseDecimal('1.234,5', 3)).toBe(1234500)
		expect(parseDecimal('25.000', 2)).toBe(2500000)
		expect(parseDecimal('1.234.567', 2)).toBe(123456700)
		expect(parseDecimal('1.234.567,8', 3)).toBe(1234567800)
		expect(parseDecimal('25.000', 3)).toBe(25000)
	})

	it('says nothing about a field it cannot read without guessing', () => {
		expect(parseDecimal('1,2345', 3)).toBeNull()
		expect(parseDecimal('1,234.56', 2)).toBeNull()
		expect(parseDecimal('1.23,4', 2)).toBeNull()
		expect(parseDecimal('12.34.5', 2)).toBeNull()
		expect(parseDecimal('1.234,567', 2)).toBeNull()
		expect(parseDecimal('1234.567,8', 3)).toBeNull()
		// No thousand starts with a nought, so this is a price per litre typed into a field of cents.
		expect(parseDecimal('0.123', 2)).toBeNull()
		expect(parseDecimal('-5', 2)).toBeNull()
		expect(parseDecimal('full', 2)).toBeNull()
		expect(parseDecimal('', 2)).toBeNull()
	})
})

describe('a calendar day', () => {
	it('survives the trip through the picker', () => {
		expect(formatDay(parseDay('2019-03-07'))).toBe('2019-03-07')
		expect(formatDay(parseDay('2024-01-01'))).toBe('2024-01-01')
		expect(parseDay('2019-03-07')?.getDate()).toBe(7)
	})

	it('is nothing when there is no day', () => {
		expect(parseDay(null)).toBeNull()
		expect(parseDay('')).toBeNull()
		expect(formatDay(null)).toBe('')
	})
})

/** Half past midnight in Berlin on the 3rd, half past eleven on the 2nd in UTC. */
const BERLIN_NIGHT = { at: 1788391800, off: 120 }

/** An hour into September in Berlin, still August in UTC: the same question at a month boundary. */
const BERLIN_FIRST = { at: 1788217200, off: 120 }

describe('an instant on screen', () => {
	/** The day the driver was living in, with the separators the reader's locale writes. */
	it('is the day the offset it was entered at puts it on', () => {
		expect(shortDate(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'de-DE')).toBe('03.09.')
		expect(shortDate(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'en-GB')).toBe('03/09')
	})

	it('is named whole when nothing around it gives the month and year', () => {
		expect(fullMoment(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'de-DE')).toBe('03.09.2026, 01:30')
		expect(fullMoment(BERLIN_NIGHT.at, BERLIN_NIGHT.off, 'en-GB')).toBe('3 Sept 2026, 01:30')
	})

	/** The reason the offset is stored (docs/adr/0007-time-is-an-instant-plus-an-offset.md). */
	it('is a different day without its offset', () => {
		expect(shortDate(BERLIN_NIGHT.at, 0, 'de-DE')).toBe('02.09.')
	})

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

describe('formatSpan', () => {
	it('names a span within one day once, with both clock times', () => {
		expect(formatSpan(1790942400, 120, 1790956800, 120, 'en-GB')).toBe('Fri 02/10, 14:00–18:00')
		expect(formatSpan(1790942400, 120, 1790956800, 120, 'de-DE')).toBe('Fr., 02.10., 14:00–18:00 Uhr')
	})

	/** Each end is the wall clock of its own offset: a night that leaves summer time still ends at 10:00. */
	it('reads each end at its own offset', () => {
		expect(formatSpan(1792879200, 120, 1792918800, 60, 'en-GB')).toBe('Sun 25/10, 00:00–10:00')
	})

	// Intl sets the dash between thin spaces here.
	it('names both days when it runs over midnight', () => {
		expect(formatSpan(1790942400, 120, 1790942400 + 2 * 86400, 120, 'en-GB')).toBe('Fri 02/10, 14:00 – Sun 04/10, 14:00')
	})
})

describe('formatWhen', () => {
	it('names the weekday, the day and the clock time at the moment\'s own offset', () => {
		expect(formatWhen(1790956800, 120, 'en-GB')).toBe('Fri 02/10, 18:00')
		expect(formatWhen(1790956800, 120, 'de-DE')).toBe('Fr., 02.10., 18:00')
	})
})

describe('categoryWord', () => {
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
