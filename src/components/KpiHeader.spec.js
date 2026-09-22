/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { getPreferences, readKpis, savePreferences } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { usePreferencesStore } from '../store/preferences.js'
import KpiHeader from './KpiHeader.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readKpis: vi.fn(),
	getPreferences: vi.fn(),
	savePreferences: vi.fn(),
}))

/**
 * @param {Partial<import('../services/api.js').Settings['preferences']>} chosen - the choices that differ from the defaults
 * @return {import('../services/api.js').Settings} what the preferences route answers
 */
function settings(chosen) {
	return { preferences: { jurisdiction: 'de', dismissed_hints: [], reclaim_vat: false, kpi_period: 'last-12', ...chosen }, jurisdictions: [] }
}

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, odo_value: 48210, odo_unit: 'km', second_unit: null, currency: 'EUR', energy_types: ['diesel'] }
/** @type {(year: number, month: number, day: number) => number} a local midnight, in seconds */
const at = (year, month, day) => new Date(year, month - 1, day).getTime() / 1000

/**
 * @param {number} value - cents per 100 km
 * @return {import('../services/api.js').Kpis} an answer with a cost and nothing else
 */
function answer(value) {
	return {
		consumption: [],
		wall_side: null,
		cost: { currency: 'EUR', net: false, per: 'km', distance: 1000, total: value * 10, energy: 0, value, energy_value: 0, tco: null, incomplete: false, unstated: false },
		hours: null,
	}
}

/**
 * @param {object} [vehicle] - the vehicle the header is on
 * @return {import('@vue/test-utils').VueWrapper} the header
 */
function header(vehicle = VEHICLE) {
	return mount(KpiHeader, { props: { vehicle } })
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the header
 * @return {string[][]} each tile's label and figure
 */
function tiles(wrapper) {
	return wrapper.findAll('.tile').map((one) => [one.find('dt').text(), one.find('.tile__figure').text()])
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 8, 19, 15, 30))
	// The period asked first, then the one before it.
	vi.mocked(readKpis).mockImplementation(async (uuid, { from }) => answer(from === at(2025, 9, 20) ? 2000 : 1900))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the vehicle header', () => {
	it('reads the last twelve months and the twelve before, and compares the two', async () => {
		const wrapper = header()
		await flushPromises()

		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2025, 9, 20), to: at(2026, 9, 20), net: false })
		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2024, 9, 20), to: at(2025, 9, 20), net: false })
		expect(tiles(wrapper)).toContainEqual(['Cost', '€20.00/100 km'])
		expect(wrapper.text()).toContain('+€1.00/100 km vs. the period before')
	})

	it('shows the odometer while the figures are on their way', () => {
		vi.mocked(readKpis).mockReturnValue(new Promise(() => {}))

		expect(tiles(header())).toEqual([['Odometer', '48,210 km']])
	})

	it('reads again for another period', async () => {
		const wrapper = header()
		await flushPromises()
		vi.mocked(readKpis).mockClear()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'last-year', label: 'Last year' })
		await flushPromises()

		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2025, 1, 1), to: at(2026, 1, 1), net: false })
		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2024, 1, 1), to: at(2025, 1, 1), net: false })
	})

	it('asks which month for the one-month period, this one first', async () => {
		const wrapper = header()
		await flushPromises()
		expect(wrapper.findComponent(NcDateTimePickerNative).exists()).toBe(false)

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'month', label: 'One month' })
		await flushPromises()
		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2026, 9, 1), to: at(2026, 10, 1), net: false })

		vi.mocked(readKpis).mockClear()
		await wrapper.findComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 1, 1))
		await flushPromises()

		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2026, 2, 1), to: at(2026, 3, 1), net: false })
		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2026, 1, 1), to: at(2026, 2, 1), net: false })
	})

	it('keeps the month when the month field is cleared', async () => {
		const wrapper = header()
		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'month', label: 'One month' })
		await flushPromises()
		vi.mocked(readKpis).mockClear()

		await wrapper.findComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', null)
		await flushPromises()

		expect(readKpis).not.toHaveBeenCalled()
	})

	it('reads again after a write, an undo, an edit of the vehicle, or on another vehicle', async () => {
		const wrapper = header()
		await flushPromises()
		vi.mocked(readKpis).mockClear()

		await /** @type {any} */ (wrapper.vm).reload()
		expect(readKpis).toHaveBeenCalledTimes(2)

		useVehiclesStore().restored += 1
		await flushPromises()
		expect(readKpis).toHaveBeenCalledTimes(4)

		await wrapper.setProps({ vehicle: { ...VEHICLE, updated_at: 1700000001, currency: 'CHF' } })
		await flushPromises()
		expect(readKpis).toHaveBeenCalledTimes(6)

		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()
		expect(readKpis).toHaveBeenLastCalledWith('v-2', expect.anything())
	})

	it('drops an answer to a period nobody is asking about any more', async () => {
		/** @type {((kpis: any) => void)[]} */
		const pending = []
		vi.mocked(readKpis).mockImplementation(() => new Promise((resolve) => pending.push(resolve)))
		const wrapper = header()
		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'last-year', label: 'Last year' })

		pending[2](answer(500))
		pending[3](answer(500))
		pending[0](answer(9900))
		pending[1](answer(9900))
		await flushPromises()

		expect(tiles(wrapper)).toContainEqual(['Cost', '€5.00/100 km'])
	})

	it('opens on the stored period, net when this user reclaims VAT', async () => {
		vi.mocked(getPreferences).mockResolvedValue(settings({ reclaim_vat: true, kpi_period: 'last-year' }))
		await usePreferencesStore().load()

		const wrapper = header()
		await flushPromises()

		expect(readKpis).toHaveBeenCalledTimes(2)
		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2025, 1, 1), to: at(2026, 1, 1), net: true })
		expect(/** @type {any} */ (wrapper.findComponent(NcSelect)).props('modelValue')).toEqual({ id: 'last-year', label: 'Last year' })
	})

	/** The preferences may arrive after the header has read; what they say wins. */
	it('reads again when the preferences arrive late', async () => {
		header()
		await flushPromises()
		vi.mocked(readKpis).mockClear()

		vi.mocked(getPreferences).mockResolvedValue(settings({ reclaim_vat: true }))
		await usePreferencesStore().load()
		await flushPromises()

		expect(readKpis).toHaveBeenCalledWith('v-1', { from: at(2025, 9, 20), to: at(2026, 9, 20), net: true })
	})

	it('keeps a picked period for the next session', async () => {
		vi.mocked(savePreferences).mockResolvedValue(settings({ kpi_period: 'this-year' }))
		const wrapper = header()
		await flushPromises()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'this-year', label: 'This year' })
		await flushPromises()

		expect(savePreferences).toHaveBeenCalledWith({ kpi_period: 'this-year' })
	})

	it('says so when the figures cannot be read, and keeps the odometer', async () => {
		vi.mocked(readKpis).mockRejectedValue(new Error('refused'))

		const wrapper = header()
		await flushPromises()

		expect(tiles(wrapper)).toEqual([['Odometer', '48,210 km']])
		expect(wrapper.text()).toContain('The figures could not be read.')
	})
})
