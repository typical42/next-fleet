/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { createReminder } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import InspectionSticker from './InspectionSticker.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	createReminder: vi.fn(),
}))

/** @type {any} */
const HU_AU = { key: 'hu_au', mode: 'date', recur_months: 24, recur_odo: null, lead_odo: null, first_due_months: 36 }

/**
 * @param {string|null} firstReg - the vehicle's first registration
 * @return {import('@vue/test-utils').VueWrapper} the question
 */
function sticker(firstReg) {
	return shallowMount(InspectionSticker, {
		props: { vehicle: { uuid: 'v-1', first_reg: firstReg }, template: HU_AU },
		global: { renderStubDefaultSlot: true },
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the question
 * @return {any} the month select
 */
function month(wrapper) {
	return wrapper.findComponent(NcSelect)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the question
 * @return {any} the year field
 */
function year(wrapper) {
	return wrapper.findComponent(NcTextField)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the question
 * @return {Promise<void>} when the save has been answered
 */
async function save(wrapper) {
	await /** @type {any} */ (wrapper.findComponent(NcButton)).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 8, 22, 12))
	vi.mocked(createReminder).mockImplementation(async (uuid, fields) => /** @type {any} */ ({ uuid: 'r-hu', ...fields }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the HU/AU sticker question', () => {
	it('asks for the month and year on the sticker', () => {
		const wrapper = sticker(null)

		expect(wrapper.text()).toContain('When is the next HU/AU?')
		expect(month(wrapper).props('modelValue')).toBeNull()
		expect(year(wrapper).props('modelValue')).toBe('')
	})

	/** The 36-month rule: a car registered in March 2024 is first inspected by March 2027. */
	it('prefills a young vehicle from its first inspection', () => {
		const wrapper = sticker('2024-03-15')

		expect(month(wrapper).props('modelValue').id).toBe(3)
		expect(year(wrapper).props('modelValue')).toBe('2027')
	})

	it('leaves an older vehicle to the sticker', () => {
		const wrapper = sticker('2020-03-15')

		expect(month(wrapper).props('modelValue')).toBeNull()
	})

	it('writes an HU/AU due by the last day of that month, and says so', async () => {
		const wrapper = sticker(null)
		await month(wrapper).vm.$emit('update:modelValue', month(wrapper).props('options')[1])
		await year(wrapper).vm.$emit('update:modelValue', '2028')

		await save(wrapper)

		expect(createReminder).toHaveBeenCalledWith('v-1', { template_key: 'hu_au', due_date: '2028-02-29' })
		expect(wrapper.emitted('saved')).toHaveLength(1)
		expect(useVehiclesStore().reminded).toBe(1)
	})

	/** The host hides the question only once it has read the list back; a second click would add a second HU/AU. */
	it('takes no second answer while the first is being shown', async () => {
		const wrapper = sticker('2024-03-15')

		await save(wrapper)

		expect(wrapper.findComponent(NcButton).props('disabled')).toBe(true)
	})

	it('writes nothing without a month and a year', async () => {
		const wrapper = sticker(null)
		await year(wrapper).vm.$emit('update:modelValue', '27')

		await save(wrapper)

		expect(createReminder).not.toHaveBeenCalled()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('Pick the month and year on the sticker.')
	})

	it('keeps the answer when the write fails', async () => {
		vi.mocked(createReminder).mockRejectedValueOnce(new Error('The server answered 500'))
		const wrapper = sticker('2024-03-15')

		await save(wrapper)

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(year(wrapper).props('modelValue')).toBe('2027')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})
