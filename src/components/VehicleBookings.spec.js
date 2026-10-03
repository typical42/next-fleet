/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { cancelBooking, ConflictError, listBookings, readEntry } from '../services/api.js'
import BookingSheet from './BookingSheet.vue'
import HandoverSheet from './HandoverSheet.vue'
import VehicleBookings from './VehicleBookings.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	cancelBooking: vi.fn(),
	listBookings: vi.fn(),
	readEntry: vi.fn(),
}))

vi.mock('@nextcloud/l10n', async (original) => ({
	...await original(),
	getCanonicalLocale: () => 'en-GB',
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', may: ['view', 'log', 'book'] }
const VIEWER = { ...VEHICLE, may: ['view'] }
/** Logs, but a laid-up car takes no booking, so the server leaves `book` out. */
const LAID_UP = { ...VEHICLE, lifecycle: 'laid_up', may: ['view', 'log'] }

/** Friday 2 October 2026, 13:20 in Berlin: between the two bookings below. */
const NOW = 1790940000

/**
 * @param {object} more - what differs from a plain booking of Anna's
 * @return {import('../services/api.js').Booking} the booking
 */
function booking(more) {
	return /** @type {any} */ ({
		uuid: 'b-0',
		updated_at: 1790000000,
		user_id: 'anna',
		user_name: 'Anna',
		starts_at_off: 120,
		ends_at_off: 120,
		purpose: null,
		state: 'booked',
		trip_uuid: null,
		may: [],
		...more,
	})
}

/** Monday's, over. */
const PAST = booking({ uuid: 'b-1', starts_at: 1790582400, ends_at: 1790596800, state: 'returned' })
/** Friday afternoon, the reader's own. */
const COMING = booking({ uuid: 'b-2', user_id: 'me', user_name: 'Me', starts_at: 1790942400, ends_at: 1790956800, purpose: 'Client visit', may: ['edit', 'cancel', 'check_out'] })
/** Saturday, Anna's, and the reader may do nothing with it. */
const ANNAS = booking({ uuid: 'b-3', starts_at: 1791014400, ends_at: 1791028800 })

/**
 * @param {object} [vehicle] - the vehicle, and with it what the session may do on it
 * @param {import('../services/api.js').Document[]} [papers] - the vehicle's, as the screen hands them down
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section(vehicle = VEHICLE, papers = []) {
	const wrapper = shallowMount(VehicleBookings, {
		props: { vehicle, papers },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section, or a part of it
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} uuid - the booking
 * @return {any} its row
 */
function row(wrapper, uuid) {
	return wrapper.get(`[data-booking="${uuid}"]`)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(NOW * 1000)
	vi.mocked(listBookings).mockResolvedValue([PAST, COMING, ANNAS])
	vi.mocked(cancelBooking).mockResolvedValue({ ...COMING, state: 'cancelled' })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the bookings section', () => {
	/** The coming ones are what a driver plans around, so they come first; the last week's below. */
	it('lists the coming bookings, then the last week\'s', async () => {
		const wrapper = await section()

		expect(listBookings).toHaveBeenCalledWith('v-1')
		expect(wrapper.findAll('.bookings__coming [data-booking]').map((one) => one.attributes('data-booking'))).toEqual(['b-2', 'b-3'])
		expect(wrapper.findAll('.bookings__past [data-booking]').map((one) => one.attributes('data-booking'))).toEqual(['b-1'])
	})

	it('says who, when, what for and where it stands', async () => {
		const wrapper = await section()

		const text = row(wrapper, 'b-2').text()
		expect(text).toContain('Me')
		expect(text).toContain('Fri 02/10, 14:00–18:00')
		expect(text).toContain('Client visit')
		expect(text).toContain('Booked')
		expect(row(wrapper, 'b-1').text()).toContain('Returned')
	})

	/** Done when: the booking row in the Bookings section shows its photos. */
	it('shows a booking\'s handover photos on its row, each a link to our download', async () => {
		const photo = /** @type {import('../services/api.js').Document} */ ({ uuid: 'd-1', kind: 'photo', file_id: 7, name: 'scratch.jpg', mime: 'image/jpeg', linked_type: 'booking', linked_uuid: 'b-1', may: [] })
		const receipt = /** @type {import('../services/api.js').Document} */ ({ ...photo, uuid: 'd-2', name: 'fuel.pdf', linked_type: 'energy', linked_uuid: 'b-2' })

		const wrapper = await section(VEHICLE, [photo, receipt])

		const links = row(wrapper, 'b-1').findAll('.bookings__papers a')
		expect(links.map((/** @type {any} */ one) => one.attributes('aria-label'))).toEqual(['Open scratch.jpg'])
		expect(links[0].attributes('href')).toContain('/apps/nextfleet/vehicles/v-1/documents/d-1')
		expect(row(wrapper, 'b-2').find('.bookings__papers').exists()).toBe(false)
	})

	/** A car still out past its end has not been given back: it stays among the coming ones. */
	it('keeps a car that is still out among the coming ones', async () => {
		vi.mocked(listBookings).mockResolvedValue([booking({ uuid: 'b-4', starts_at: 1790582400, ends_at: 1790596800, state: 'out' })])

		const wrapper = await section()

		expect(wrapper.findAll('.bookings__coming [data-booking]')).toHaveLength(1)
	})

	it('teaches rather than saying nothing', async () => {
		vi.mocked(listBookings).mockResolvedValue([])

		const wrapper = await section()

		expect(wrapper.find('.bookings__empty').text()).toContain('Book the vehicle')
	})

	it('says only that there are none to someone who may not book', async () => {
		vi.mocked(listBookings).mockResolvedValue([])

		const wrapper = await section(VIEWER)

		expect(wrapper.find('.bookings__empty').text()).toBe('No bookings.')
	})

	it('says so when the list is refused', async () => {
		vi.mocked(listBookings).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await section()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toContain('500')
	})

	/** The first vehicle's list arriving last would put its bookings on the second. */
	it('drops the answer for a vehicle the screen has moved on from', async () => {
		/** @type {(list: any) => void} */
		let late = () => {}
		vi.mocked(listBookings).mockReturnValueOnce(new Promise((resolve) => { late = resolve }))
		const wrapper = await section()

		vi.mocked(listBookings).mockResolvedValueOnce([ANNAS])
		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()
		late([PAST, COMING])
		await flushPromises()

		expect(listBookings).toHaveBeenLastCalledWith('v-2')
		expect(wrapper.findAll('[data-booking]').map((one) => one.attributes('data-booking'))).toEqual(['b-3'])
	})
})

describe('booking', () => {
	/** By the vehicle's `book`: `log` on a car in service, as the server's rule is. */
	it('offers Book where the vehicle says book, and nowhere else', async () => {
		expect(button(await section(), 'Book')).toBeDefined()
		expect(button(await section(VIEWER), 'Book')).toBeUndefined()
		expect(button(await section(LAID_UP), 'Book')).toBeUndefined()
	})

	/** The screen is told too: the header names the reader's own next booking. */
	it('opens the sheet, and reads the list again once it is saved', async () => {
		const wrapper = await section()

		await button(wrapper, 'Book').vm.$emit('click')
		const sheet = /** @type {any} */ (wrapper.getComponent(BookingSheet))
		expect(sheet.props('booking')).toBeNull()

		await sheet.vm.$emit('saved', COMING)
		await flushPromises()

		expect(wrapper.findComponent(BookingSheet).exists()).toBe(false)
		expect(listBookings).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})
})

describe('changing and cancelling', () => {
	/** By the booking's own `may`, which knows whose it is and where it stands. */
	it('offers Change and Cancel on the rows that allow them, and nowhere else', async () => {
		const wrapper = await section()

		expect(button(row(wrapper, 'b-2'), 'Change')).toBeDefined()
		expect(button(row(wrapper, 'b-2'), 'Cancel booking')).toBeDefined()
		expect(row(wrapper, 'b-3').findAllComponents(NcButton)).toHaveLength(0)
	})

	it('opens the sheet on the booking', async () => {
		const wrapper = await section()

		await button(row(wrapper, 'b-2'), 'Change').vm.$emit('click')

		expect(/** @type {any} */ (wrapper.getComponent(BookingSheet)).props('booking')).toEqual(COMING)
	})

	/** A cancel has no undo, so it asks once (docs/ui.md). */
	it('asks once before it cancels', async () => {
		const wrapper = await section()

		await button(row(wrapper, 'b-2'), 'Cancel booking').vm.$emit('click')
		expect(cancelBooking).not.toHaveBeenCalled()
		expect(row(wrapper, 'b-2').text()).toContain('Cancel this booking?')

		await button(row(wrapper, 'b-2'), 'Yes, cancel it').vm.$emit('click')
		await flushPromises()

		expect(cancelBooking).toHaveBeenCalledWith('v-1', COMING)
		expect(listBookings).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	it('leaves the booking alone when the question is answered no', async () => {
		const wrapper = await section()

		await button(row(wrapper, 'b-2'), 'Cancel booking').vm.$emit('click')
		await button(row(wrapper, 'b-2'), 'Keep it').vm.$emit('click')

		expect(cancelBooking).not.toHaveBeenCalled()
		expect(button(row(wrapper, 'b-2'), 'Cancel booking')).toBeDefined()
	})

	/** The booking moved on; what it now is decides whether a cancel still makes sense, so the list is read again. */
	it('says so when the booking changed meanwhile, and shows it as it now stands', async () => {
		vi.mocked(cancelBooking).mockRejectedValue(new ConflictError('Changed since you read it'))
		const wrapper = await section()

		await button(row(wrapper, 'b-2'), 'Cancel booking').vm.$emit('click')
		await button(row(wrapper, 'b-2'), 'Yes, cancel it').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toContain('changed somewhere else')
		expect(listBookings).toHaveBeenCalledTimes(2)
	})
})

describe('the handover', () => {
	const OUT = booking({ uuid: 'b-5', user_id: 'me', user_name: 'Me', starts_at: 1790935200, ends_at: 1790949600, state: 'out', may: ['check_in'] })

	/** By the booking's own `may`, as Change and Cancel are. */
	it('offers Take the car on a booking that may be checked out, and opens the sheet on it', async () => {
		const wrapper = await section()

		expect(button(row(wrapper, 'b-3'), 'Take the car')).toBeUndefined()
		await button(row(wrapper, 'b-2'), 'Take the car').vm.$emit('click')

		expect(/** @type {any} */ (wrapper.getComponent(HandoverSheet)).props('booking')).toEqual(COMING)
	})

	/** The screen is told too: the header says who has the car. */
	it('offers Return the car on a booking that is out, and reads the list again once it is back', async () => {
		vi.mocked(listBookings).mockResolvedValue([OUT])
		const wrapper = await section()

		await button(row(wrapper, 'b-5'), 'Return the car').vm.$emit('click')
		const sheet = /** @type {any} */ (wrapper.getComponent(HandoverSheet))
		expect(sheet.props('booking')).toEqual(OUT)

		await sheet.vm.$emit('saved', { ...OUT, state: 'returned' })
		await flushPromises()

		expect(wrapper.findComponent(HandoverSheet).exists()).toBe(false)
		expect(listBookings).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('changed')).toHaveLength(1)
	})

	/** Nothing holds the car this minute, so whoever may log can take it without booking first. */
	it('offers Take it now while no booking holds the car, and opens the sheet on none', async () => {
		const wrapper = await section()

		await button(wrapper, 'Take it now').vm.$emit('click')

		expect(/** @type {any} */ (wrapper.getComponent(HandoverSheet)).props('booking')).toBeNull()
		expect(button(await section(VIEWER), 'Take it now')).toBeUndefined()
		expect(button(await section(LAID_UP), 'Take it now')).toBeUndefined()
	})

	/** Taking it now may have booked before the check-out failed; that booking belongs on the list. */
	it('reads the list again when the sheet is closed unsaved', async () => {
		const wrapper = await section()

		await button(wrapper, 'Take it now').vm.$emit('click')
		await wrapper.getComponent(HandoverSheet).vm.$emit('close')
		await flushPromises()

		expect(wrapper.findComponent(HandoverSheet).exists()).toBe(false)
		expect(listBookings).toHaveBeenCalledTimes(2)
	})

	it('does not offer Take it now while a booking holds the car or it is out', async () => {
		vi.mocked(listBookings).mockResolvedValue([booking({ uuid: 'b-6', starts_at: 1790938800, ends_at: 1790946000 })])
		expect(button(await section(), 'Take it now')).toBeUndefined()

		vi.mocked(listBookings).mockResolvedValue([OUT])
		expect(button(await section(), 'Take it now')).toBeUndefined()
	})

	/** Flags are computed on read and never refuse; the row says them in words. */
	it('says on the row what the handover is in question for', async () => {
		vi.mocked(listBookings).mockResolvedValue([booking({ uuid: 'b-7', starts_at: 1790582400, ends_at: 1790596800, state: 'returned', flags: ['odo_below', 'late'] })])

		const wrapper = await section()

		const text = row(wrapper, 'b-7').text()
		expect(text).toContain('Counter below the one before')
		expect(text).toContain('Returned after the end')
	})
})

describe('a booking becomes a trip', () => {
	const DRAFT = { started_at: 1790935200, started_at_off: 120, ended_at: 1790940000, ended_at_off: 120, start_odo: 52000, end_odo: 52140, purpose: null }
	const BACK = booking({ uuid: 'b-8', user_id: 'me', user_name: 'Me', starts_at: 1790935200, ends_at: 1790949600, state: 'returned', trip_draft: DRAFT, may: ['log_trip'] })

	/** The check-in is the prompt; the trip is the driver's to finish (docs/ui.md). */
	it('asks for the trip once the car is back', async () => {
		vi.mocked(listBookings).mockResolvedValue([{ ...BACK, state: 'out', trip_draft: null, may: ['check_in'] }])
		const wrapper = await section()

		await button(row(wrapper, 'b-8'), 'Return the car').vm.$emit('click')
		await wrapper.getComponent(HandoverSheet).vm.$emit('saved', BACK)

		expect(wrapper.emitted('log')).toEqual([[BACK]])
	})

	it('asks for nothing when a sheet saved a booking that is not back', async () => {
		const wrapper = await section()

		await button(row(wrapper, 'b-2'), 'Take the car').vm.$emit('click')
		await wrapper.getComponent(HandoverSheet).vm.$emit('saved', { ...COMING, state: 'out', may: ['check_in'] })

		expect(wrapper.emitted('log')).toBeUndefined()
	})

	it('offers Log the trip on a returned booking without one', async () => {
		vi.mocked(listBookings).mockResolvedValue([BACK, { ...PAST, may: [] }])
		const wrapper = await section()

		expect(button(row(wrapper, 'b-1'), 'Log the trip')).toBeUndefined()
		await button(row(wrapper, 'b-8'), 'Log the trip').vm.$emit('click')

		expect(wrapper.emitted('log')).toEqual([[BACK]])
	})

	it('opens the trip a booking became', async () => {
		const trip = { type: 'trip', occurred_at: 1790935200, trip: { uuid: 't-1' } }
		vi.mocked(readEntry).mockResolvedValue(/** @type {any} */ (trip))
		vi.mocked(listBookings).mockResolvedValue([{ ...BACK, trip_uuid: 't-1', trip_voided: false, trip_draft: null, may: ['open_trip'] }])
		const wrapper = await section()

		await button(row(wrapper, 'b-8'), 'Show the trip').vm.$emit('click')
		await flushPromises()

		expect(readEntry).toHaveBeenCalledWith('v-1', 'trip', 't-1')
		expect(wrapper.emitted('open')).toEqual([[trip]])
	})

	/** The trip opens by its own rule, as its timeline row does; to anyone else the row only says it is logged. */
	it('says the trip is logged to a reader who may not open it', async () => {
		vi.mocked(listBookings).mockResolvedValue([{ ...BACK, trip_uuid: 't-1', trip_voided: false, trip_draft: null, may: [] }])
		const wrapper = await section()

		expect(row(wrapper, 'b-8').text()).toContain('Trip logged')
		expect(button(row(wrapper, 'b-8'), 'Show the trip')).toBeUndefined()
	})

	it('says so when the trip cannot be read', async () => {
		vi.mocked(readEntry).mockRejectedValue(new Error('The server answered 404'))
		vi.mocked(listBookings).mockResolvedValue([{ ...BACK, trip_uuid: 't-1', trip_voided: false, trip_draft: null, may: ['open_trip'] }])
		const wrapper = await section()

		await button(row(wrapper, 'b-8'), 'Show the trip').vm.$emit('click')
		await flushPromises()

		expect(wrapper.getComponent(NcNoteCard).props('text')).toContain('404')
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	/** A voided trip stays tied: the booking was driven, whatever became of its log. */
	it('says the trip was voided, and offers none to open', async () => {
		vi.mocked(listBookings).mockResolvedValue([{ ...BACK, trip_uuid: 't-1', trip_voided: true, trip_draft: null, may: [] }])
		const wrapper = await section()

		expect(row(wrapper, 'b-8').text()).toContain('Trip voided')
		expect(row(wrapper, 'b-8').text()).not.toContain('Trip logged')
		expect(button(row(wrapper, 'b-8'), 'Show the trip')).toBeUndefined()
	})
})
