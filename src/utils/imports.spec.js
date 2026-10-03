/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { FORMATS, formatWord, placedWord, reasonWord, refusalWord, unitsAsked } from './imports.js'

const DIESEL = { uuid: 'v-1', energy_types: ['diesel'] }
const ELECTRIC = { uuid: 'v-2', energy_types: ['electric'] }
const PLUG_IN = { uuid: 'v-3', energy_types: ['petrol', 'electric'] }

describe('FORMATS', () => {
	it('offers every record type of both importers, one file each', () => {
		expect(FORMATS.map((one) => `${one.importer}/${one.recordType}`)).toEqual([
			'lubelogger/fuel', 'lubelogger/service', 'lubelogger/repair', 'lubelogger/upgrade',
			'lubelogger/tax', 'lubelogger/supplies', 'lubelogger/odometer',
			'spritmonitor/fuel', 'spritmonitor/costs',
		])
	})

	/** Nominative only (docs/legal.md): the other project's name says whose format it is. */
	it('names a format by its file and the record type', () => {
		expect(formatWord(FORMATS[0])).toBe('CSV (LubeLogger format) — fuel')
		expect(formatWord(FORMATS[8])).toBe('CSV (Spritmonitor format) — costs')
	})
})

describe('unitsAsked', () => {
	it('asks the distance of every file with a counter, and the volume of fuel', () => {
		expect(unitsAsked(FORMATS[0], DIESEL)).toEqual(['distance', 'volume'])
		expect(unitsAsked(FORMATS[1], DIESEL)).toEqual(['distance'])
		expect(unitsAsked(FORMATS[6], DIESEL)).toEqual(['distance'])
	})

	/** LubeLogger's tax and supplies files carry no counter (lib/Import/LubeLoggerImporter.php). */
	it('asks nothing of a file without a counter', () => {
		expect(unitsAsked(FORMATS[4], DIESEL)).toEqual([])
		expect(unitsAsked(FORMATS[5], DIESEL)).toEqual([])
	})

	/** Spritmonitor's costs file has its counter column, so the server asks its unit. */
	it('asks the distance of a Spritmonitor costs file', () => {
		expect(unitsAsked(FORMATS[8], DIESEL)).toEqual(['distance'])
	})

	/** Kilowatt-hours are kilowatt-hours; only litres come in two kinds of gallon. */
	it('asks no volume of a vehicle that only charges', () => {
		expect(unitsAsked(FORMATS[0], ELECTRIC)).toEqual(['distance'])
		expect(unitsAsked(FORMATS[0], PLUG_IN)).toEqual(['distance', 'volume'])
	})
})

describe('reasonWord', () => {
	it('says why a row is left out, naming its column', () => {
		expect(reasonWord('number', 'Cost')).toBe('Cost is not a number.')
		expect(reasonWord('missing', 'FuelConsumed')).toBe('FuelConsumed is empty.')
		expect(reasonWord('date', 'Datum')).toBe('Datum is not a date this import reads.')
	})

	/** A skip is the user's own answer, not a fault of the file (lib/Import/SpritmonitorImporter.php). */
	it('words a skipped category as the choice it was', () => {
		expect(reasonWord('skipped', 'Kostenart')).toBe('Skipped, as you chose for its category.')
	})

	/** What a Spritmonitor code names that no entry here holds (lib/Import/SpritmonitorImporter.php). */
	it('words what a code names that no entry holds', () => {
		expect(reasonWord('adblue', 'Kraftstoff')).toBe('Kraftstoff says AdBlue, which is no fuel.')
		expect(reasonWord('hydrogen', 'Fuel')).toBe('Fuel says hydrogen, which this app does not record.')
		expect(reasonWord('purchase', 'Kostenart')).toBe('A purchase price is not a running cost.')
		expect(reasonWord('refund', 'Kostenart')).toBe('A refund would be a negative cost.')
	})

	/** `marked()` blames no column for a fill-up of an energy the vehicle does not take. */
	it('words a reason with no column', () => {
		expect(reasonWord('energy', null)).toBe('The vehicle takes no such energy.')
		expect(reasonWord('duplicate', null)).toBe('Already there.')
	})

	it('falls back to the word the server sent', () => {
		expect(reasonWord('something_new', 'Odo')).toBe('Odo: something_new')
	})
})

describe('refusalWord', () => {
	it('says why the file is not read at all', () => {
		expect(refusalWord('too_large', null)).toBe('The file is larger than 5 MB.')
		expect(refusalWord('line_too_long', 7)).toBe('Row 7 is longer than 64 KiB, so the file is not read.')
	})
})

describe('placedWord', () => {
	/** Spritmonitor's costs report names checks, not entry fields (`date`, `currency`, …). */
	it('names the field a column became', () => {
		expect(placedWord('filled_at', 'fuel')).toBe('Date')
		expect(placedWord('date', 'costs')).toBe('Date')
		expect(placedWord('odo', 'service')).toBe('Counter')
		expect(placedWord('currency', 'fuel')).toBe('Currency (checked, not imported)')
	})

	/** A fill-up's `amount` is litres or kWh; everyone else's is money. */
	it('tells a quantity from a sum of money', () => {
		expect(placedWord('amount', 'fuel')).toBe('Quantity')
		expect(placedWord('amount', 'tax')).toBe('Amount')
	})
})
