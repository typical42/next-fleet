/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
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

const GERMAN = { uuid: 'v-de', updated_at: 1, plate: 'B-XY 123', jurisdiction: 'de', lifecycle: 'active' }
const SOLD = { uuid: 'v-sold', updated_at: 1, plate: 'M-AB 1', jurisdiction: 'de', lifecycle: 'disposed' }
const ELSEWHERE = { uuid: 'v-gen', updated_at: 1, plate: 'XX 42', jurisdiction: 'generic', lifecycle: 'active' }

const settings = {
	preferences: { jurisdiction: 'de', dismissed_hints: [], reclaim_vat: false, kpi_period: 'last-12' },
	jurisdictions: [
		{ key: 'de', name: 'Germany', logbook_export: true },
		{ key: 'generic', name: 'Generic', logbook_export: false },
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
	return wrapper.findComponent(NcButton)
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
		expect(field(wrapper).props('error')).toBe(true)
	})
})
