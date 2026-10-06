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

/**
 * Now: Fri 2 Oct 2026, 13:20 in Berlin. An instant, not a wall clock, because the bookings in the
 * way are instants too: built from the run's zone, now would fall after them in Los Angeles.
 */
const NOW = 1790940000

/** 16:00 and 19:30 that Friday in Berlin: instants for the reason NOW is, so ahead of it in any zone. */
const FRIDAY_FOUR = new Date((NOW + 160 * 60) * 1000)
const FRIDAY_SEVEN = new Date((NOW + 370 * 60) * 1000)

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
	vi.setSystemTime(NOW * 1000)
	vi.mocked(createBooking).mockResolvedValue(MINE)
	vi.mocked(changeBooking).mockResolvedValue(MINE)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('booking a vehicle', () => {
	/** 14:00 in Berlin is the next full hour in any zone a whole number of hours from UTC. */
	it('starts at the next full hour and ends two hours later', () => {
		const wrapper = sheet()

		expect(picker(wrapper, 'Start').props('modelValue')).toEqual(new Date((NOW + 40 * 60) * 1000))
		expect(picker(wrapper, 'End').props('modelValue')).toEqual(new Date((NOW + 160 * 60) * 1000))
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

	/**
	 * A double-booked car is what a pool exists to prevent; the sheet names whose, keeps the form.
	 */
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

	it('says the car is still with them when the one in the way is overdue', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is still out then', {
			uuid: 'b-2',
			user_id: 'anna',
			user_name: 'Anna',
			starts_at: 1790582400,
			starts_at_off: 120,
			ends_at: 1790596800,
			ends_at_off: 120,
			state: 'out',
		}))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toMatch(/^Still with Anna, booked Mon 28\/09, 10:00\s*–\s*14:00$/)
	})

	/** The server's words are English and name columns; a refusal the sheet knows reads as the user's. */
	it('says a refusal it knows in words, and keeps the sheet', async () => {
		vi.mocked(createBooking).mockRejectedValue(new Error('the vehicle is not in service, so it takes no booking'))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The vehicle is not in service, so it takes no booking.')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('says a refusal it does not know as it came', async () => {
		vi.mocked(createBooking).mockRejectedValue(new Error('The server answered 500'))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The server answered 500')
	})

	/** Checked before sending, in words, rather than answered by the server in its own. */
	it('refuses an end before the start, or one gone by, without asking the server', async () => {
		const wrapper = sheet()
		await picker(wrapper, 'Start').vm.$emit('update:modelValue', FRIDAY_SEVEN)
		await picker(wrapper, 'End').vm.$emit('update:modelValue', FRIDAY_FOUR)

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The end of a booking comes after its start.')

		await picker(wrapper, 'Start').vm.$emit('update:modelValue', new Date((NOW - 3 * 3600) * 1000))
		await picker(wrapper, 'End').vm.$emit('update:modelValue', new Date((NOW - 3600) * 1000))

		await press(wrapper, 'Try again')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking ends in the future. A drive that is over is logged as a trip.')
		expect(createBooking).not.toHaveBeenCalled()
	})

	/** The server's bounds (docs/security.md), checked before sending. */
	it('refuses a span over 90 days, or an end over a year ahead, without asking the server', async () => {
		const wrapper = sheet()
		await picker(wrapper, 'Start').vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		await picker(wrapper, 'End').vm.$emit('update:modelValue', new Date(2027, 0, 1, 12, 0))

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking lasts 90 days at most.')

		await picker(wrapper, 'Start').vm.$emit('update:modelValue', new Date(2027, 9, 1, 9, 0))
		await picker(wrapper, 'End').vm.$emit('update:modelValue', new Date(2027, 9, 3, 9, 0))

		await press(wrapper, 'Try again')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking ends no more than a year from now.')
		expect(createBooking).not.toHaveBeenCalled()
	})

	it('says a start in the past in words', async () => {
		vi.mocked(createBooking).mockRejectedValue(new Error('starts_at is in the past'))
		const wrapper = sheet()

		await press(wrapper, 'Book')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking does not start in the past.')
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

	/** BookingService keeps a span that predates its bounds, so the sheet sends it too. */
	it('keeps a span longer than the bounds allow when only the purpose changes', async () => {
		const long = { ...MINE, ends_at: MINE.starts_at + 120 * 86400 }
		const wrapper = sheet(long)
		await wrapper.getComponent(NcTextField).vm.$emit('update:modelValue', 'Season')

		await press(wrapper, 'Save')

		expect(changeBooking).toHaveBeenCalledWith('v-1', long, expect.objectContaining({ purpose: 'Season', ends_at: long.ends_at }))

		await picker(wrapper, 'End').vm.$emit('update:modelValue', new Date((long.ends_at + 3600) * 1000))
		await press(wrapper, 'Save')

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking lasts 90 days at most.')
		expect(changeBooking).toHaveBeenCalledTimes(1)
	})
})

describe('closing the sheet', () => {
	it('closes on Esc and on Cancel', async () => {
		const wrapper = sheet()

		await wrapper.find('.sheet').trigger('keydown.esc')
		await press(wrapper, 'Cancel')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	/** Esc in a date field belongs to the picker the browser opened (VehicleSheet.vue says why). */
	it('stays open on Esc in a date field', async () => {
		const wrapper = sheet()

		await picker(wrapper, 'Start').trigger('keydown.esc')
		await picker(wrapper, 'End').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('stays open while a save is in flight', async () => {
		vi.mocked(createBooking).mockReturnValue(new Promise(() => {}))
		const wrapper = sheet()

		await press(wrapper, 'Book')
		await wrapper.find('.sheet').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
