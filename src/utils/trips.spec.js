/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { driversTableOf, tripBarsOf, tripTableOf, tripTitlesOf } from './trips.js'

/** @type {import('../services/api.js').DistanceSeries} */
const KM = {
	per: 'km',
	buckets: [
		{ trips: 0, business: 0, private: 0, commute: 0 },
		{ trips: 3, business: 120, private: 30, commute: 50 },
		{ trips: 1, business: 0, private: 0, commute: 0 },
	],
	range: { trips: 4, business: 120, private: 30, commute: 50 },
}

describe('the trip bars', () => {
	it('stack the categories, scaled to the longest bucket, and tell a bucket without trips', () => {
		expect(tripBarsOf(KM)).toEqual([
			{ empty: true, bands: [] },
			{
				empty: false,
				bands: [
					{ band: 'business', from: 0, to: 0.6 },
					{ band: 'private', from: 0.6, to: 0.75 },
					{ band: 'commute', from: 0.75, to: 1 },
				],
			},
			{ empty: false, bands: [] },
		])
	})

	it('say each bucket\'s distance on hover, or that it has no trips', () => {
		expect(tripTitlesOf(KM, ['Jan', 'Feb', 'Mar'], 'en-GB')).toEqual(['Jan: No entries', 'Feb: 200 km', 'Mar: 0 km'])
	})
})

describe('the trip table', () => {
	it('carries each category\'s distance by bucket, the whole period and its share, then the total', () => {
		expect(tripTableOf(KM, ['Jan', 'Feb', 'Mar'], 'en-GB')).toEqual({
			columns: ['Jan', 'Feb', 'Mar', 'Whole period', 'Share'],
			rows: [
				{ key: 'business', label: 'Business', cells: ['–', '120 km', '0 km', '120 km', '60%'] },
				{ key: 'private', label: 'Private', cells: ['–', '30 km', '0 km', '30 km', '15%'] },
				{ key: 'commute', label: 'Commute', cells: ['–', '50 km', '0 km', '50 km', '25%'] },
				{ key: 'total', label: 'Total', cells: ['No entries', '200 km', '0 km', '200 km', '–'], total: true },
			],
		})
	})
})

describe('the drivers table', () => {
	it('lists each driver\'s trips, distance and business share', () => {
		/** @type {import('../services/api.js').DriverSeries} */
		const series = {
			per: 'km',
			drivers: [
				{ user_id: 'anna', name: 'Anna', trips: 2, distance: 50, business: 20 },
				{ user_id: 'erased:k3x9qk3x9qk3x9qk3x9q', name: 'erased:k3x9qk3x9qk3x9qk3x9q', trips: 1, distance: 0, business: 0 },
			],
		}

		expect(driversTableOf(series, 'en-GB')).toEqual({
			columns: ['Trips', 'Distance', 'Business share'],
			rows: [
				{ key: 'anna', label: 'Anna', cells: ['2', '50 km', '40%'] },
				{ key: 'erased:k3x9qk3x9qk3x9qk3x9q', label: 'erased:k3x9qk3x9qk3x9qk3x9q', cells: ['1', '0 km', '–'] },
			],
		})
	})
})
