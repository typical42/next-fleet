/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { averagesTableOf, bentOf, lineOf, rateTableOf, segmentsTableOf, vehiclesTableOf } from './trends.js'

/** @type {import('../services/api.js').PerDistance} */
const EUROS_PER_KM = {
	currency: 'EUR',
	per: 'km',
	buckets: [{ total: 6000, distance: null, value: null }, { total: 5000, distance: 800, value: 625 }, { total: null, distance: null, value: null }],
	range: { total: 11000, distance: 800, value: 625 },
	vehicles: [],
}

describe('the rate table', () => {
	it('carries each bucket\'s costs, distance and their rate, then the whole period\'s', () => {
		expect(rateTableOf(EUROS_PER_KM, ['Jan', 'Feb', 'Mar'], 'en-GB')).toEqual({
			columns: ['Jan', 'Feb', 'Mar', 'Whole period'],
			rows: [
				{ key: 'total', label: 'Costs', cells: ['€60.00', '€50.00', '–', '€110.00'] },
				{ key: 'distance', label: 'Distance', cells: ['–', '800 km', '–', '800 km'] },
				{ key: 'value', label: 'Cost per 100 km', cells: ['–', '€6.25', '–', '€6.25'] },
			],
		})
	})

	it('compares the vehicles over the whole period, the fleet last', () => {
		const series = {
			...EUROS_PER_KM,
			vehicles: [
				{ uuid: 'a', range: { total: 9000, distance: 500, value: 1800 } },
				{ uuid: 'b', range: { total: 2000, distance: null, value: null } },
			],
		}

		expect(vehiclesTableOf(series, { a: 'Golf', b: 'Passat' }, 'en-GB')).toEqual({
			columns: ['Costs', 'Distance', 'Cost per 100 km'],
			rows: [
				{ key: 'a', label: 'Golf', cells: ['€90.00', '500 km', '€18.00'] },
				{ key: 'b', label: 'Passat', cells: ['€20.00', '–', '–'] },
				{ key: '', label: 'All vehicles', cells: ['€110.00', '800 km', '€6.25'], total: true },
			],
		})
	})

	it('knows when a rate leaves out a cost without a distance', () => {
		expect(bentOf(EUROS_PER_KM)).toBe(true)
		expect(bentOf({ ...EUROS_PER_KM, buckets: [EUROS_PER_KM.buckets[0]], range: EUROS_PER_KM.buckets[0] })).toBe(true)
		expect(bentOf({ ...EUROS_PER_KM, buckets: [EUROS_PER_KM.buckets[1]], range: EUROS_PER_KM.buckets[1] })).toBe(false)
		expect(bentOf({ ...EUROS_PER_KM, per: 'h', buckets: [], range: { total: 4000, distance: 10, value: 400 } })).toBe(false)
		// A vehicle that moved without a cost is in the rate at 0.
		expect(bentOf({ ...EUROS_PER_KM, buckets: [{ total: null, distance: 400, value: 0 }], range: { total: 5000, distance: 1200, value: 5000 * 100 / 1200 } })).toBe(false)
	})

	it('rates a counter of hours per hour', () => {
		const rows = rateTableOf({ ...EUROS_PER_KM, per: 'h', range: { total: 4000, distance: 10, value: 400 } }, ['Jan', 'Feb', 'Mar'], 'en-GB').rows

		expect(rows[1].cells[3]).toBe('10 h')
		expect(rows[2]).toEqual({ key: 'value', label: 'Cost per hour', cells: ['–', '€6.25', '–', '€4.00'] })
	})
})

/** @type {import('../services/api.js').ConsumptionLine} */
const DIESEL = {
	uuid: 'a',
	energy: 'diesel',
	per: 'km',
	amount: 88000,
	distance: 1600,
	value: 5.5,
	segments: [
		{ energy: 'diesel', closes: 'x', filled_at: 1739613600, amount: 40000, distance: 800, per: 'km', value: 5 },
		{ energy: 'diesel', closes: 'y', filled_at: 1742032800, amount: 48000, distance: 800, per: 'km', value: 6 },
	],
}

describe('the consumption tables', () => {
	it('lists one vehicle\'s segments by the day each closed', () => {
		expect(segmentsTableOf(DIESEL, 'en-GB', 'Europe/Berlin')).toEqual({
			columns: ['Distance', 'Amount', 'Consumption'],
			rows: [
				{ key: 'x', label: '15 Feb 2025', cells: ['800 km', '40 l', '5.0 l/100 km'] },
				{ key: 'y', label: '15 Mar 2025', cells: ['800 km', '48 l', '6.0 l/100 km'] },
			],
		})
	})

	it('lists each vehicle\'s average per energy', () => {
		const electric = { ...DIESEL, energy: 'electric', amount: 36000, distance: 200, value: 18, segments: null }

		expect(averagesTableOf([{ ...DIESEL, segments: null }, electric], { a: 'Golf' }, 'en-GB')).toEqual({
			columns: ['Energy', 'Distance', 'Average'],
			rows: [
				{ key: 'a:diesel', label: 'Golf', cells: ['Diesel', '1,600 km', '5.5 l/100 km'] },
				{ key: 'a:electric', label: 'Golf', cells: ['Electric', '200 km', '18.0 kWh/100 km'] },
			],
		})
	})
})

describe('the line', () => {
	it('runs through the middle of each bucket, scaled to the highest, and breaks where a bucket has no figure', () => {
		expect(lineOf([null, 400, 800, null, 200])).toEqual([
			[{ i: 1, x: 15, y: 50 }, { i: 2, x: 25, y: 0 }],
			[{ i: 4, x: 45, y: 75 }],
		])
	})

	it('lies on the baseline when nothing is above it, and is not drawn at all without a figure', () => {
		expect(lineOf([0, 0])).toEqual([[{ i: 0, x: 5, y: 100 }, { i: 1, x: 15, y: 100 }]])
		expect(lineOf([null, null])).toEqual([])
	})
})
