/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { BookingConflictError, changeBooking, ConflictError, createBooking, listBookings } from '../services/api.js'
import BookingSheet from './BookingSheet.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	changeBooking: vi.fn(),
	createBooking: vi.fn(),
	listBookings: vi.fn(),
}))

vi.mock('@nextcloud/l10n', async (original) => ({
	...await original(),
	getCanonicalLocale: () => 'en-GB',
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', may: ['view', 'log'] }

/** Built from local wall clocks, so the expectations hold in whatever zone the run is in. */
const FRIDAY_FOUR = new Date(2026, 9, 2, 16, 0)
const FRIDAY_SEVEN = new Date(2026, 9, 2, 19, 30)

/** @type {import('../services/api.js').Booking} */
const MINE = /** @type {any} */ ({
	uuid: 'b-1',
	updated_at: 1790000000,
	user_id: 'me',
	user_name: 'Me',
	starts_at: FRIDAY_FOUR.getTime() / 1000,
	starts_at_off: -FRIDAY_FOUR.getTimezoneOffset(),
	ends_at: FRIDAY_SEVEN.getTime() / 1000,
	ends_at_off: -FRIDAY_SEVEN.getTimezoneOffset(),
	purpose: 'Client visit',
	state: 'booked',
	may: ['edit', 'cancel', 'check_out'],
})

/**
 * @param {object|null} [booking] - the booking to change, or none to book
 * @return {import('@vue/test-utils').VueWrapper} the sheet
 */
function sheet(booking = null) {
	return shallowMount(BookingSheet, {
		props: { vehicle: VEHICLE, booking },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the picker's label
 * @return {any} the picker
 */
function picker(wrapper, label) {
	return wrapper.findAllComponents(NcDateTimePickerNative).find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - the button's words
 */
async function press(wrapper, text) {
	await button(wrapper, text).vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 9, 2, 13, 20))
	vi.mocked(createBooking).mockResolvedValue(MINE)
	vi.mocked(changeBooking).mockResolvedValue(MINE)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('booking a vehicle', () => {
	/** Nobody books the minute they are in: the next full hour, for two hours (docs/ui.md). */
	it('starts at the next full hour and ends two hours later', () => {
		const wrapper = sheet()

		expect(picker(wrapper, 'Start').props('modelValue')).toEqual(new Date(2026, 9, 2, 14, 0))
		expect(picker(wrapper, 'End').props('modelValue')).toEqual(new Date(2026, 9, 2, 16, 0))
	})

	/** Each end travels with the offset of its own moment, as every instant does (docs/architecture.md#time). */
	it('books the span and the purpose, each end with its offset', async () => {
		const wrapper = sheet()
		await picker(wrapper, 'Start').vm.$emit('update:modelValue', FRIDAY_FOUR)
		await picker(wrapper, 'End').vm.$emit('update:modelValue', FRIDAY_SEVEN)
		await wrapper.getComponent(NcTextField).vm.$emit('update:modelValue', 'Client visit')

		await press(wrapper, 'Book')

		expect(createBooking).toHaveBeenCalledWith('v-1', {
			starts_at: FRIDAY_FOUR.getTime() / 1000,
			starts_at_off: -FRIDAY_FOUR.getTimezoneOffset(),
			ends_at: FRIDAY_SEVEN.getTime() / 1000,
			ends_at_off: -FRIDAY_SEVEN.getTimezoneOffset(),
			purpose: 'Client visit',
		})
		expect(wrapper.emitted('saved')).toEqual([[MINE]])
	})

	/** A double-booked car is what a pool exists to prevent; the sheet says whose it is and keeps the form. */
	it('names the booking in the way and keeps what was typed', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is booked then', {
			uuid: 'b-2',
			user_id: 'anna',
			user_name: 'Anna',
			starts_at: 1790942400,
			starts_at_off: 120,
			ends_at: 1790956800,
			ends_at_off: 120,
			state: 'booked',
		}))
		const wrapper = sheet()
		await wrapper.getComponent(NcTextField).vm.$emit('update:modelValue', 'Client visit')

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('Booked by Anna, Fri 02/10, 14:00–18:00')
		expect(wrapper.getComponent(NcTextField).props('modelValue')).toBe('Client visit')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	/** A car still out is with its booker until they bring it back, whatever its booking said. */
	it('says who has the car when the one in the way is out', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is booked then', {
			uuid: 'b-2',
			user_id: 'anna',
			user_name: 'Anna',
			starts_at: 1790942400,
			starts_at_off: 120,
			ends_at: 1790956800,
			ends_at_off: 120,
			state: 'out',
		}))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('With Anna until Fri 02/10, 18:00')
	})

	it('says why any other refusal came, and keeps the sheet', async () => {
		vi.mocked(createBooking).mockRejectedValue(new Error('ends_at is still to come'))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('ends_at is still to come')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})
})

describe('changing a booking', () => {
	it('opens on the booking and writes it back under its token', async () => {
		const wrapper = sheet(MINE)

		expect(picker(wrapper, 'Start').props('modelValue')).toEqual(FRIDAY_FOUR)
		expect(picker(wrapper, 'End').props('modelValue')).toEqual(FRIDAY_SEVEN)
		expect(wrapper.getComponent(NcTextField).props('modelValue')).toBe('Client visit')

		await press(wrapper, 'Save')

		expect(changeBooking).toHaveBeenCalledWith('v-1', MINE, expect.objectContaining({ purpose: 'Client visit', starts_at: MINE.starts_at }))
		expect(wrapper.emitted('saved')).toEqual([[MINE]])
	})

	/** As every sheet does (docs/ui.md): what is on screen wins, once the person has been told. */
	it('says it moved on, and the next save writes over it at the token read back', async () => {
		vi.mocked(changeBooking).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		vi.mocked(listBookings).mockResolvedValue([{ ...MINE, updated_at: 1790000999 }])
		const wrapper = sheet(MINE)

		await press(wrapper, 'Save')
		expect(wrapper.getComponent(NcNoteCard).props('type')).toBe('warning')

		await press(wrapper, 'Save anyway')

		expect(changeBooking).toHaveBeenLastCalledWith('v-1', expect.objectContaining({ updated_at: 1790000999 }), expect.anything())
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})
})
