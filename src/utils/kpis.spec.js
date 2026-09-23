/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { periodTilesOf, tilesOf } from './kpis.js'

const CAR = { uuid: 'v-1', updated_at: 1700000000, odo_value: 48210, odo_unit: 'km', second_unit: null, currency: 'EUR', energy_types: ['diesel'] }

/**
 * @param {object} [cost] - what differs from a plain euro period with a distance
 * @return {import('../services/api.js').Cost} the cost part of an answer
 */
function cost(cost = {}) {
	return { currency: 'EUR', net: false, per: 'km', distance: 1000, total: 20000, energy: 12000, maintenance: 0, expenses: [], value: 2000, energy_value: 1200, tco: null, incomplete: false, unstated: false, ...cost }
}

/**
 * @param {object} [kpis] - what differs from an answer with nothing in it but the cost
 * @return {import('../services/api.js').Kpis} the answer
 */
function kpis(kpis = {}) {
	return { consumption: [], wall_side: null, cost: cost(), hours: null, ...kpis }
}

const DIESEL = { energy: 'diesel', amount: 61000, distance: 1000, per: 'km', value: 6.1 }

/**
 * @param {ReturnType<typeof tilesOf>} tiles - the header
 * @param {string} label - what the tile is called
 * @return {ReturnType<typeof tilesOf>[number]|undefined} the tile, when the header has one called so
 */
function tile(tiles, label) {
	return tiles.find((one) => one.label === label)
}

describe('the header tiles', () => {
	it('states the odometer, consumption and the two costs of a plain car, in that order', () => {
		const tiles = tilesOf(CAR, kpis({ consumption: [DIESEL] }), null, 'en')

		expect(tiles.map((one) => [one.label, one.figure])).toEqual([
			['Odometer', '48,210 km'],
			['Diesel consumption', '6.1 l/100 km'],
			['Cost', '€20.00/100 km'],
			['Energy cost', '€12.00/100 km'],
		])
	})

	it('compares each figure with the period before, signed', () => {
		const before = kpis({ consumption: [{ ...DIESEL, value: 6.4 }], cost: cost({ value: 1900, energy_value: 1200 }) })
		const tiles = tilesOf(CAR, kpis({ consumption: [DIESEL] }), before, 'en')

		expect(tile(tiles, 'Diesel consumption')?.change).toBe('−0.3 l/100 km vs. the period before')
		expect(tile(tiles, 'Cost')?.change).toBe('+€1.00/100 km vs. the period before')
		expect(tile(tiles, 'Energy cost')?.change).toBe('±€0.00/100 km vs. the period before')
		// The counter is where the vehicle stands, not something a period has.
		expect(tile(tiles, 'Odometer')?.change).toBeNull()
	})

	it('says nothing about a period before that has no such figure', () => {
		const tiles = tilesOf(CAR, kpis({ consumption: [DIESEL] }), kpis({ cost: cost({ distance: null, value: null, energy_value: null }) }), 'en')

		expect(tile(tiles, 'Diesel consumption')?.change).toBeNull()
		expect(tile(tiles, 'Cost')?.change).toBeNull()
	})

	it('never compares a figure per hour with one per 100 km', () => {
		const before = kpis({ consumption: [{ ...DIESEL, per: 'h', value: 4.5 }], cost: cost({ per: 'h' }) })

		const tiles = tilesOf(CAR, kpis({ consumption: [DIESEL] }), before, 'en')

		expect(tile(tiles, 'Diesel consumption')?.change).toBeNull()
		expect(tile(tiles, 'Cost')?.change).toBeNull()
	})

	it('shows a plug-in hybrid two consumptions and the wall-side figure, never a blend', () => {
		const hybrid = { ...CAR, energy_types: ['petrol', 'electric'] }
		const answer = kpis({
			consumption: [
				{ energy: 'petrol', amount: 30000, distance: 500, per: 'km', value: 6 },
				{ energy: 'electric', amount: 90000, distance: 500, per: 'km', value: 18 },
			],
			wall_side: { amount: 100000, distance: 500, per: 'km', value: 20 },
		})

		const tiles = tilesOf(hybrid, answer, null, 'en')

		expect(tile(tiles, 'Petrol consumption')?.figure).toBe('6.0 l/100 km')
		expect(tile(tiles, 'Electric consumption')?.figure).toBe('18.0 kWh/100 km')
		expect(tile(tiles, 'Electric, at the charger')).toMatchObject({
			figure: '≈ 20.0 kWh/100 km',
			notes: ['Approximate: counted at the charger, so charging losses are included'],
		})
	})

	it('shows the wall-side figure only on a vehicle that charges', () => {
		const tiles = tilesOf(CAR, kpis({ wall_side: { amount: 1000, distance: 10, per: 'km', value: 10 } }), null, 'en')

		expect(tile(tiles, 'Electric, at the charger')).toBeUndefined()
	})

	it('states an hour vehicle per hour', () => {
		const tractor = { ...CAR, odo_value: 1200, odo_unit: 'h' }
		const answer = kpis({ consumption: [{ ...DIESEL, per: 'h', value: 4.5 }], cost: cost({ per: 'h', value: 1500, energy_value: 900 }) })

		const tiles = tilesOf(tractor, answer, null, 'en')

		expect(tiles.map((one) => one.figure)).toEqual(['1,200 h', '4.5 l/h', '€15.00/h', '€9.00/h'])
	})

	it('states the period total when no Reading gives a distance', () => {
		const answer = kpis({ cost: cost({ distance: null, value: null, energy_value: null, total: 45000, energy: 5000 }) })
		const before = kpis({ cost: cost({ distance: null, value: null, energy_value: null, total: 40000, energy: 5000 }) })

		const tiles = tilesOf(CAR, answer, before, 'en')

		expect(tile(tiles, 'Cost in the period')).toMatchObject({ figure: '€450.00', change: '+€50.00 vs. the period before' })
		expect(tile(tiles, 'Energy cost in the period')?.figure).toBe('€50.00')
		expect(tile(tiles, 'Cost')).toBeUndefined()
	})

	it('compares no cost against a period before that recorded none', () => {
		const empty = { total: null, energy: null, value: null, energy_value: null, tco: null }

		const tiles = tilesOf(CAR, kpis(), kpis({ cost: cost(empty) }), 'en')
		const alone = tilesOf(CAR, kpis({ cost: cost(empty) }), null, 'en')

		expect(tile(tiles, 'Cost')).toMatchObject({ figure: '€20.00/100 km', change: null })
		expect(tile(tiles, 'Energy cost')?.change).toBeNull()
		expect(tile(alone, 'Cost')?.figure).toBe('€0.00/100 km')
	})

	it('compares a period that recorded no cost against nothing', () => {
		const empty = { total: null, energy: null, value: null, energy_value: null, tco: null }

		const tiles = tilesOf(CAR, kpis({ cost: cost(empty) }), kpis(), 'en')

		expect(tile(tiles, 'Cost')).toMatchObject({ figure: '€0.00/100 km', change: null })
		expect(tile(tiles, 'Energy cost')?.change).toBeNull()
	})

	it('says "no currency" instead of any cost on a vehicle without one', () => {
		const answer = kpis({ cost: cost({ currency: null, total: null, energy: null, value: null, energy_value: null }) })

		const tiles = tilesOf({ ...CAR, currency: null }, answer, null, 'en')

		expect(tile(tiles, 'Cost')?.figure).toBe('No currency')
		expect(tile(tiles, 'Energy cost')).toBeUndefined()
		expect(tile(tiles, 'TCO')).toBeUndefined()
	})

	it('says what a cost figure leaves out', () => {
		const tiles = tilesOf(CAR, kpis({ cost: cost({ net: true, incomplete: true, unstated: true }) }), null, 'en')

		expect(tile(tiles, 'Cost')?.notes).toEqual([
			'Net of VAT',
			'Incomplete: a fill-up in the period has no price',
			'Rows without a VAT rate count gross',
		])
		expect(tile(tiles, 'Energy cost')?.notes).toEqual([
			'Net of VAT',
			'Incomplete: a fill-up in the period has no price',
			'Rows without a VAT rate count gross',
		])
	})

	/** The Costs screen states a year's figures, and the counter belongs to no period. */
	it('states the period\'s figures without the odometer', () => {
		const tiles = periodTilesOf(CAR, kpis({ consumption: [DIESEL], cost: cost({ tco: 3500 }) }), null, 'en')

		expect(tiles.map((one) => one.label)).toEqual(['Diesel consumption', 'Cost', 'Energy cost', 'TCO'])
	})

	it('shows the TCO only when there is one', () => {
		expect(tile(tilesOf(CAR, kpis(), null, 'en'), 'TCO')).toBeUndefined()

		const tiles = tilesOf(CAR, kpis({ cost: cost({ tco: 3500 }) }), kpis({ cost: cost({ tco: 3000 }) }), 'en')

		expect(tile(tiles, 'TCO')).toMatchObject({ figure: '€35.00/100 km', change: '+€5.00/100 km vs. the period before' })
	})

	it('states engine hours in the period on a two-counter vehicle, and what they mean for consumption', () => {
		const truck = { ...CAR, second_unit: 'h' }

		const tiles = tilesOf(truck, kpis({ consumption: [DIESEL], hours: 120 }), kpis({ hours: 100 }), 'en')

		expect(tile(tiles, 'Engine hours in the period')).toMatchObject({
			figure: '120 h',
			change: '+20 h vs. the period before',
			notes: ['Consumption includes fuel used while working'],
		})
		// Still measured against kilometres (docs/architecture.md), not per hour.
		expect(tile(tiles, 'Diesel consumption')?.figure).toBe('6.1 l/100 km')
	})

	it('leaves engine hours out when the second counter did not move', () => {
		expect(tile(tilesOf({ ...CAR, second_unit: 'h' }, kpis(), null, 'en'), 'Engine hours in the period')).toBeUndefined()
	})

	it('states the odometer alone until the period is read', () => {
		expect(tilesOf(CAR, null, null, 'en').map((one) => one.label)).toEqual(['Odometer'])
	})

	it('still says the counter was never read', () => {
		expect(tile(tilesOf({ ...CAR, odo_value: null }, kpis(), null, 'en'), 'Odometer')?.figure).toBe('Never read')
	})
})
