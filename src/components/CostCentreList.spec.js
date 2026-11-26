/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, createCostCentre, deleteCostCentre, listCostCentres, NotFoundError, RefusedError, updateCostCentre } from '../services/api.js'
import CostCentreList from './CostCentreList.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	createCostCentre: vi.fn(),
	deleteCostCentre: vi.fn(),
	listCostCentres: vi.fn(),
	updateCostCentre: vi.fn(),
}))
vi.mock('@nextcloud/auth', () => ({
	getCurrentUser: () => ({ uid: 'owner' }),
	getRequestToken: () => 'token',
}))

const SALES = { uuid: 'c-1', user_id: 'owner', code: '4711', name: 'Sales', archived: false, created_at: 1, updated_at: 10 }
const OLD = { uuid: 'c-2', user_id: 'owner', code: null, name: 'Old project', archived: true, created_at: 2, updated_at: 20 }
/** The list of an owner whose vehicle the user drives: read along, never shown here. */
const THEIRS = { uuid: 'c-3', user_id: 'boss', code: null, name: 'Pool', archived: false, created_at: 3, updated_at: 30 }

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the list, past its first read
 */
async function list() {
	const wrapper = shallowMount(CostCentreList, { global: { renderStubDefaultSlot: true } })
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
	return wrapper.findComponent(NcNoteCard).exists() ? String(wrapper.findComponent(NcNoteCard).props('text')) : ''
}

beforeEach(() => {
	vi.clearAllMocks()
	vi.mocked(listCostCentres).mockResolvedValue([SALES, OLD, THEIRS])
})

describe('CostCentreList', () => {
	it('shows the user\'s own list, archived ones marked', async () => {
		const wrapper = await list()

		const names = wrapper.findAll('.centres__name').map((one) => one.text())
		expect(names).toEqual(['4711 Sales', 'Old project (archived)'])
	})

	it('says when the list is empty', async () => {
		vi.mocked(listCostCentres).mockResolvedValue([THEIRS])

		const wrapper = await list()

		expect(wrapper.text()).toContain('No cost centres yet.')
	})

	it('adds a cost centre, an empty code as none, and empties the fields', async () => {
		const added = { ...SALES, uuid: 'c-9', code: null, name: 'Service' }
		vi.mocked(createCostCentre).mockResolvedValue(added)
		const wrapper = await list()
		vi.mocked(listCostCentres).mockResolvedValue([SALES, OLD, added])
		const form = wrapper.find('.centres__add')

		await field(form, 'Code').vm.$emit('update:modelValue', '  ')
		await field(form, 'Name').vm.$emit('update:modelValue', ' Service ')
		await button(wrapper, 'Add cost centre').vm.$emit('click')
		await flushPromises()

		expect(createCostCentre).toHaveBeenCalledWith({ code: null, name: 'Service' })
		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toContain('Service')
		expect(field(form, 'Name').props('modelValue')).toBe('')
	})

	/** The server's order is its collation's, which a sort in the browser could contradict. */
	it('shows the list in the server\'s order after an add', async () => {
		const added = { ...SALES, uuid: 'c-9', code: null, name: 'Akquise' }
		vi.mocked(createCostCentre).mockResolvedValue(added)
		const wrapper = await list()
		vi.mocked(listCostCentres).mockResolvedValue([added, SALES, OLD])

		await field(wrapper.find('.centres__add'), 'Name').vm.$emit('update:modelValue', 'Akquise')
		await button(wrapper, 'Add cost centre').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toEqual(['Akquise', '4711 Sales', 'Old project (archived)'])
		expect(message(wrapper)).toBe('')
	})

	it('keeps an added cost centre on screen when the list cannot be read again', async () => {
		vi.mocked(createCostCentre).mockResolvedValue({ ...SALES, uuid: 'c-9', code: null, name: 'Service' })
		const wrapper = await list()
		vi.mocked(listCostCentres).mockRejectedValue(new Error('offline'))

		await field(wrapper.find('.centres__add'), 'Name').vm.$emit('update:modelValue', 'Service')
		await button(wrapper, 'Add cost centre').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toContain('Service')
		expect(message(wrapper)).toBe('')
	})

	it('adds nothing without a name', async () => {
		const wrapper = await list()

		await button(wrapper, 'Add cost centre').vm.$emit('click')

		expect(createCostCentre).not.toHaveBeenCalled()
		expect(message(wrapper)).toBe('A cost centre needs a name.')
	})

	it('says which value another cost centre has already', async () => {
		vi.mocked(createCostCentre).mockRejectedValue(new RefusedError('taken', 'code_taken'))
		const wrapper = await list()
		const form = wrapper.find('.centres__add')

		await field(form, 'Code').vm.$emit('update:modelValue', '4711')
		await field(form, 'Name').vm.$emit('update:modelValue', 'Service')
		await button(wrapper, 'Add cost centre').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('Another cost centre has this code.')
		expect(field(form, 'Name').props('modelValue')).toBe('Service')
	})

	it('renames a cost centre in its row and keeps whether it is archived', async () => {
		const renamed = { ...SALES, name: 'Sales North', updated_at: 11 }
		vi.mocked(updateCostCentre).mockResolvedValue(renamed)
		const wrapper = await list()
		vi.mocked(listCostCentres).mockResolvedValue([OLD, renamed])

		await button(wrapper, 'Rename').vm.$emit('click')
		const row = wrapper.find('.centres__edit')
		expect(field(row, 'Code').props('modelValue')).toBe('4711')
		await field(row, 'Name').vm.$emit('update:modelValue', 'Sales North')
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(updateCostCentre).toHaveBeenCalledWith(SALES, { code: '4711', name: 'Sales North', archived: false })
		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toContain('4711 Sales North')
		expect(wrapper.find('.centres__edit').exists()).toBe(false)
	})

	it('archives a cost centre and offers an archived one again', async () => {
		vi.mocked(updateCostCentre).mockImplementation(async (/** @type {any} */ centre, /** @type {any} */ fields) => ({ ...centre, ...fields, updated_at: centre.updated_at + 1 }))
		const wrapper = await list()

		await button(wrapper, 'Archive').vm.$emit('click')
		await flushPromises()
		// Both are archived now; the second row is the one that was before.
		await button(wrapper, 'Offer again', 1).vm.$emit('click')
		await flushPromises()

		expect(updateCostCentre).toHaveBeenNthCalledWith(1, SALES, { code: '4711', name: 'Sales', archived: true })
		expect(vi.mocked(updateCostCentre).mock.calls[1][1]).toEqual({ code: null, name: 'Old project', archived: false })
	})

	it('deletes a cost centre nothing names', async () => {
		vi.mocked(deleteCostCentre).mockResolvedValue({ uuid: 'c-1', deleted_at: 99 })
		const wrapper = await list()

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(deleteCostCentre).toHaveBeenCalledWith(SALES)
		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toEqual(['Old project (archived)'])
	})

	it('says to archive one that entries name', async () => {
		vi.mocked(deleteCostCentre).mockRejectedValue(new RefusedError('in use', 'in_use'))
		const wrapper = await list()

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('Entries or a vehicle use this cost centre. Archive it instead.')
		expect(wrapper.findAll('.centres__name')).toHaveLength(2)
	})

	it('reads the list again when a record changed since it was read', async () => {
		vi.mocked(updateCostCentre).mockRejectedValue(new ConflictError('stale'))
		const wrapper = await list()
		vi.mocked(listCostCentres).mockResolvedValue([{ ...SALES, name: 'Sales East', updated_at: 12 }])

		await button(wrapper, 'Archive').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('This cost centre was changed somewhere else. The list now shows it as it is.')
		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toEqual(['4711 Sales East'])
	})

	it('reads the list again when a record is gone since it was read', async () => {
		vi.mocked(updateCostCentre).mockRejectedValue(new NotFoundError('No such cost centre'))
		const wrapper = await list()
		vi.mocked(listCostCentres).mockResolvedValue([OLD])

		await button(wrapper, 'Rename').vm.$emit('click')
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(message(wrapper)).toBe('This cost centre no longer exists. The list now shows what is left.')
		expect(wrapper.findAll('.centres__name').map((one) => one.text())).toEqual(['Old project (archived)'])
		expect(wrapper.find('.centres__edit').exists()).toBe(false)
	})

	/**
	 * A screen reader tabbing through the rows hears which record each button acts on. Each name
	 * holds what the button shows, so voice control finds it by that.
	 */
	it('names the record in each row button', async () => {
		const wrapper = await list()

		const rows = wrapper.findAll('.centres__row').map((row) => row.findAllComponents(NcButton).map((/** @type {any} */ one) => one.props('ariaLabel')))
		expect(rows).toEqual([
			['Rename 4711 Sales', 'Archive 4711 Sales', 'Delete 4711 Sales'],
			['Rename Old project (archived)', 'Offer again: Old project (archived)', 'Delete Old project (archived)'],
		])
	})

	it('says when the list cannot be read', async () => {
		vi.mocked(listCostCentres).mockRejectedValue(new Error('No connection'))

		const wrapper = await list()

		expect(message(wrapper)).toBe('No connection')
		expect(wrapper.text()).not.toContain('No cost centres yet.')
	})
})
