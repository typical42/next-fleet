/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { addBookingRecipient, listBookingRecipients, removeBookingRecipient, searchGrantees } from '../services/api.js'
import BookingRecipients from './BookingRecipients.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	addBookingRecipient: vi.fn(),
	listBookingRecipients: vi.fn(),
	removeBookingRecipient: vi.fn(),
	searchGrantees: vi.fn(),
}))

/** @type {import('../services/api.js').BookingRecipient} */
const BEN = { recipient: 'ben', recipient_type: 'user', display_name: 'Ben' }
/** @type {import('../services/api.js').BookingRecipient} */
const CREW = { recipient: 'crew', recipient_type: 'group', display_name: 'Crew' }

/**
 * @param {string[]} events - what the list hears
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section(events = ['booked', 'taken']) {
	const wrapper = shallowMount(BookingRecipients, {
		props: { vehicle: 'v-1', events },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @return {any} the picker
 */
function picker(wrapper) {
	return wrapper.findComponent(NcSelectUsers)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @return {any[]} the event boxes, in the order the server names the events
 */
function boxes(wrapper) {
	return wrapper.findAllComponents(NcCheckboxRadioSwitch)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listBookingRecipients).mockResolvedValue([BEN])
	vi.mocked(addBookingRecipient).mockResolvedValue([BEN, CREW])
	vi.mocked(removeBookingRecipient).mockResolvedValue([])
	vi.mocked(searchGrantees).mockResolvedValue([{ grantee: 'crew', grantee_type: 'group', display_name: 'Crew' }])
})

describe('who hears of the bookings', () => {
	it('shows the list and the events it hears', async () => {
		const wrapper = await section(['booked', 'returned'])

		expect(listBookingRecipients).toHaveBeenCalledWith('v-1')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['user:ben'])
		expect(boxes(wrapper).map((box) => box.props('modelValue'))).toEqual([true, false, true, false, false])
	})

	it('says an empty list means the owner', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([])

		const wrapper = await section()

		expect(wrapper.text()).toContain('With nobody on the list, the notices go to you.')
	})

	it('shows nothing to somebody the list is refused to', async () => {
		vi.mocked(listBookingRecipients).mockRejectedValue(new Error('Not yours'))

		const wrapper = await section()

		expect(picker(wrapper).exists()).toBe(false)
		expect(boxes(wrapper)).toHaveLength(0)
	})

	it('adds a group the picker found', async () => {
		const wrapper = await section()
		await picker(wrapper).vm.$emit('search', 'cr')
		await flushPromises()
		const [crew] = picker(wrapper).props('options')
		expect(crew.id).toBe('group:crew')

		await picker(wrapper).vm.$emit('update:modelValue', [...picker(wrapper).props('modelValue'), crew])
		await flushPromises()

		expect(addBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'crew', recipient_type: 'group' })
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['user:ben', 'group:crew'])
	})

	it('takes one off the list', async () => {
		const wrapper = await section()

		await picker(wrapper).vm.$emit('update:modelValue', [])
		await flushPromises()

		expect(removeBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'ben', recipient_type: 'user' })
		expect(picker(wrapper).props('modelValue')).toEqual([])
	})

	it('says why a pick was refused', async () => {
		vi.mocked(addBookingRecipient).mockRejectedValue(new Error('recipient is not an account or group on this instance'))
		const wrapper = await section()

		await picker(wrapper).vm.$emit('update:modelValue', [...picker(wrapper).props('modelValue'), { id: 'user:x', recipient: { recipient: 'x', recipient_type: 'user' } }])
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('You cannot add this account or group to the list.')
	})

	it('hands the ticked events to the sheet, which saves them with the vehicle', async () => {
		const wrapper = await section(['booked', 'taken'])

		await boxes(wrapper)[2].vm.$emit('update:modelValue', true)
		await boxes(wrapper)[0].vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('update:events')).toEqual([[['booked', 'taken', 'returned']], [['taken', 'returned']]])
	})
})
