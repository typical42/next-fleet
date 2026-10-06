/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { addRecipient, listRecipients, removeRecipient, searchUsers } from '../services/api.js'
import ReminderRecipients from './ReminderRecipients.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	addRecipient: vi.fn(),
	listRecipients: vi.fn(),
	removeRecipient: vi.fn(),
	searchUsers: vi.fn(),
}))

const ALICE = { user_id: 'alice', display_name: 'Alice' }
const DAVE = { user_id: 'dave', display_name: 'Dave' }

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section() {
	const wrapper = shallowMount(ReminderRecipients, {
		props: { vehicle: 'v-1', cadence: 'weekly' },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @return {any} the account picker
 */
function picker(wrapper) {
	return wrapper.findComponent(NcSelectUsers)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listRecipients).mockResolvedValue([ALICE])
	vi.mocked(addRecipient).mockResolvedValue([ALICE, DAVE])
	vi.mocked(removeRecipient).mockResolvedValue([])
	vi.mocked(searchUsers).mockResolvedValue([DAVE])
})

describe('the recipients and the mail cadence', () => {
	it('shows who the reminders go to and how often the mail comes', async () => {
		const wrapper = await section()

		expect(listRecipients).toHaveBeenCalledWith('v-1')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['alice'])
		expect(/** @type {any} */ (wrapper.findComponent(NcSelect)).props('modelValue').id).toBe('weekly')
	})

	it('shows nothing to somebody the list is refused to', async () => {
		vi.mocked(listRecipients).mockRejectedValue(new Error('Not yours'))

		const wrapper = await section()

		expect(picker(wrapper).exists()).toBe(false)
		expect(wrapper.findComponent(NcSelect).exists()).toBe(false)
	})

	it('hands a changed cadence to the sheet, which saves it with the vehicle', async () => {
		const wrapper = await section()

		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { id: 'monthly', label: 'Monthly' })

		expect(wrapper.emitted('update:cadence')).toEqual([['monthly']])
	})

	it('offers the accounts core finds for what was typed', async () => {
		const wrapper = await section()

		await picker(wrapper).vm.$emit('search', 'da')
		await flushPromises()

		expect(searchUsers).toHaveBeenCalledWith('da')
		expect(picker(wrapper).props('options')).toEqual([{ id: 'dave', displayName: 'Dave', subname: 'dave', user: 'dave' }])
	})

	it('keeps the matches for what was typed last, whichever answer comes last', async () => {
		/** @type {(found: any[]) => void} */
		let answerFirst = () => {}
		vi.mocked(searchUsers)
			.mockReturnValueOnce(new Promise((resolve) => { answerFirst = resolve }))
			.mockResolvedValueOnce([DAVE])
		const wrapper = await section()

		await picker(wrapper).vm.$emit('search', 'd')
		await picker(wrapper).vm.$emit('search', 'dave')
		await flushPromises()
		answerFirst([{ user_id: 'dan', display_name: 'Dan' }])
		await flushPromises()

		expect(picker(wrapper).props('options').map((/** @type {any} */ one) => one.id)).toEqual(['dave'])
	})

	/** Each pick is written at once, and the list shown is the one the server answered with. */
	it('adds an account picked and removes one taken off, the owner included', async () => {
		const wrapper = await section()
		const [alice] = picker(wrapper).props('modelValue')

		await picker(wrapper).vm.$emit('update:modelValue', [alice, { id: 'dave', displayName: 'Dave', user: 'dave' }])
		await flushPromises()
		expect(addRecipient).toHaveBeenCalledWith('v-1', 'dave')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['alice', 'dave'])

		vi.mocked(removeRecipient).mockResolvedValue([DAVE])
		await picker(wrapper).vm.$emit('update:modelValue', [picker(wrapper).props('modelValue')[1]])
		await flushPromises()
		expect(removeRecipient).toHaveBeenCalledWith('v-1', 'alice')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['dave'])
	})

	/** Gone since core's search found it, or somebody the caller may not share with. */
	it('says so in words when an account is refused and keeps the list as it was', async () => {
		vi.mocked(addRecipient).mockRejectedValue(new Error('user_id is not an account on this instance'))
		const wrapper = await section()
		const [alice] = picker(wrapper).props('modelValue')

		await picker(wrapper).vm.$emit('update:modelValue', [alice, { id: 'ghost', displayName: 'Ghost', user: 'ghost' }])
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('You cannot add this account to the list.')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['alice'])
	})

	it('shows a refusal it has no words for as the server sent it', async () => {
		vi.mocked(removeRecipient).mockRejectedValue(new Error('Not yours'))
		const wrapper = await section()

		await picker(wrapper).vm.$emit('update:modelValue', [])
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The list was not changed: Not yours')
	})
})
