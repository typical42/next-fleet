/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, createBudget, deleteBudget, listBudgets, NotFoundError, RefusedError, updateBudget } from '../services/api.js'
import VehicleBudgets from './VehicleBudgets.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	createBudget: vi.fn(),
	deleteBudget: vi.fn(),
	listBudgets: vi.fn(),
	updateBudget: vi.fn(),
}))
vi.mock('@nextcloud/l10n', async (original) => ({
	...await original(),
	getCanonicalLocale: () => 'en-GB',
}))

const OWNED = { uuid: 'v-1', currency: 'EUR', may: ['view', 'log', 'edit', 'delete', 'own', 'costs'] }
const MANAGED = { ...OWNED, may: ['view', 'log', 'edit', 'delete', 'costs'] }
const THIS_YEAR = { uuid: 'b-1', year: 2031, amount: 500000, net: true, created_at: 1, updated_at: 10, deleted_at: null, created_by: 'owner' }
const NEXT_YEAR = { uuid: 'b-2', year: 2032, amount: 600000, net: false, created_at: 2, updated_at: 20, deleted_at: null, created_by: 'owner' }

/**
 * @param {object} vehicle - the vehicle as the sheet read it
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the list, past its first read
 */
async function budgets(vehicle = OWNED) {
	const wrapper = shallowMount(VehicleBudgets, { props: { vehicle }, global: { renderStubDefaultSlot: true } })
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted list
 * @param {string} label - what the button says
 * @param {number} [at] - which of the buttons with that label
 * @return {any} the button
 */
function button(wrapper, label, at = 0) {
	return wrapper.findAllComponents(NcButton).filter((/** @type {any} */ one) => one.text() === label).at(at)
}

/**
 * @param {any} within - the wrapper or a part of it
 * @param {string} label - the field's label
 * @return {any} the field
 */
function field(within, label) {
	return within.findAllComponents(NcTextField).find((/** @type {any} */ one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted list
 * @return {string} the message on screen, or nothing
 */
function message(wrapper) {
	const cards = wrapper.findAllComponents(NcNoteCard).filter((/** @type {any} */ one) => one.props('type') === 'error')
	return cards.length > 0 ? String(cards[0].props('text')) : ''
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted list
 * @param {{year?: string, amount?: string, net?: boolean}} typed - what the person enters
 */
async function fill(wrapper, typed) {
	const add = wrapper.find('.budgets__add')
	if (typed.year !== undefined) {
		await field(add, 'Year').vm.$emit('update:modelValue', typed.year)
	}
	if (typed.amount !== undefined) {
		await field(add, 'Amount').vm.$emit('update:modelValue', typed.amount)
	}
	if (typed.net !== undefined) {
		await add.findComponent(NcCheckboxRadioSwitch).vm.$emit('update:modelValue', typed.net)
	}
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date('2031-06-15T12:00:00Z'))
	vi.mocked(listBudgets).mockResolvedValue([THIS_YEAR, NEXT_YEAR])
})

describe('VehicleBudgets', () => {
	it('shows each year with its amount, net or gross', async () => {
		const wrapper = await budgets()

		expect(listBudgets).toHaveBeenCalledWith('v-1')
		expect(wrapper.findAll('.budgets__year').map((one) => one.text())).toEqual(['2031', '2032'])
		expect(wrapper.findAll('.budgets__amount').map((one) => one.text())).toEqual(['€5,000.00 net', '€6,000.00 gross'])
	})

	it('adds a budget for a year, the amount in cents', async () => {
		vi.mocked(listBudgets).mockResolvedValue([])
		const made = { ...THIS_YEAR, year: 2033, amount: 450050, net: false }
		vi.mocked(createBudget).mockResolvedValue(made)
		const wrapper = await budgets()

		await fill(wrapper, { year: '2033', amount: '4500,50', net: false })
		await button(wrapper, 'Add budget').vm.$emit('click')
		await flushPromises()

		expect(createBudget).toHaveBeenCalledWith('v-1', { year: 2033, amount: 450050, net: false })
		expect(wrapper.findAll('.budgets__year').map((one) => one.text())).toEqual(['2033'])
		expect(field(wrapper.find('.budgets__add'), 'Amount').props('modelValue')).toBe('')
	})

	it('starts on the current year', async () => {
		vi.mocked(listBudgets).mockResolvedValue([])
		const wrapper = await budgets()

		expect(field(wrapper.find('.budgets__add'), 'Year').props('modelValue')).toBe('2031')
	})

	it('says why it takes no amount it cannot read', async () => {
		const wrapper = await budgets()

		await fill(wrapper, { amount: 'a lot' })
		await button(wrapper, 'Add budget').vm.$emit('click')
		await flushPromises()

		expect(createBudget).not.toHaveBeenCalled()
		expect(message(wrapper)).toBe('That is not an amount.')
	})

	/** Another session may have set it: the list is read again, so the one to change is on screen. */
	it('words a year that has a budget already, and shows it', async () => {
		vi.mocked(createBudget).mockRejectedValue(new RefusedError('the vehicle has a budget for 2031', 'year_taken'))
		const wrapper = await budgets()

		await fill(wrapper, { amount: '100' })
		await button(wrapper, 'Add budget').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('This year has a budget already. Change that one.')
		expect(listBudgets).toHaveBeenCalledTimes(2)
	})

	it('changes the amount and net or gross with the token it read', async () => {
		const changed = { ...THIS_YEAR, amount: 550000, net: false, updated_at: 11 }
		vi.mocked(updateBudget).mockResolvedValue(changed)
		const wrapper = await budgets()

		await button(wrapper, 'Change').vm.$emit('click')
		const row = wrapper.find('.budgets__edit')
		await field(row, 'Amount').vm.$emit('update:modelValue', '5500')
		await row.findComponent(NcCheckboxRadioSwitch).vm.$emit('update:modelValue', false)
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(updateBudget).toHaveBeenCalledWith('v-1', THIS_YEAR, { amount: 550000, net: false })
		expect(wrapper.findAll('.budgets__amount').map((one) => one.text())).toEqual(['€5,500.00 gross', '€6,000.00 gross'])
	})

	it('reads the list again when the budget changed somewhere else', async () => {
		vi.mocked(updateBudget).mockRejectedValue(new ConflictError())
		const wrapper = await budgets()

		await button(wrapper, 'Change').vm.$emit('click')
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(listBudgets).toHaveBeenCalledTimes(2)
		expect(message(wrapper)).toBe('This budget was changed somewhere else. The list now shows it as it is.')
	})

	it('deletes a budget', async () => {
		vi.mocked(deleteBudget).mockResolvedValue({ ...THIS_YEAR, deleted_at: 12 })
		const wrapper = await budgets()

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(deleteBudget).toHaveBeenCalledWith('v-1', THIS_YEAR)
		expect(wrapper.findAll('.budgets__year').map((one) => one.text())).toEqual(['2032'])
	})

	it('reads the list again when the budget is gone since it was read', async () => {
		vi.mocked(deleteBudget).mockRejectedValue(new NotFoundError('No such vehicle or budget'))
		const wrapper = await budgets()
		vi.mocked(listBudgets).mockResolvedValue([NEXT_YEAR])

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('This budget no longer exists. The list now shows what is left.')
		expect(wrapper.findAll('.budgets__year').map((one) => one.text())).toEqual(['2032'])
	})

	/** As CostCentreList: the name says which year, and holds what the button shows. */
	it('names the year in each row button', async () => {
		const wrapper = await budgets()

		const rows = wrapper.findAll('.budgets__row').map((row) => row.findAllComponents(NcButton).map((/** @type {any} */ one) => one.props('ariaLabel')))
		expect(rows).toEqual([
			['Change the budget for 2031', 'Delete the budget for 2031'],
			['Change the budget for 2032', 'Delete the budget for 2032'],
		])
	})

	it('shows a manager the budgets and nothing to change them with', async () => {
		const wrapper = await budgets(MANAGED)

		expect(wrapper.findAll('.budgets__year')).toHaveLength(2)
		expect(wrapper.findAllComponents(NcButton)).toHaveLength(0)
		expect(wrapper.find('.budgets__add').exists()).toBe(false)
	})

	it('asks the owner for a currency first', async () => {
		vi.mocked(listBudgets).mockResolvedValue([])
		const wrapper = await budgets({ ...OWNED, currency: null })

		expect(wrapper.find('.budgets__add').exists()).toBe(false)
		expect(wrapper.text()).toContain('Set the currency first. A budget is an amount in the currency of the vehicle.')
	})
})
