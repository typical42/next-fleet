/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, readStatistics } from '../services/api.js'
import { usePreferencesStore } from '../store/preferences.js'
import StatisticsView from './StatisticsView.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readStatistics: vi.fn(),
	getPreferences: vi.fn(),
}))

const ZONE = Intl.DateTimeFormat().resolvedOptions().timeZone

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', currency: 'EUR', may: ['view', 'costs'] }
const OTHER = { uuid: 'v-2', updated_at: 1700000000, plate: 'B-XY 456', currency: 'CHF', may: ['view', 'costs'] }

/**
 * @param {object} [sum] - what differs from a priced period with nothing in it
 * @return {import('../services/api.js').CostSum} a period's cost
 */
function sum(sum = {}) {
	return { total: 0, energy: 0, maintenance: 0, expenses: [], incomplete: false, unstated: false, ...sum }
}

const EMPTY = sum({ total: null, energy: null, maintenance: null, expenses: null })

/** 2026's months, cut in the zone the tests run in, as the server would cut them there. */
const MONTHS = Array.from({ length: 12 }, (_, i) => ({ from: new Date(2026, i, 1).getTime() / 1000, to: new Date(2026, i + 1, 1).getTime() / 1000 }))

/**
 * @param {string} currency - the series'
 * @return {import('../services/api.js').CostSeries} a year whose March cost 120
 */
function series(currency = 'EUR') {
	const march = sum({ total: 12000, energy: 9000, maintenance: 3000 })

	return { currency, buckets: MONTHS.map((_, i) => i === 2 ? march : EMPTY), range: march }
}

/**
 * @param {object} [differs] - what differs from one vehicle's priced year
 * @return {import('../services/api.js').Statistics} the range as the route answers it
 */
function answer(differs = {}) {
	return {
		from: MONTHS[0].from,
		to: MONTHS[11].to,
		bucket: 'month',
		buckets: MONTHS,
		vehicles: [{ uuid: 'v-1', costs: true, drivers: true }],
		costs: [series()],
		per_distance: [],
		consumption: [],
		distance: [],
		by_driver: [],
		co2: { grams: 109630, unstated: false, source: 'https://example.org/fuels', year: null, grid: null },
		...differs,
	}
}

/** @type {import('../services/api.js').PerDistance} a year whose March cost €15 per 100 km */
const RATE = {
	currency: 'EUR',
	per: 'km',
	buckets: MONTHS.map((_, i) => i === 2 ? { total: 12000, distance: 800, value: 1500 } : { total: null, distance: null, value: null }),
	range: { total: 12000, distance: 800, value: 1500 },
	vehicles: [{ uuid: 'v-1', range: { total: 12000, distance: 800, value: 1500 } }],
}

/** @type {import('../services/api.js').ConsumptionLine} */
const DIESEL = {
	uuid: 'v-1',
	energy: 'diesel',
	per: 'km',
	amount: 88000,
	distance: 1600,
	value: 5.5,
	segments: [
		{ energy: 'diesel', closes: 'f-1', filled_at: MONTHS[1].from + 86400, amount: 40000, distance: 800, per: 'km', value: 5 },
		{ energy: 'diesel', closes: 'f-2', filled_at: MONTHS[2].from + 86400, amount: 48000, distance: 800, per: 'km', value: 6 },
	],
}

/** @type {import('../services/api.js').DistanceSeries} a year driven in March */
const TRIPS = {
	per: 'km',
	buckets: MONTHS.map((_, i) => i === 2 ? { trips: 3, business: 120, private: 30, commute: 0 } : { trips: 0, business: 0, private: 0, commute: 0 }),
	range: { trips: 3, business: 120, private: 30, commute: 0 },
}

/** @type {import('../services/api.js').DriverSeries} */
const DRIVERS = {
	per: 'km',
	drivers: [
		{ user_id: 'anna', name: 'Anna', trips: 2, distance: 100, business: 100 },
		{ user_id: 'ben', name: 'Ben', trips: 1, distance: 50, business: 20 },
	],
}

const ONLY_OWN = 'Shows only your own trips where you neither own nor manage the vehicle.'

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the table region's accessible name
 * @param {string} head - the row's head
 * @return {string[]} the row's cells; none when the table or the row is not there
 */
function cells(wrapper, label, head) {
	const found = wrapper.find(`[role="region"][aria-label="${label}"]`)
	if (!found.exists()) {
		return []
	}
	const tr = found.findAll('tbody tr').find((one) => one.find('th').text() === head)

	return tr ? tr.findAll('td').map((one) => one.text()) : []
}

/**
 * @param {object} [props] - what differs from opening on the one vehicle
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the screen, past its first read
 */
async function screen(props = {}) {
	const wrapper = mount(StatisticsView, { props: { vehicles: [VEHICLE, OTHER], vehicle: 'v-1', ...props } })
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the picker's label
 * @return {import('@vue/test-utils').VueWrapper} the picker
 */
function picker(wrapper, label) {
	const found = wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
	if (!found) {
		throw new Error(`No picker "${label}"`)
	}

	return found
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the row's head
 * @param {number} [table] - which currency's table, in order
 * @return {string[]} the row's cells
 */
function row(wrapper, label, table = 0) {
	const found = wrapper.findAll('table')[table]?.findAll('tbody tr').find((one) => one.find('th').text() === label)

	return found ? found.findAll('td').map((one) => one.text()) : []
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @param {string} label - the button's accessible name
 * @return {import('@vue/test-utils').DOMWrapper<HTMLButtonElement>|undefined} the button
 */
function button(wrapper, label) {
	return wrapper.findAll('button').find((one) => one.attributes('aria-label') === label || one.text() === label)
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 8, 19, 15, 30))
	vi.mocked(readStatistics).mockResolvedValue(answer())
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the Statistics screen', () => {
	it('reads this calendar year of the vehicle it opened on, in the reader\'s own zone', async () => {
		await screen()

		expect(readStatistics).toHaveBeenCalledWith({ vehicle: 'v-1', from: '2026-01-01', to: '2027-01-01', tz: ZONE, net: false })
	})

	it('reads every vehicle when none is chosen', async () => {
		const wrapper = await screen({ vehicle: '' })
		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ vehicle: '' }))
		expect(picker(wrapper, 'Vehicle').props('modelValue')).toMatchObject({ id: '', label: 'All vehicles' })

		await picker(wrapper, 'Vehicle').vm.$emit('update:modelValue', { id: 'v-2', label: 'B-XY 456' })
		await flushPromises()

		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ vehicle: 'v-2' }))
	})

	it('follows the vehicle the screen is opened on next', async () => {
		const wrapper = await screen()

		await wrapper.setProps({ vehicle: 'v-2' })
		await flushPromises()

		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ vehicle: 'v-2' }))
	})

	it('tabulates each month, and says a month without rows has none', async () => {
		const wrapper = await screen()

		expect(row(wrapper, 'Energy')[2]).toBe('€90.00')
		expect(row(wrapper, 'Total')[2]).toBe('€120.00')
		expect(row(wrapper, 'Total')[0]).toBe('No entries')
		expect(row(wrapper, 'Total')[12]).toBe('€120.00')
	})

	it('states each category\'s share of the range', async () => {
		const wrapper = await screen()

		expect(row(wrapper, 'Energy')[13]).toBe('75%')
		expect(row(wrapper, 'Maintenance')[13]).toBe('25%')
	})

	it('says when a currency\'s sums leave out a price or count gross, and when they are net', async () => {
		const short = { ...series('CHF'), range: sum({ total: 500, energy: 500, incomplete: true, unstated: true }) }
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [series(), short] }))
		vi.mocked(getPreferences).mockResolvedValue(/** @type {any} */ ({
			preferences: { dismissed_hints: [], dismissed_logbook_hints: [], inbox_folder: null, reclaim_vat: true, kpi_period: 'last-12' },
			jurisdictions: [],
		}))
		await usePreferencesStore().load()
		const wrapper = await screen()

		const [eur, chf] = wrapper.findAll('section.statistics__costs').map((one) => one.findAll('.statistics__note').map((note) => note.text()))
		expect(eur).toEqual(['Net of VAT'])
		expect(chf).toEqual(['Net of VAT', 'Incomplete: a fill-up in the period has no price', 'Entries without a VAT rate count gross'])
	})

	it('draws one bar per month with rows, in its bands, out of the accessibility tree', async () => {
		const wrapper = await screen()

		expect(wrapper.findAll('svg rect.statistics__band').map((one) => one.attributes('data-band'))).toEqual(['energy', 'maintenance'])
		expect(wrapper.find('svg.statistics__bars').attributes('aria-hidden')).toBe('true')
	})

	/** Nothing is added up across currencies (docs/api.md#statistics). */
	it('draws one chart and one table per currency', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ vehicles: [{ uuid: 'v-1', costs: true }, { uuid: 'v-2', costs: true }], costs: [series('CHF'), series('EUR')], co2: null }))

		const wrapper = await screen({ vehicle: '' })

		expect(wrapper.findAll('h3').map((one) => one.text())).toEqual(['Costs in CHF', 'Costs in EUR'])
		expect(row(wrapper, 'Total', 0)[2]).toMatch(/^CHF\s120\.00$/)
		expect(row(wrapper, 'Total', 1)[2]).toBe('€120.00')
		expect(wrapper.findAll('svg.statistics__bars')).toHaveLength(2)
	})

	it('draws one vehicle\'s cost per 100 km as a line, every figure in its table', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ per_distance: [RATE] }))

		const wrapper = await screen()

		const section = wrapper.find('section.statistics__rate')
		expect(section.find('h3').text()).toBe('Cost per 100 km in EUR')
		expect(section.find('svg.statistics__line').attributes('aria-hidden')).toBe('true')
		expect(section.findAll('svg .statistics__dot')).toHaveLength(1)
		expect(cells(wrapper, 'Cost per 100 km in EUR, by month', 'Cost per 100 km')[2]).toBe('€15.00')
		expect(cells(wrapper, 'Cost per 100 km in EUR, by month', 'Distance')[12]).toBe('800 km')
		expect(wrapper.find('[aria-label="Cost per 100 km in EUR, by vehicle"]').exists()).toBe(false)
	})

	it('compares every vehicle\'s cost per 100 km beneath the fleet\'s line', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ per_distance: [RATE], co2: null }))

		const wrapper = await screen({ vehicle: '' })

		expect(cells(wrapper, 'Cost per 100 km in EUR, by vehicle', 'B-XY 123')).toEqual(['€120.00', '800 km', '€15.00'])
		expect(cells(wrapper, 'Cost per 100 km in EUR, by vehicle', 'All vehicles')).toEqual(['€120.00', '800 km', '€15.00'])
	})

	it('says when the rate leaves out a price or counts gross, and when it is net', async () => {
		const short = { ...series(), range: sum({ total: 500, energy: 500, incomplete: true, unstated: true }) }
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [short], per_distance: [RATE] }))
		vi.mocked(getPreferences).mockResolvedValue(/** @type {any} */ ({
			preferences: { dismissed_hints: [], dismissed_logbook_hints: [], inbox_folder: null, reclaim_vat: true, kpi_period: 'last-12' },
			jurisdictions: [],
		}))
		await usePreferencesStore().load()
		const wrapper = await screen()

		expect(wrapper.findAll('section.statistics__rate .statistics__note').map((note) => note.text()))
			.toEqual(['Net of VAT', 'Incomplete: a fill-up in the period has no price', 'Entries without a VAT rate count gross'])
	})

	it('says when the rate leaves out a cost without a distance', async () => {
		const NOTE = 'Costs in a period without a distance are not in the cost per distance.'
		vi.mocked(readStatistics).mockResolvedValue(answer({ per_distance: [RATE], co2: null }))
		const even = await screen({ vehicle: '' })
		expect(even.find('section.statistics__rate').text()).not.toContain(NOTE)

		vi.mocked(readStatistics).mockResolvedValue(answer({ per_distance: [{ ...RATE, range: { total: 15000, distance: 800, value: 1500 } }], co2: null }))
		const bent = await screen({ vehicle: '' })
		expect(bent.find('section.statistics__rate .statistics__note').text()).toBe(NOTE)
	})

	it('draws one vehicle\'s consumption fill-up by fill-up, each segment in its table', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ consumption: [DIESEL] }))

		const wrapper = await screen()

		const section = wrapper.find('section.statistics__consumption')
		expect(section.find('h3').text()).toBe('Consumption')
		expect(section.find('h4').text()).toBe('Diesel consumption')
		expect(section.findAll('svg.statistics__line .statistics__dot')).toHaveLength(2)
		expect(section.findAll('svg.statistics__line title').map((one) => one.text())).toEqual(['Feb 2, 2026: 5.0 l/100 km', 'Mar 2, 2026: 6.0 l/100 km'])
		const rows = wrapper.findAll('[role="region"][aria-label="Diesel consumption"] tbody tr')
		expect(rows.map((one) => one.find('th').text())).toEqual([expect.stringMatching(/Feb/), expect.stringMatching(/Mar/)])
		expect(rows[1].findAll('td').map((one) => one.text())).toEqual(['800 km', '48 l', '6.0 l/100 km'])
	})

	it('lists every vehicle\'s average consumption and draws no line', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ consumption: [{ ...DIESEL, segments: null }], co2: null }))

		const wrapper = await screen({ vehicle: '' })

		expect(cells(wrapper, 'Consumption', 'B-XY 123')).toEqual(['Diesel', '1,600 km', '5.5 l/100 km'])
		expect(wrapper.find('section.statistics__consumption svg').exists()).toBe(false)
	})

	it('draws the distance by trip category as bars, every figure in its table', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ distance: [TRIPS] }))

		const wrapper = await screen()

		const section = wrapper.find('section.statistics__trips')
		expect(section.find('h3').text()).toBe('Distance by trip category')
		expect(section.findAll('svg rect.statistics__band').map((one) => one.attributes('data-band'))).toEqual(['business', 'private'])
		expect(section.find('svg.statistics__bars').attributes('aria-hidden')).toBe('true')
		expect(cells(wrapper, 'Distance by trip category, by month', 'Business')).toEqual([...Array(2).fill('–'), '120 km', ...Array(9).fill('–'), '120 km', '80%'])
		expect(cells(wrapper, 'Distance by trip category, by month', 'Total')[12]).toBe('150 km')
		expect(section.find('.statistics__note').text()).toBe('Only the trips in the logbook count, so this can differ from the distance on the counter.')
	})

	it('says the trips\' hours can differ from the hour counter', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ distance: [{ ...TRIPS, per: 'h' }] }))

		const wrapper = await screen()

		expect(wrapper.find('section.statistics__trips .statistics__note').text()).toBe('Only the trips in the logbook count, so this can differ from the hours on the counter.')
	})

	it('lists each driver\'s trips, distance and business share', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ distance: [TRIPS], by_driver: [DRIVERS] }))

		const wrapper = await screen()

		expect(wrapper.find('section.statistics__drivers h3').text()).toBe('Distance by driver')
		expect(cells(wrapper, 'Distance by driver', 'Anna')).toEqual(['2', '100 km', '100%'])
		expect(cells(wrapper, 'Distance by driver', 'Ben')).toEqual(['1', '50 km', '40%'])
		expect(wrapper.text()).not.toContain(ONLY_OWN)
	})

	it('says it shows only the reader\'s own trips where they may not compare the drivers', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ vehicles: [{ uuid: 'v-1', costs: true, drivers: false }], distance: [TRIPS], by_driver: [{ ...DRIVERS, drivers: [DRIVERS.drivers[1]] }] }))

		const wrapper = await screen()

		expect(wrapper.find('section.statistics__drivers').text()).toContain(ONLY_OWN)
	})

	it('steps to the year before, and not past this one', async () => {
		const wrapper = await screen()
		expect(button(wrapper, 'Next year')?.attributes('disabled')).toBeDefined()

		await button(wrapper, 'Previous year')?.trigger('click')
		await flushPromises()

		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2025-01-01', to: '2026-01-01' }))
		expect(wrapper.text()).toContain('2025')
	})

	it('reads the last 12 months, all of it, or two days the reader picks', async () => {
		const wrapper = await screen()

		await picker(wrapper, 'Range').vm.$emit('update:modelValue', { id: 'last-12', label: 'Last 12 months' })
		await flushPromises()
		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2025-10-01', to: '2026-10-01' }))
		expect(button(wrapper, 'Previous year')).toBeUndefined()

		await picker(wrapper, 'Range').vm.$emit('update:modelValue', { id: 'all', label: 'All time' })
		await flushPromises()
		expect(readStatistics).toHaveBeenLastCalledWith({ vehicle: 'v-1', tz: ZONE, net: false })

		await picker(wrapper, 'Range').vm.$emit('update:modelValue', { id: 'between', label: 'Between two days' })
		const [first, last] = wrapper.findAllComponents(NcDateTimePickerNative)
		await first.vm.$emit('update:modelValue', new Date(2026, 1, 3))
		await last.vm.$emit('update:modelValue', new Date(2026, 1, 28))
		await flushPromises()
		expect(readStatistics).toHaveBeenLastCalledWith(expect.objectContaining({ from: '2026-02-03', to: '2026-03-01' }))
	})

	it('asks for both days, and for a last one not before the first, instead of reading', async () => {
		const wrapper = await screen()
		await picker(wrapper, 'Range').vm.$emit('update:modelValue', { id: 'between', label: 'Between two days' })
		await flushPromises()
		expect(wrapper.text()).toContain('Pick the first and the last day.')
		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(false)

		const [first, last] = wrapper.findAllComponents(NcDateTimePickerNative)
		await first.vm.$emit('update:modelValue', new Date(2026, 1, 28))
		await last.vm.$emit('update:modelValue', new Date(2026, 1, 3))
		await flushPromises()
		expect(wrapper.text()).toContain('The last day is before the first.')
		expect(readStatistics).toHaveBeenCalledTimes(1)
	})

	/** A spreadsheet's year is the one on screen, one file per table (docs/architecture.md#csv-export). */
	it('offers one vehicle\'s calendar year as four CSV files', async () => {
		const wrapper = await screen()
		await button(wrapper, 'Previous year')?.trigger('click')
		await flushPromises()

		/** @type {string[]} */
		const saved = []
		const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(/** @this {HTMLAnchorElement} */ function() {
			saved.push(this.download === '' ? this.getAttribute('href') ?? '' : 'not a download')
		})

		await button(wrapper, 'Export')?.trigger('click')
		// The menu's popover renders after more than one tick on a busy runner.
		const items = await vi.waitFor(() => {
			const shown = [...document.body.querySelectorAll('[role="menuitem"]')]
			expect(shown.map((one) => one.textContent?.trim())).toEqual(['Trips', 'Fill-ups', 'Maintenance', 'Expenses'])
			return shown
		})
		for (const item of items) {
			item.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		}

		// The address itself is csvUrl()'s (api.spec.js); jsdom has no webroot to put before it.
		expect(saved.map((href) => href.replace(/^.*\/apps\//, '/apps/'))).toEqual(['trips', 'energy', 'maintenance', 'expenses']
			.map((table) => `/apps/nextfleet/vehicles/v-1/csv/2025/${table}`))
		click.mockRestore()
		wrapper.unmount()
	})

	it('offers no export for every vehicle, nor for a range that is not a calendar year', async () => {
		const all = await screen({ vehicle: '' })
		expect(button(all, 'Export')).toBeUndefined()

		const one = await screen()
		await picker(one, 'Range').vm.$emit('update:modelValue', { id: 'last-12', label: 'Last 12 months' })
		await flushPromises()
		expect(button(one, 'Export')).toBeUndefined()
	})

	/** Nothing is summed across currencies, and a vehicle without one has nothing to sum in. */
	it('says so on a vehicle without a currency instead of drawing nothing', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [] }))

		const wrapper = await screen({ vehicles: [{ ...VEHICLE, currency: null }] })

		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(true)
		expect(wrapper.find('table').exists()).toBe(false)
		// CO₂ needs no currency.
		expect(wrapper.find('.statistics__co2').text()).toContain('≈ 110 kg')
	})

	it('says so when the range has no figure for the reader, instead of empty charts', async () => {
		const blank = { currency: 'EUR', buckets: MONTHS.map(() => EMPTY), range: EMPTY }
		const still = { ...RATE, buckets: MONTHS.map(() => ({ total: null, distance: null, value: null })), range: { total: null, distance: null, value: null } }
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [blank], per_distance: [still], co2: { ...answer().co2, grams: null } }))

		const wrapper = await screen()

		expect(wrapper.findComponent(NcEmptyContent).props('name')).toBe('Nothing to show in this range')
		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.find('.statistics__co2').exists()).toBe(false)
	})

	/** Its records add no cost series, so a blank answer does not mean none were made. */
	it('asks for a currency, not another range, on a vehicle without one', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [], co2: { ...answer().co2, grams: null } }))

		const wrapper = await screen({ vehicles: [{ ...VEHICLE, currency: null }] })

		expect(wrapper.findAllComponents(NcEmptyContent).map((one) => one.props('name'))).toEqual(['No currency'])
	})

	it('draws the range when only the counter moved in it', async () => {
		const blank = { currency: 'EUR', buckets: MONTHS.map(() => EMPTY), range: EMPTY }
		const moved = { ...RATE, buckets: MONTHS.map(() => ({ total: null, distance: null, value: null })), range: { total: null, distance: 800, value: null } }
		vi.mocked(readStatistics).mockResolvedValue(answer({ costs: [blank], per_distance: [moved], co2: { ...answer().co2, grams: null } }))

		const wrapper = await screen()

		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(false)
		expect(cells(wrapper, 'Cost per 100 km in EUR, by month', 'Distance')[12]).toBe('800 km')
	})

	it('says the owner keeps the costs, on a vehicle whose costs the reader may not see', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ vehicles: [{ uuid: 'v-1', costs: false }], costs: [] }))

		const wrapper = await screen({ vehicles: [{ ...VEHICLE, may: ['view'] }] })

		expect(wrapper.text()).toContain('The owner keeps the costs of this vehicle from you.')
		expect(wrapper.find('table').exists()).toBe(false)
	})

	it('says which costs it leaves out when it reads every vehicle', async () => {
		vi.mocked(readStatistics).mockResolvedValue(answer({ vehicles: [{ uuid: 'v-1', costs: true }, { uuid: 'v-2', costs: false }], co2: null }))

		const wrapper = await screen({ vehicle: '' })

		expect(wrapper.text()).toContain('Leaves out the costs of vehicles whose owner keeps them from you.')
		expect(wrapper.find('table').exists()).toBe(true)
	})

	it('states one vehicle\'s CO₂ as an estimate and links its source, and none for every vehicle', async () => {
		const wrapper = await screen()
		const co2 = wrapper.find('.statistics__co2')

		expect(co2.find('h3').text()).toBe('CO₂ (estimate)')
		expect(co2.text()).toContain('≈ 110 kg')
		expect(co2.find('a').attributes()).toMatchObject({ href: 'https://example.org/fuels', rel: 'noreferrer noopener', target: '_blank' })

		vi.mocked(readStatistics).mockResolvedValue(answer({ co2: null }))
		const all = await screen({ vehicle: '' })
		expect(all.find('.statistics__co2').exists()).toBe(false)
	})

	it('turns a spinner while the range is read', async () => {
		/** @type {(read: import('../services/api.js').Statistics) => void} */
		let answered = () => {}
		vi.mocked(readStatistics).mockReturnValue(new Promise((resolve) => {
			answered = resolve
		}))

		const wrapper = await screen()
		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(true)

		answered(answer())
		await flushPromises()
		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(false)
		expect(wrapper.find('section.statistics__costs').exists()).toBe(true)
	})

	it('says when the range could not be read', async () => {
		vi.mocked(readStatistics).mockRejectedValue(new Error('nope'))

		const wrapper = await screen()

		expect(wrapper.text()).toContain('The statistics could not be read.')
	})
})
