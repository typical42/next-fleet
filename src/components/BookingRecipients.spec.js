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

vi.mock('@nextcloud/auth', async (original) => ({
	...await original(),
	getCurrentUser: () => ({ uid: 'olga', displayName: 'Olga' }),
}))

/** @type {import('../services/api.js').BookingRecipient} */
const BEN = { recipient: 'ben', recipient_type: 'user', display_name: 'Ben', events: ['booked', 'returned'] }
/** @type {import('../services/api.js').BookingRecipient} */
const CREW = { recipient: 'crew', recipient_type: 'group', display_name: 'Crew', events: ['taken'] }

/**
 * @param {string[]} events - what the vehicle's list hears
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
 * @return {{ legend: string, boxes: any[] }[]} each row's name and its event boxes, in the order the server names the events
 */
function rows(wrapper) {
	return wrapper.findAll('.notices__row').map((row) => ({
		legend: row.find('legend').text(),
		boxes: row.findAllComponents(NcCheckboxRadioSwitch),
	}))
}

/**
 * @param {any[]} boxes - one row's boxes
 * @return {boolean[]} which are ticked
 */
function ticked(boxes) {
	return boxes.map((box) => box.props('modelValue'))
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listBookingRecipients).mockResolvedValue([BEN])
	vi.mocked(addBookingRecipient).mockResolvedValue([BEN, CREW])
	vi.mocked(removeBookingRecipient).mockResolvedValue([])
	vi.mocked(searchGrantees).mockResolvedValue([{ grantee: 'crew', grantee_type: 'group', display_name: 'Crew' }])
})

describe('who hears of the bookings', () => {
	it('shows each one on the list with the events it hears', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([BEN, CREW])

		const wrapper = await section(['booked', 'returned'])

		expect(listBookingRecipients).toHaveBeenCalledWith('v-1')
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['user:ben', 'group:crew'])
		expect(rows(wrapper).map((row) => row.legend)).toEqual(['Ben', 'Group Crew'])
		expect(rows(wrapper).map((row) => ticked(row.boxes))).toEqual([[true, false, true, false, false], [false, true, false, false, false]])
	})

	/** The owner's row is the vehicle's events, saved with the vehicle. */
	it('shows the owner with the vehicle\'s events while the list is empty', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([])

		const wrapper = await section(['booked', 'returned'])

		expect(wrapper.text()).toContain('With nobody on the list, the notices go to you.')
		expect(rows(wrapper).map((row) => row.legend)).toEqual(['You (owner)'])
		expect(ticked(rows(wrapper)[0].boxes)).toEqual([true, false, true, false, false])
	})

	it('hands the owner\'s ticks to the sheet, which saves them with the vehicle', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([])
		const wrapper = await section(['booked', 'taken'])

		await rows(wrapper)[0].boxes[2].vm.$emit('update:modelValue', true)
		await rows(wrapper)[0].boxes[0].vm.$emit('update:modelValue', false)

		expect(wrapper.emitted('update:events')).toEqual([[['booked', 'taken', 'returned']], [['taken', 'returned']]])
		expect(addBookingRecipient).not.toHaveBeenCalled()
	})

	it('writes a tick on a row at once', async () => {
		vi.mocked(addBookingRecipient).mockResolvedValue([{ ...BEN, events: ['booked', 'taken', 'returned'] }])
		const wrapper = await section()

		await rows(wrapper)[0].boxes[1].vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(addBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'ben', recipient_type: 'user', events: ['booked', 'taken', 'returned'] })
		expect(ticked(rows(wrapper)[0].boxes)).toEqual([true, true, true, false, false])
		expect(wrapper.emitted('update:events')).toBeUndefined()
	})

	it('says why a tick was not saved', async () => {
		vi.mocked(addBookingRecipient).mockRejectedValue(new Error('Service unavailable'))
		const wrapper = await section()

		await rows(wrapper)[0].boxes[1].vm.$emit('update:modelValue', true)
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The list was not changed: Service unavailable')
		expect(ticked(rows(wrapper)[0].boxes)).toEqual([true, false, true, false, false])
	})

	it('says so in place of the list when it cannot be read', async () => {
		vi.mocked(listBookingRecipients).mockRejectedValue(new Error('Service unavailable'))

		const wrapper = await section()

		expect(picker(wrapper).exists()).toBe(false)
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The list could not be read: Service unavailable')
		expect(rows(wrapper)).toEqual([])
	})

	/** The server gives the new row the vehicle's events as saved. */
	it('adds a group the picker found', async () => {
		const wrapper = await section(['returned'])
		await picker(wrapper).vm.$emit('search', 'cr')
		await flushPromises()
		const crew = picker(wrapper).props('options').find((/** @type {any} */ one) => one.id === 'group:crew')

		await picker(wrapper).vm.$emit('update:modelValue', [...picker(wrapper).props('modelValue'), crew])
		await flushPromises()

		expect(addBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'crew', recipient_type: 'group' })
		expect(picker(wrapper).props('modelValue').map((/** @type {any} */ one) => one.id)).toEqual(['user:ben', 'group:crew'])
	})

	it('offers the owner themselves until they are on the list', async () => {
		const wrapper = await section()
		await picker(wrapper).vm.$emit('search', 'cr')
		await flushPromises()
		const [olga, crew] = picker(wrapper).props('options')
		expect([olga.id, olga.displayName, crew.id]).toEqual(['user:olga', 'Olga', 'group:crew'])

		await picker(wrapper).vm.$emit('update:modelValue', [...picker(wrapper).props('modelValue'), olga])
		await flushPromises()

		expect(addBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'olga', recipient_type: 'user' })
	})

	/** The "You (owner)" row goes away with the first pick, so its ticks go to the one picked. */
	it('gives the first one on an empty list the ticks on screen', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([])
		const wrapper = await section(['booked', 'taken', 'cancelled'])
		await rows(wrapper)[0].boxes[3].vm.$emit('update:modelValue', false)
		await wrapper.setProps({ events: wrapper.emitted('update:events')?.at(-1)?.[0] })
		const [olga] = picker(wrapper).props('options')

		await picker(wrapper).vm.$emit('update:modelValue', [olga])
		await flushPromises()

		expect(addBookingRecipient).toHaveBeenCalledWith('v-1', { recipient: 'olga', recipient_type: 'user', events: ['booked', 'taken'] })
	})

	it('offers the owner no second time once on the list', async () => {
		vi.mocked(listBookingRecipients).mockResolvedValue([{ ...BEN, recipient: 'olga', display_name: 'Olga' }])

		const wrapper = await section()

		expect(picker(wrapper).props('options')).toEqual([])
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
})
