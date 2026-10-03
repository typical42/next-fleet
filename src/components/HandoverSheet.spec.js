/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { BookingConflictError, checkIn, checkOut, createBooking } from '../services/api.js'
import HandoverSheet from './HandoverSheet.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	checkIn: vi.fn(),
	checkOut: vi.fn(),
	createBooking: vi.fn(),
}))

vi.mock('@nextcloud/l10n', async (original) => ({
	...await original(),
	getCanonicalLocale: () => 'en-GB',
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', odo_value: 52000, odo_unit: 'km', may: ['view', 'log'] }

/** Built from a local wall clock, so the expectations hold in whatever zone the run is in. */
const FRIDAY_ONE_TWENTY = new Date(2026, 9, 2, 13, 20)

/** @type {import('../services/api.js').Booking} */
const MINE = /** @type {any} */ ({
	uuid: 'b-1',
	updated_at: 1790000000,
	user_id: 'me',
	user_name: 'Me',
	starts_at: new Date(2026, 9, 2, 14, 0).getTime() / 1000,
	starts_at_off: 120,
	ends_at: new Date(2026, 9, 2, 18, 0).getTime() / 1000,
	ends_at_off: 120,
	purpose: 'Client visit',
	state: 'booked',
	out_odo: null,
	flags: [],
	may: ['edit', 'cancel', 'check_out'],
})

/**
 * Anna's, Friday 10:00–12:00 in Berlin, as a 409 names it.
 *
 * @type {import('../services/api.js').BookingHeld}
 */
const ANNAS = { uuid: 'b-2', user_id: 'anna', user_name: 'Anna', starts_at: Date.UTC(2026, 9, 2, 8) / 1000, starts_at_off: 120, ends_at: Date.UTC(2026, 9, 2, 10) / 1000, ends_at_off: 120, state: 'booked' }

/**
 * @param {object|null} [booking] - the booking the car is taken or given back under, or none to take it now
 * @return {import('@vue/test-utils').VueWrapper} the sheet
 */
function sheet(booking = MINE) {
	return shallowMount(HandoverSheet, {
		props: { vehicle: VEHICLE, booking },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the field's label
 * @return {any} the field
 */
function field(wrapper, label) {
	return wrapper.findAllComponents(NcTextField).find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(FRIDAY_ONE_TWENTY)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('taking the car', () => {
	/** The vehicle's own counter is the best guess; the driver corrects it from the dashboard. */
	it('prefills the counter from the vehicle and checks out with what was stated', async () => {
		const out = { ...MINE, state: /** @type {const} */ ('out') }
		vi.mocked(checkOut).mockResolvedValue(out)
		const wrapper = sheet()

		expect(field(wrapper, 'Counter reading (km)').props('modelValue')).toBe('52000')
		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52140')
		await field(wrapper, 'Tank or battery (%)').vm.$emit('update:modelValue', '80')
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(checkOut).toHaveBeenCalledWith('v-1', MINE, { odo: 52140, level: 80, at_off: -FRIDAY_ONE_TWENTY.getTimezoneOffset() })
		expect(wrapper.emitted('saved')).toEqual([[out]])
	})

	/** The refusal names whose the car is, so the driver knows whom to ask, and the form stays. */
	it('says the car is still with someone, and keeps the values', async () => {
		vi.mocked(checkOut).mockRejectedValue(new BookingConflictError('the vehicle is still out', { ...ANNAS, state: /** @type {const} */ ('out') }))
		const wrapper = sheet()

		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52140')
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('Still with Anna, booked Fri 02/10, 10:00–12:00')
		expect(field(wrapper, 'Counter reading (km)').props('modelValue')).toBe('52140')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	/** Taking it early claims the hours before the start, which someone else may hold. */
	it('says who holds the hours before the start', async () => {
		vi.mocked(checkOut).mockRejectedValue(new BookingConflictError('the vehicle is booked until then', ANNAS))
		const wrapper = sheet()

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('Booked by Anna, Fri 02/10, 10:00–12:00')
	})

	it('refuses a counter that is not one, and a level over 100', async () => {
		const wrapper = sheet()

		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '7,2')
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()
		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('That is not a counter reading.')

		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52140')
		await field(wrapper, 'Tank or battery (%)').vm.$emit('update:modelValue', '120')
		await button(wrapper, 'Try again').vm.$emit('click')
		await flushPromises()
		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The tank or battery is a percentage, 0 to 100.')

		expect(checkOut).not.toHaveBeenCalled()
	})
})

describe('giving the car back', () => {
	const OUT = { ...MINE, state: /** @type {const} */ ('out'), out_odo: 52140, may: ['check_in'] }

	/** The counter is what the dashboard reads now; a prefilled one would be saved unread. */
	it('asks for the counter afresh, names the one it was taken at, and checks in with the note', async () => {
		const answer = /** @type {any} */ ({ ...OUT, state: 'returned', trip_draft: {} })
		vi.mocked(checkIn).mockResolvedValue(answer)
		const wrapper = sheet(OUT)

		expect(field(wrapper, 'Counter reading (km)').props('modelValue')).toBe('')
		expect(field(wrapper, 'Counter reading (km)').props('helperText')).toBe('Taken at 52,140 km')
		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52310')
		await wrapper.getComponent(NcTextArea).vm.$emit('update:modelValue', 'Wiper fluid low')
		await button(wrapper, 'Return the car').vm.$emit('click')
		await flushPromises()

		expect(checkIn).toHaveBeenCalledWith('v-1', OUT, { odo: 52310, notes: 'Wiper fluid low', at_off: -FRIDAY_ONE_TWENTY.getTimezoneOffset() })
		expect(wrapper.emitted('saved')).toEqual([[answer]])
	})
})

describe('taking it now', () => {
	const BOOKED_NOW = { ...MINE, uuid: 'b-9', starts_at: FRIDAY_ONE_TWENTY.getTime() / 1000 }

	/** No route of its own: a booking from now to the end the sheet asks for, then the check-out. */
	it('books from now to the end it asks for, then takes the car', async () => {
		vi.mocked(createBooking).mockResolvedValue(BOOKED_NOW)
		vi.mocked(checkOut).mockResolvedValue({ ...BOOKED_NOW, state: /** @type {const} */ ('out') })
		const wrapper = sheet(null)

		const end = wrapper.getComponent(NcDateTimePickerNative)
		expect(end.props('modelValue')).toEqual(new Date(2026, 9, 2, 16, 0))
		await end.vm.$emit('update:modelValue', new Date(2026, 9, 2, 17, 30))
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		const off = -FRIDAY_ONE_TWENTY.getTimezoneOffset()
		expect(createBooking).toHaveBeenCalledWith('v-1', {
			starts_at: FRIDAY_ONE_TWENTY.getTime() / 1000,
			starts_at_off: off,
			ends_at: new Date(2026, 9, 2, 17, 30).getTime() / 1000,
			ends_at_off: -new Date(2026, 9, 2, 17, 30).getTimezoneOffset(),
		})
		expect(checkOut).toHaveBeenCalledWith('v-1', BOOKED_NOW, { odo: 52000, at_off: off })
		expect(wrapper.emitted('saved')).toEqual([[{ ...BOOKED_NOW, state: /** @type {const} */ ('out') }]])
	})

	/** The booking stands once it is made; trying again takes the car under it rather than booking twice. */
	it('takes the car under the booking it already made when the check-out is tried again', async () => {
		vi.mocked(createBooking).mockResolvedValue(BOOKED_NOW)
		vi.mocked(checkOut).mockRejectedValueOnce(new Error('The server answered 500')).mockResolvedValueOnce({ ...BOOKED_NOW, state: /** @type {const} */ ('out') })
		const wrapper = sheet(null)

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()
		expect(wrapper.getComponent(NcNoteCard).props('text')).toContain('500')
		// The end is the booking's now; changing it here would be ignored, so it cannot be.
		expect(wrapper.getComponent(NcDateTimePickerNative).attributes('disabled')).toBe('true')

		await button(wrapper, 'Try again').vm.$emit('click')
		await flushPromises()

		expect(createBooking).toHaveBeenCalledTimes(1)
		expect(checkOut).toHaveBeenLastCalledWith('v-1', BOOKED_NOW, expect.anything())
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	/** A booking that starts within the hour may stand in the way; it is named as on the booking sheet. */
	it('names the booking in the way of the span', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is booked then', ANNAS))
		const wrapper = sheet(null)

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('Booked by Anna, Fri 02/10, 10:00–12:00')
		expect(checkOut).not.toHaveBeenCalled()
	})
})
