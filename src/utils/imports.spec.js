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

	it('words a skipped category as the choice it was', () => {
		expect(reasonWord('skipped', 'Kostenart')).toBe('Skipped, as you chose for its category.')
	})

	it('words what a code names that no entry holds', () => {
		expect(reasonWord('adblue', 'Kraftstoff')).toBe('Kraftstoff says AdBlue, which is not a fuel.')
		expect(reasonWord('hydrogen', 'Fuel')).toBe('Fuel says hydrogen, which this app does not record.')
		expect(reasonWord('purchase', 'Kostenart')).toBe('A purchase price is not a running cost.')
		expect(reasonWord('refund', 'Kostenart')).toBe('A refund would be a negative cost.')
	})

	it('words a currency the vehicle is not kept in apart from a vehicle currency that is no code', () => {
		expect(reasonWord('currency', 'Cost')).toBe('Cost names a currency the vehicle is not kept in.')
		expect(reasonWord('currency', null)).toBe('The currency of the vehicle is not a three-letter code such as EUR. Correct it in the edit sheet of the vehicle, then preview again.')
	})

	it('words a reason with no column', () => {
		expect(reasonWord('duplicate', null)).toBe('Already there.')
		expect(reasonWord('cells', null)).toBe('The row has more cells than the file has columns.')
	})

	it('falls back to the word the server sent', () => {
		expect(reasonWord('something_new', 'Odo')).toBe('Odo: something_new')
	})

	/** A header is the file's own text, shown as it was written there (src/utils/l10n.js). */
	it('names a column as the file spells it', () => {
		expect(reasonWord('number', 'Kosten R&D')).toBe('Kosten R&D is not a number.')
		expect(reasonWord('missing', 'O\'Brien\'s km')).toBe('O\'Brien\'s km is empty.')
	})
})

describe('refusalWord', () => {
	it('says why the file is not read at all', () => {
		expect(refusalWord('too_large', null)).toBe('The file is larger than 5 MB.')
		expect(refusalWord('line_too_long', 7)).toBe('Row 7 is longer than 64 KiB, so the file is not read.')
		expect(refusalWord('too_many_cells', 1)).toBe('Row 1 has more than 256 cells, so the file is not read.')
		expect(refusalWord('too_many_cells', null)).toBe('The file has more than 500,000 cells.')
	})
})

describe('placedWord', () => {
	it('names the field a column became', () => {
		expect(placedWord('filled_at', 'fuel')).toBe('Date')
		expect(placedWord('date', 'costs')).toBe('Date')
		expect(placedWord('odo', 'service')).toBe('Counter')
		expect(placedWord('currency', 'fuel')).toBe('Currency (checked, not imported)')
	})

	it('tells a quantity from a sum of money', () => {
		expect(placedWord('amount', 'fuel')).toBe('Quantity')
		expect(placedWord('amount', 'tax')).toBe('Amount')
	})
})
