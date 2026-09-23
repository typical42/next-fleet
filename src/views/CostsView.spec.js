/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { readYear } from '../services/api.js'
import CostsView from './CostsView.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readYear: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_value: 48210, odo_unit: 'km', second_unit: null, currency: 'EUR', energy_types: ['diesel'] }

/**
 * @param {object} [cost] - what differs from a priced period with nothing in it
 * @return {import('../services/api.js').Cost} a period's cost
 */
function cost(cost = {}) {
	return { currency: 'EUR', net: false, per: 'km', distance: 1000, total: 0, energy: 0, maintenance: 0, expenses: [], value: 0, energy_value: 0, tco: null, incomplete: false, unstated: false, ...cost }
}

const EMPTY = cost({ distance: null, total: null, energy: null, maintenance: null, expenses: null, value: null, energy_value: null })

/**
 * @param {object} [year] - what differs in the year's own cost
 * @return {import('../services/api.js').CostYear} a year whose March cost €120
 */
function answer(year = {}) {
	const march = cost({ total: 12000, energy: 9000, maintenance: 3000, value: 1200, energy_value: 900 })

	return {
		year: { consumption: [], wall_side: null, cost: cost({ total: 12000, energy: 9000, maintenance: 3000, value: 1200, energy_value: 900, tco: 3500, ...year }), hours: null },
		co2: { grams: 109630, unstated: false, source: 'https://example.org/fuels', grid: null },
		months: Array.from({ length: 12 }, (_, i) => ({ month: i + 1, from: 0, to: 0, cost: i === 2 ? march : EMPTY })),
	}
}

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the screen, past its first read
 */
async function screen() {
	const wrapper = mount(CostsView, { props: { vehicle: VEHICLE } })
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the row's head
 * @return {string[]} the row's cells
 */
function row(wrapper, label) {
	const found = wrapper.findAll('tbody tr').find((one) => one.find('th').text() === label)

	return found ? found.findAll('td').map((one) => one.text()) : []
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the button's accessible name
 * @return {import('@vue/test-utils').DOMWrapper<HTMLButtonElement>} the button
 */
function button(wrapper, label) {
	const found = wrapper.findAll('button').find((one) => one.attributes('aria-label') === label || one.text() === label)
	if (!found) {
		throw new Error(`No button "${label}"`)
	}

	return found
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 8, 19, 15, 30))
	vi.mocked(readYear).mockResolvedValue(answer())
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the Costs screen', () => {
	it('reads this year in the reader\'s own zone', async () => {
		await screen()

		expect(readYear).toHaveBeenCalledWith('v-1', { year: '2026', tz: Intl.DateTimeFormat().resolvedOptions().timeZone, net: false })
	})

	it('tabulates each month, and says a month without rows has none', async () => {
		const wrapper = await screen()

		expect(row(wrapper, 'Energy')[2]).toBe('€90.00')
		expect(row(wrapper, 'Total')[2]).toBe('€120.00')
		expect(row(wrapper, 'Total')[0]).toBe('No entries')
		expect(row(wrapper, 'Total')[12]).toBe('€120.00')
	})

	it('draws one bar per month with rows, in its bands', async () => {
		const wrapper = await screen()

		expect(wrapper.findAll('svg rect.costs__band').map((one) => one.attributes('data-band'))).toEqual(['energy', 'maintenance'])
	})

	it('states the year\'s figures, TCO included', async () => {
		const wrapper = await screen()

		expect(wrapper.findAll('.tile dt').map((one) => one.text())).toEqual(['Cost', 'Energy cost', 'TCO'])
	})

	/** A spreadsheet's year is the one on screen, one file per table (docs/architecture.md#csv-export). */
	it('offers the year on screen as four CSV files', async () => {
		const wrapper = await screen()
		await button(wrapper, 'Previous year').trigger('click')
		await flushPromises()

		/** @type {string[]} */
		const saved = []
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(/** @this {HTMLAnchorElement} */ function() {
			saved.push(this.download === '' ? this.getAttribute('href') ?? '' : 'not a download')
		})

		await button(wrapper, 'Export').trigger('click')
		await flushPromises()
		const items = [...document.body.querySelectorAll('[role="menuitem"]')]
		expect(items.map((one) => one.textContent?.trim())).toEqual(['Trips', 'Fill-ups', 'Maintenance', 'Expenses'])
		for (const item of items) {
			item.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		}

		// The address itself is csvUrl()'s (api.spec.js); jsdom has no webroot to put before it.
		expect(saved.map((href) => href.replace(/^.*\/apps\//, '/apps/'))).toEqual(['trips', 'energy', 'maintenance', 'expenses']
			.map((table) => `/apps/nextfleet/vehicles/v-1/csv/2025/${table}`))
		click.mockRestore()
		wrapper.unmount()
	})

	it('steps to the year before, and not past this one', async () => {
		const wrapper = await screen()
		expect(button(wrapper, 'Next year').attributes('disabled')).toBeDefined()

		await button(wrapper, 'Previous year').trigger('click')
		await flushPromises()

		expect(readYear).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ year: '2025' }))
		expect(wrapper.text()).toContain('2025')
	})

	/** Nothing is summed across currencies (docs/architecture.md#numbers-consumption-cost-emissions). */
	it('says so on a vehicle without a currency instead of drawing nothing', async () => {
		vi.mocked(readYear).mockResolvedValue(answer({ currency: null }))

		const wrapper = await screen()

		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(true)
		expect(wrapper.find('table').exists()).toBe(false)
		// CO₂ needs no currency.
		expect(wrapper.find('.costs__co2').text()).toContain('≈ 110 kg')
	})

	it('states the year\'s CO₂ as an estimate and links its source', async () => {
		const wrapper = await screen()
		const co2 = wrapper.find('.costs__co2')

		expect(co2.find('h3').text()).toBe('CO₂ (estimate)')
		expect(co2.text()).toContain('≈ 110 kg')
		expect(co2.text()).toContain('An estimate: what was tanked or charged, times its emission factor')
		expect(co2.find('a').attributes()).toMatchObject({ href: 'https://example.org/fuels', rel: 'noreferrer noopener', target: '_blank' })
	})

	it('says when the year could not be read', async () => {
		vi.mocked(readYear).mockRejectedValue(new Error('nope'))

		const wrapper = await screen()

		expect(wrapper.text()).toContain('The costs could not be read.')
	})

	it('leaves the vehicle\'s screen by its own button', async () => {
		const wrapper = await screen()

		await button(wrapper, 'Back to the vehicle').trigger('click')

		expect(wrapper.emitted('back')).toHaveLength(1)
	})
})
