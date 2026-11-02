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

vi.mock('@nextcloud/auth', async (original) => ({
	...await original(),
	getCurrentUser: () => ({ uid: 'me' }),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', odo_value: 52000, odo_unit: 'km', may: ['view', 'log'] }

/** A driver's bookings on it wait for an answer. */
const APPROVAL = { ...VEHICLE, booking_approval: true }

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
const ANNAS = { uuid: 'b-2', user_id: 'anna', user_name: 'Anna', starts_at: Date.UTC(2026, 9, 2, 8) / 1000, starts_at_off: 120, ends_at: Date.UTC(2026, 9, 2, 10) / 1000, ends_at_off: 120, state: 'booked', approval: null }

/**
 * @param {object|null} [booking] - the booking the car is taken or given back under, or none to take it now
 * @param {number|null} [lastIn] - the counter the car last came back at
 * @param {object} [vehicle] - the vehicle it is
 * @return {import('@vue/test-utils').VueWrapper} the sheet
 */
function sheet(booking = MINE, lastIn = null, vehicle = VEHICLE) {
	return shallowMount(HandoverSheet, {
		props: { vehicle, booking, lastIn },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { props: ['name'], template: '<div class="dialog"><h2>{{ name }}</h2><slot /><slot name="actions" /></div>' } },
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

	/** The owner or a manager taking someone else's booking is not the one who drives. */
	it('hands the car over to the booker on someone else\'s booking', async () => {
		const annas = { ...MINE, user_id: 'anna', user_name: 'Anna' }
		vi.mocked(checkOut).mockResolvedValue({ ...annas, state: /** @type {const} */ ('out') })
		const wrapper = sheet(annas)

		expect(wrapper.get('h2').text()).toBe('Hand over to Anna')
		await button(wrapper, 'Hand over to Anna').vm.$emit('click')
		await flushPromises()

		expect(checkOut).toHaveBeenCalledWith('v-1', annas, expect.anything())
		expect(sheet().get('h2').text()).toBe('Take the car')
		expect(sheet(null).get('h2').text()).toBe('Take the car')
	})

	it('prefills the larger of the vehicle\'s counter and the one the car last came back at', () => {
		expect(field(sheet(MINE, 52140), 'Counter reading (km)').props('modelValue')).toBe('52140')
		expect(field(sheet(MINE, 51000), 'Counter reading (km)').props('modelValue')).toBe('52000')
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

	/** The server's words are English and name columns; a refusal the sheet knows reads as the user's. */
	it('says a refusal it knows in words', async () => {
		vi.mocked(checkOut).mockRejectedValue(new Error('the booking is over, so the car is no longer taken under it'))
		const wrapper = sheet()

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The booking is over, so the car is no longer taken under it.')
	})

	it('says a note over the bound in words', async () => {
		vi.mocked(checkOut).mockRejectedValue(new Error('notes is longer than 10000 characters'))
		const wrapper = sheet()

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The note is longer than 10,000 characters.')
	})

	/**
	 * Taken now, the booking would stand and the list hear "taken" before the check-out refused the
	 * note. The bound counts characters of the trimmed note, as the server's Field::read() does,
	 * not UTF-16 units.
	 */
	it('refuses a note over the bound before it books', async () => {
		const wrapper = sheet(null)

		await wrapper.getComponent(NcTextArea).vm.$emit('update:modelValue', 'x'.repeat(10001))
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()
		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('The note is longer than 10,000 characters.')
		expect(createBooking).not.toHaveBeenCalled()

		vi.mocked(createBooking).mockRejectedValue(new Error('The server answered 500'))
		await wrapper.getComponent(NcTextArea).vm.$emit('update:modelValue', '🚗'.repeat(10000))
		await button(wrapper, 'Try again').vm.$emit('click')
		await flushPromises()
		expect(createBooking).toHaveBeenCalledTimes(1)

		await wrapper.getComponent(NcTextArea).vm.$emit('update:modelValue', `${'x'.repeat(10000)}\n`)
		await button(wrapper, 'Try again').vm.$emit('click')
		await flushPromises()
		expect(createBooking).toHaveBeenCalledTimes(2)
	})
})

describe('giving the car back', () => {
	const OUT = { ...MINE, state: /** @type {const} */ ('out'), out_odo: 52140, may: ['check_in'] }

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

	/** Kept past midnight on a vehicle that wants answers, it is a request; nothing typed is asked for. */
	it('says before saving that a take-now past midnight becomes a request', async () => {
		const wrapper = sheet(null, null, APPROVAL)

		expect(button(wrapper, 'Take the car')).toBeDefined()
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 0, 0))
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		const note = wrapper.getComponent(NcNoteCard)
		expect(note.props('heading')).toBe('Kept past midnight, this is a request that waits for approval.')
		expect(note.props('text')).toBe('You state the counter, the tank and the notes when you take the car.')
		expect(button(wrapper, 'Request')).toBeDefined()
		expect(wrapper.findComponent(NcTextField).exists()).toBe(false)
		expect(wrapper.findComponent(NcTextArea).exists()).toBe(false)
	})

	/** The owner and the managers answer requests, so theirs never waits (BookingService::insert()). */
	it('says nothing of a request to one who answers them', async () => {
		const wrapper = sheet(null, null, { ...APPROVAL, may: ['view', 'log', 'edit'] })

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		expect(button(wrapper, 'Take the car')).toBeDefined()
	})

	/** Kept past the day on a vehicle that wants answers, it is a request: booked, not taken, and said so. */
	it('leaves a take-now that waits for approval booked, takes nothing, and says it waits', async () => {
		const requested = { ...BOOKED_NOW, approval: /** @type {const} */ ('requested'), may: ['edit', 'cancel'] }
		vi.mocked(createBooking).mockResolvedValue(requested)
		const wrapper = sheet(null, null, APPROVAL)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		await button(wrapper, 'Request').vm.$emit('click')
		await flushPromises()

		expect(createBooking).toHaveBeenCalledTimes(1)
		expect(checkOut).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toBeUndefined()
		const alert = wrapper.getComponent(NcNoteCard)
		expect(alert.props('showAlert')).toBe(true)
		expect(alert.props('heading')).toBe('The booking waits for approval.')
		expect(alert.props('text')).toBe('You state the counter, the tank and the notes when you take the car.')
		expect(wrapper.findAllComponents(NcButton).map((one) => one.text())).toEqual(['Close'])

		await button(wrapper, 'Close').vm.$emit('click')
		expect(wrapper.emitted('close')).toHaveLength(1)
	})

	/** The button pressed is gone; a keyboard user lands on the one that is left. */
	it('moves focus to Close once a take-now comes back a request', async () => {
		vi.mocked(createBooking).mockResolvedValue({ ...BOOKED_NOW, approval: /** @type {const} */ ('requested') })
		const wrapper = shallowMount(HandoverSheet, {
			props: { vehicle: APPROVAL, booking: null, lastIn: null },
			attachTo: document.body,
			global: {
				renderStubDefaultSlot: true,
				stubs: {
					NcDialog: { template: '<div><slot /><slot name="actions" /></div>' },
					NcButton: { props: ['disabled'], template: '<button type="button" :disabled="disabled"><slot /></button>' },
				},
			},
		})

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		await wrapper.get('button:last-of-type').trigger('click')
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.get('button').element)
		expect(wrapper.get('button').text()).toBe('Close')
		wrapper.unmount()
	})

	/** The vehicle on screen may be older than the switch: what was typed is not dropped unsaid. */
	it('says so when a take-now the sheet thought free comes back a request', async () => {
		vi.mocked(createBooking).mockResolvedValue({ ...BOOKED_NOW, approval: /** @type {const} */ ('requested') })
		const wrapper = sheet(null)

		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52140')
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(checkOut).not.toHaveBeenCalled()
		expect(wrapper.getComponent(NcNoteCard).props('heading')).toBe('The booking waits for approval.')
		expect(wrapper.findComponent(NcTextField).exists()).toBe(false)
	})

	/** The other way round, the counter was never asked for, so the car is not taken unread. */
	it('asks for the counter when a request the sheet foresaw needs no approval after all', async () => {
		vi.mocked(createBooking).mockResolvedValue(BOOKED_NOW)
		vi.mocked(checkOut).mockResolvedValue({ ...BOOKED_NOW, state: /** @type {const} */ ('out') })
		const wrapper = sheet(null, null, APPROVAL)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		await button(wrapper, 'Request').vm.$emit('click')
		await flushPromises()

		expect(checkOut).not.toHaveBeenCalled()
		const note = wrapper.getComponent(NcNoteCard)
		expect(note.props('type')).toBe('info')
		expect(note.props('text')).toBe('No approval needed after all. State the counter and take the car.')
		await field(wrapper, 'Counter reading (km)').vm.$emit('update:modelValue', '52140')
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(createBooking).toHaveBeenCalledTimes(1)
		expect(checkOut).toHaveBeenCalledWith('v-1', BOOKED_NOW, expect.objectContaining({ odo: 52140 }))
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	/** A refused request is still a request: the refusal joins the note, it does not replace it. */
	it('keeps saying it is a request when one is refused', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is booked then', ANNAS))
		const wrapper = sheet(null, null, APPROVAL)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 3, 9, 0))
		await button(wrapper, 'Request').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findAllComponents(NcNoteCard).map((one) => one.props('heading') ?? one.props('text'))).toEqual([
			'Booked by Anna, Fri 02/10, 10:00–12:00',
			'Kept past midnight, this is a request that waits for approval.',
		])
		expect(button(wrapper, 'Request')).toBeDefined()
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

	it('names a request in the way as one', async () => {
		vi.mocked(createBooking).mockRejectedValue(new BookingConflictError('the vehicle is booked then', { ...ANNAS, approval: 'asked' }))
		const wrapper = sheet(null)

		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('Requested by Anna, Fri 02/10, 10:00–12:00')
	})

	/** Checked before sending, in words; the start is now, so an end gone by is also before it. */
	it('refuses an end gone by without asking the server', async () => {
		const wrapper = sheet(null)

		await wrapper.getComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2026, 9, 2, 12, 0))
		await button(wrapper, 'Take the car').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toBe('A booking ends in the future. A drive that is over is logged as a trip.')
		expect(createBooking).not.toHaveBeenCalled()
	})
})

describe('closing the sheet', () => {
	it('closes on Esc and on Cancel', async () => {
		const wrapper = sheet()

		await wrapper.find('.sheet').trigger('keydown.esc')
		await button(wrapper, 'Cancel').vm.$emit('click')

		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	/** Esc in the date field belongs to the picker the browser opened (VehicleSheet.vue says why). */
	it('stays open on Esc in the date field', async () => {
		const wrapper = sheet(null)

		await wrapper.getComponent(NcDateTimePickerNative).trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('stays open while a save is in flight', async () => {
		vi.mocked(checkOut).mockReturnValue(new Promise(() => {}))
		const wrapper = sheet()

		await button(wrapper, 'Take the car').vm.$emit('click')
		await wrapper.find('.sheet').trigger('keydown.esc')

		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
