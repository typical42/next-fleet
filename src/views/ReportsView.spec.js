/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences } from '../services/api.js'
import ReportsView from './ReportsView.vue'

// The network is mocked; the address the export lives at is the real one, because which page opens
// is the point of the screen.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	getPreferences: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (/** @type {string} */ path) => `/index.php${path}`,
}))

const GERMAN = { uuid: 'v-de', updated_at: 1, plate: 'B-XY 123', jurisdiction: 'de', lifecycle: 'active', odo_unit: 'km' }
const SOLD = { uuid: 'v-sold', updated_at: 1, plate: 'M-AB 1', jurisdiction: 'de', lifecycle: 'disposed', odo_unit: 'km' }
const HOURS = { uuid: 'v-hours', updated_at: 1, plate: 'GEN 1', jurisdiction: 'de', lifecycle: 'active', odo_unit: 'h' }
const ELSEWHERE = { uuid: 'v-gen', updated_at: 1, plate: 'XX 42', jurisdiction: 'generic', lifecycle: 'active', odo_unit: 'km' }

const settings = {
	preferences: { jurisdiction: 'de', dismissed_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null },
	jurisdictions: [
		{ key: 'de', name: 'Germany', logbook_export: true, mileage_claim: true, grid_factor: null },
		{ key: 'generic', name: 'Generic', logbook_export: false, mileage_claim: false, grid_factor: null },
	],
}

/**
 * The screen, mounted and past its first read.
 *
 * @param {import('../services/api.js').Vehicle[]} vehicles - the fleet it is given
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper
 */
async function screen(vehicles = [GERMAN, SOLD, ELSEWHERE]) {
	const wrapper = shallowMount(ReportsView, { props: { vehicles } })
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the vehicle dropdown
 */
function dropdown(wrapper) {
	return wrapper.findComponent(NcSelect)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the year field
 */
function field(wrapper) {
	return wrapper.findComponent(NcTextField)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the button that opens the logbook
 */
function link(wrapper) {
	return wrapper.findComponent('.reports__open-logbook')
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the button that opens the mileage claim
 */
function claim(wrapper) {
	return wrapper.findComponent('.reports__open-claim')
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(getPreferences).mockResolvedValue(settings)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('reports screen', () => {
	/**
	 * A country without a renderer answers the export with a 404, so its vehicles are not offered.
	 * A sold vehicle is: its logbook is kept for years after it left the fleet
	 * (docs/features.md#logbook-mode).
	 */
	it('offers the vehicles whose country prints a logbook, sold ones included', async () => {
		const wrapper = await screen()

		expect(dropdown(wrapper).props('options').map((/** @type {{ id: string }} */ one) => one.id))
			.toEqual(['v-de', 'v-sold'])
	})

	/**
	 * Prefilled, like every other choice in the app (docs/ui.md): the first vehicle on offer and
	 * the year it is now. The export is a page, so the button is a link to it in a tab of its own,
	 * which leaves the app where it was while the page is printed.
	 */
	it('links to the logbook of the first vehicle for this year', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-09-17T10:00:00Z'))

		const wrapper = await screen()

		expect(dropdown(wrapper).props('modelValue').id).toBe('v-de')
		expect(field(wrapper).props('modelValue')).toBe('2026')
		expect(link(wrapper).props('href')).toBe('/index.php/apps/nextfleet/vehicles/v-de/logbook/2026')
		expect(link(wrapper).props('target')).toBe('_blank')
	})

	it('links to the vehicle and the year chosen', async () => {
		const wrapper = await screen()

		await dropdown(wrapper).vm.$emit('update:modelValue', { id: 'v-sold', label: 'M-AB 1' })
		await field(wrapper).vm.$emit('update:modelValue', ' 2019 ')

		expect(link(wrapper).props('href')).toBe('/index.php/apps/nextfleet/vehicles/v-sold/logbook/2019')
	})

	/** The route takes four digits as typed, so a leading zero is not read away into three. */
	it('links to a year with a leading zero as it was typed', async () => {
		const wrapper = await screen()

		await field(wrapper).vm.$emit('update:modelValue', '0999')

		expect(link(wrapper).props('href')).toBe('/index.php/apps/nextfleet/vehicles/v-de/logbook/0999')
	})

	/** A dropdown with nothing in it teaches nothing; the empty state says where the export comes from. */
	it('says so when no vehicle is kept under a country that prints a logbook', async () => {
		const wrapper = await screen([ELSEWHERE])

		expect(dropdown(wrapper).exists()).toBe(false)
		expect(wrapper.findComponent(NcEmptyContent).props('name')).toBe('No logbook to print')
	})

	/**
	 * Not knowing which countries print is not knowing that none do: the screen says the read failed
	 * rather than showing the empty state.
	 */
	it('says so when it cannot learn which countries print a logbook', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await screen()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(false)
		expect(link(wrapper).exists()).toBe(false)
	})

	/** The route answers anything but four digits with a 400, so the screen does not offer it. */
	it.each(['', '19', '2026abc', '20.26'])('offers no page for the year %j', async (year) => {
		const wrapper = await screen()

		await field(wrapper).vm.$emit('update:modelValue', year)

		expect(link(wrapper).props('disabled')).toBe(true)
		expect(claim(wrapper).props('disabled')).toBe(true)
		expect(field(wrapper).props('error')).toBe(true)
	})

	/** Same vehicle, same year, the other page: business trips at the country's rate. */
	it('links to the mileage claim of the vehicle and year chosen', async () => {
		const wrapper = await screen()

		await field(wrapper).vm.$emit('update:modelValue', '2024')

		expect(claim(wrapper).props('href')).toBe('/index.php/apps/nextfleet/vehicles/v-de/mileage/2024')
		expect(claim(wrapper).props('target')).toBe('_blank')
	})

	/**
	 * The claim route answers 404 where the country states no rate, and for a vehicle counting
	 * hours, which no kilometre rate values; neither is offered one.
	 */
	it('offers no mileage claim where the route has none', async () => {
		vi.mocked(getPreferences).mockResolvedValue({
			...settings,
			jurisdictions: [{ ...settings.jurisdictions[0], mileage_claim: false }, settings.jurisdictions[1]],
		})
		const noRate = await screen()
		expect(link(noRate).exists()).toBe(true)
		expect(claim(noRate).exists()).toBe(false)

		vi.mocked(getPreferences).mockResolvedValue(settings)
		const hours = await screen([HOURS])
		expect(link(hours).exists()).toBe(true)
		expect(claim(hours).exists()).toBe(false)
	})

	/** A country could print a claim and no logbook; its vehicles are offered all the same. */
	it('offers a vehicle whose country prints only a claim', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-09-17T10:00:00Z'))
		vi.mocked(getPreferences).mockResolvedValue({
			...settings,
			jurisdictions: [{ ...settings.jurisdictions[0], logbook_export: false }, settings.jurisdictions[1]],
		})

		const wrapper = await screen([GERMAN])

		expect(link(wrapper).exists()).toBe(false)
		expect(claim(wrapper).props('href')).toBe('/index.php/apps/nextfleet/vehicles/v-de/mileage/2026')
	})
})
