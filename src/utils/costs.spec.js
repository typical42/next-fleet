/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { barsOf, tableOf } from './costs.js'

/**
 * @param {object} [cost] - what differs from a priced month with nothing in it
 * @return {import('../services/api.js').Cost} the month's cost
 */
function cost(cost = {}) {
	return { currency: 'EUR', net: false, per: 'km', distance: null, total: 0, energy: 0, maintenance: 0, expenses: [], value: null, energy_value: null, tco: null, incomplete: false, unstated: false, ...cost }
}

/** A month with no rows: no sums at all (lib/Service/CostService.php). */
const EMPTY = cost({ total: null, energy: null, maintenance: null, expenses: null })

/**
 * @param {Record<number, import('../services/api.js').Cost>} costs - the months that differ from empty
 * @return {import('../services/api.js').CostYear} a year as the route answers it
 */
function year(costs) {
	const months = Array.from({ length: 12 }, (_, i) => ({ month: i + 1, from: 0, to: 0, cost: costs[i + 1] ?? EMPTY }))

	return { year: { consumption: [], wall_side: null, cost: cost(), hours: null }, months }
}

describe('the bars', () => {
	it('stacks energy, maintenance and expenses from the baseline, scaled to the dearest month', () => {
		const bars = barsOf(year({
			1: cost({ total: 20000, energy: 10000, maintenance: 6000, expenses: [{ category: 'toll', total: 1000 }, { category: null, total: 3000 }] }),
			2: cost({ total: 10000, energy: 10000 }),
		}))

		expect(bars[0]).toEqual({
			month: 1,
			empty: false,
			bands: [
				{ band: 'energy', from: 0, to: 0.5 },
				{ band: 'maintenance', from: 0.5, to: 0.8 },
				{ band: 'expenses', from: 0.8, to: 1 },
			],
		})
		expect(bars[1].bands).toEqual([{ band: 'energy', from: 0, to: 0.5 }])
	})

	/** A month with no rows is not a zero (docs/architecture.md#numbers-consumption-cost-emissions). */
	it('marks a month without rows as empty rather than drawing it at zero', () => {
		const bars = barsOf(year({ 3: cost({ total: 5000, energy: 5000 }) }))

		expect(bars).toHaveLength(12)
		expect(bars[0]).toEqual({ month: 1, empty: true, bands: [] })
		expect(bars[2].empty).toBe(false)
	})

	it('draws a priced month that cost nothing as no bar, and not as empty', () => {
		expect(barsOf(year({ 1: cost() }))[0]).toEqual({ month: 1, empty: false, bands: [] })
	})

	/** A refund is a row too, but a bar grows from the baseline up. */
	it('leaves a negative band out of the stack', () => {
		const bars = barsOf(year({ 1: cost({ total: 4000, energy: 5000, expenses: [{ category: 'fine', total: -1000 }] }) }))

		expect(bars[0].bands).toEqual([{ band: 'energy', from: 0, to: 1 }])
	})
})

describe('the table', () => {
	const answer = year({
		1: cost({ total: 20000, energy: 10000, maintenance: 6000, expenses: [{ category: 'toll', total: 1000 }, { category: null, total: 3000 }] }),
		2: cost({ total: 10000, energy: 10000 }),
	})
	answer.year.cost = cost({ total: 30000, energy: 20000, maintenance: 6000, expenses: [{ category: 'toll', total: 1000 }, { category: null, total: 3000 }] })

	it('heads the columns with the months and the year', () => {
		const { columns } = tableOf(answer, 'en')

		expect(columns).toHaveLength(13)
		expect(columns[0]).toBe('Jan')
		expect(columns[11]).toBe('Dec')
		expect(columns[12]).toBe('Year')
	})

	it('breaks the expenses out by category under the three bands, and totals the month', () => {
		const { rows } = tableOf(answer, 'en')

		expect(rows.map((row) => [row.label, row.item])).toEqual([
			['Energy', false],
			['Maintenance', false],
			['Expenses', false],
			['Toll', true],
			['Uncategorised', true],
			['Total', false],
		])
		expect(rows.map((row) => row.cells[0])).toEqual(['€100.00', '€60.00', '€40.00', '€10.00', '€30.00', '€200.00'])
		expect(rows.map((row) => row.cells[12])).toEqual(['€200.00', '€60.00', '€40.00', '€10.00', '€30.00', '€300.00'])
	})

	/** A priced month without that category spent nothing on it; that is a real zero. */
	it('states a zero for a category a priced month did not have', () => {
		const { rows } = tableOf(answer, 'en')

		expect(rows.find((row) => row.label === 'Toll')?.cells[1]).toBe('€0.00')
	})

	/** A month with no rows says so and is not a zero (docs/architecture.md#numbers-consumption-cost-emissions). */
	it('says a month without rows has none, rather than a zero', () => {
		const { rows } = tableOf(answer, 'en')

		expect(rows.map((row) => row.cells[2])).toEqual(['–', '–', '–', '–', '–', 'No entries'])
	})
})
