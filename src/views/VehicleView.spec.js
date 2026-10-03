/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h } from 'vue'

import DueBanner from '../components/DueBanner.vue'
import EntrySheet from '../components/EntrySheet.vue'
import ImportSheet from '../components/ImportSheet.vue'
import KpiHeader from '../components/KpiHeader.vue'
import LeaveVehicle from '../components/LeaveVehicle.vue'
import Timeline from '../components/Timeline.vue'
import VehicleBookings from '../components/VehicleBookings.vue'
import VehicleDocuments from '../components/VehicleDocuments.vue'
import VehicleSheet from '../components/VehicleSheet.vue'
import VehicleSticker from '../components/VehicleSticker.vue'
import { getVehicle, readKpis, readTimeline } from '../services/api.js'
import VehicleView from './VehicleView.vue'

// The timeline is mounted for real below, so its one call out is stubbed here. What it does with
// the answer is its own question (src/components/Timeline.spec.js).
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readTimeline: vi.fn(),
	readKpis: vi.fn(),
	getVehicle: vi.fn(),
}))

const OWNER = ['view', 'log', 'edit', 'delete', 'own']
const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active', may: OWNER }

const reload = vi.fn()
/** The Bookings section, standing in with the one thing the screen calls on it. */
const Bookings = defineComponent({
	name: 'VehicleBookings',
	props: { vehicle: { type: Object, required: true }, papers: { type: Array, default: () => [] } },
	emits: ['log', 'open', 'changed'],
	setup(_, { expose }) {
		expose({ reload })
		return () => h('div')
	},
})

/**
 * @param {object} [more] - props beyond the vehicle
 * @return {import('@vue/test-utils').VueWrapper} the screen, mounted on one vehicle
 */
function screen(more = {}) {
	// A stub renders no slot of its own, and a button says what it is in its slot - so the two
	// buttons of this screen would be indistinguishable without this. The timeline and the header
	// are left unstubbed: what this screen has to get right is that they read again after a write,
	// and a stub has no reading to do.
	return shallowMount(VehicleView, {
		props: { vehicle: VEHICLE, ...more },
		global: { renderStubDefaultSlot: true, stubs: { Timeline: false, KpiHeader: false, VehicleBookings: Bookings } },
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [], next: null }))
	vi.mocked(readKpis).mockReturnValue(new Promise(() => {}))
})

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when the screen has none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the vehicle sheet, whether the screen is showing one or not
 */
function sheet(wrapper) {
	return wrapper.findComponent(VehicleSheet)
}

/**
 * Types a key at the page rather than at the screen: the shortcut listens on the window, and
 * where the keystroke was aimed is what decides whether it counts.
 *
 * @param {EventTarget} at - what the keystroke is aimed at
 * @param {string} key - the key pressed
 */
function press(at, key) {
	at.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
}

describe('the vehicle screen', () => {
	/**
	 * One primary button per screen, and on this one it is the entry - editing a vehicle is
	 * something people do twice a year (docs/ui.md).
	 */
	it('offers the entry first and the edit beside it', () => {
		const wrapper = screen()

		// First in the markup as well as first in emphasis: reading, tab and wrapping order are
		// the same order.
		expect(wrapper.findAllComponents(NcButton).map((one) => one.text()))
			.toEqual(['New entry', 'Costs', 'Edit vehicle'])
		expect(button(wrapper, 'New entry').props('variant')).toBe('primary')
		expect(button(wrapper, 'Edit vehicle').props('variant')).not.toBe('primary')
	})

	/** The shell swaps the screen (src/App.vue); this one only asks for it. */
	it('asks for the vehicle\'s costs', async () => {
		const wrapper = screen()

		await button(wrapper, 'Costs').vm.$emit('click')

		expect(wrapper.emitted('costs')).toHaveLength(1)
	})

	it('edits the vehicle it is showing', async () => {
		const wrapper = screen()
		expect(sheet(wrapper).exists()).toBe(false)

		await button(wrapper, 'Edit vehicle').vm.$emit('click')

		// Equal rather than identical: a prop reaches the child through Vue's reactive proxy.
		expect(sheet(wrapper).props('vehicle')).toEqual(VEHICLE)
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})

	/**
	 * `n` is the primary action of the screen in view (docs/ui.md), and on this screen that is
	 * the entry - not the edit, which people need twice a year.
	 */
	it('opens the entry sheet when n is pressed', async () => {
		const wrapper = screen()

		press(document.body, 'n')
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent(EntrySheet).exists()).toBe(true)
		expect(sheet(wrapper).exists()).toBe(false)
	})

	it('offers the QR sticker of the vehicle it is on', () => {
		expect(/** @type {any} */ (screen().findComponent(VehicleSticker)).props('vehicle')).toEqual(VEHICLE)
	})

	/** The QR sticker's link lands here (docs/ui.md, "The QR shortcut"). */
	/** What it says, and whether it says anything, is its own question (LeaveVehicle.spec.js). */
	it('offers leaving the vehicle it is on', () => {
		expect(/** @type {any} */ (screen().findComponent(LeaveVehicle)).props('vehicle')).toEqual(VEHICLE)
	})

	it('opens with the entry sheet up when the sticker asked for it', () => {
		expect(screen({ enter: true }).findComponent(EntrySheet).exists()).toBe(true)
		expect(screen().findComponent(EntrySheet).exists()).toBe(false)
	})

	/**
	 * A letter is also a letter somebody is typing. The sheet this screen opens is full of
	 * fields, and `n` in one of them is an `n`, not a shortcut.
	 */
	it('leaves n alone when it is typed into a field', async () => {
		const wrapper = screen()
		const field = document.createElement('input')
		document.body.appendChild(field)

		press(field, 'n')
		await wrapper.vm.$nextTick()

		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
		field.remove()
	})

	/**
	 * One timeline per vehicle, and it is this screen's middle (docs/ui.md) - the vehicle it is
	 * showing, not the one that happens to be first in the fleet.
	 */
	it('shows the timeline of the vehicle it is on', async () => {
		const wrapper = screen()
		await flushPromises()

		expect(/** @type {any} */ (wrapper.findComponent(Timeline)).props('vehicle')).toEqual(VEHICLE)
		expect(readTimeline).toHaveBeenCalledWith('v-1', { type: '', cursor: null })
	})

	/**
	 * The row that was just entered is the one the driver is looking for, so the list is read back
	 * rather than left a page behind. A sheet that was cancelled wrote nothing and costs no read.
	 */
	it('reads the timeline back once an entry is written, and not when one is cancelled', async () => {
		const wrapper = screen()
		await flushPromises()

		press(document.body, 'n')
		await wrapper.vm.$nextTick()
		await wrapper.findComponent(EntrySheet).vm.$emit('close')
		await flushPromises()
		expect(readTimeline).toHaveBeenCalledTimes(1)

		press(document.body, 'n')
		await wrapper.vm.$nextTick()
		await wrapper.findComponent(EntrySheet).vm.$emit('saved')
		await flushPromises()

		expect(readTimeline).toHaveBeenCalledTimes(2)
	})

	/** A tapped row opens the entry sheet on that Entry, and its save reads the list back too. */
	it('opens the entry sheet on a row the timeline hands up', async () => {
		const row = { type: 'odometer', occurred_at: 1788217200, occurred_at_off: 120, odometer: { uuid: 'r-1', value: 148320 } }
		const wrapper = screen()
		await flushPromises()

		await wrapper.findComponent(Timeline).vm.$emit('open', row)

		expect(/** @type {any} */ (wrapper.findComponent(EntrySheet)).props('entry')).toEqual(row)
		await wrapper.findComponent(EntrySheet).vm.$emit('saved')
		await wrapper.findComponent(EntrySheet).vm.$emit('close')
		await flushPromises()
		expect(readTimeline).toHaveBeenCalledTimes(2)
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})

	/** The header states the figures of the vehicle on screen, and a write moves them. */
	it('reads the header figures back once an entry is written', async () => {
		const wrapper = screen()
		await flushPromises()
		expect(/** @type {any} */ (wrapper.findComponent(KpiHeader)).props('vehicle')).toEqual(VEHICLE)
		expect(readKpis).toHaveBeenCalledTimes(2)

		press(document.body, 'n')
		await wrapper.vm.$nextTick()
		await wrapper.findComponent(EntrySheet).vm.$emit('saved')
		await flushPromises()

		expect(readKpis).toHaveBeenCalledTimes(4)
	})

	/** A save is done with, so the sheet goes; the screen already reads the store for the rest. */
	it('closes the sheet once the vehicle is saved', async () => {
		const wrapper = screen()

		await button(wrapper, 'Edit vehicle').vm.$emit('click')
		await sheet(wrapper).vm.$emit('saved', VEHICLE)

		expect(sheet(wrapper).exists()).toBe(false)
	})

	/**
	 * The import starts in the vehicle sheet and takes its place: two dialogs stacked would be
	 * two ways out (docs/ui.md, "Importing"). Its entries land in the timeline and the header.
	 */
	it('swaps the vehicle sheet for the import, and reads back what it wrote', async () => {
		const wrapper = screen()
		await flushPromises()
		await button(wrapper, 'Edit vehicle').vm.$emit('click')

		await sheet(wrapper).vm.$emit('import')

		expect(sheet(wrapper).exists()).toBe(false)
		const importing = wrapper.findComponent(ImportSheet)
		expect(/** @type {any} */ (importing).props('vehicle')).toEqual(VEHICLE)
		vi.mocked(readTimeline).mockClear()

		await importing.vm.$emit('imported')
		await flushPromises()

		expect(wrapper.findComponent(ImportSheet).exists()).toBe(false)
		expect(readTimeline).toHaveBeenCalled()
	})

	/** A driver logs but does not edit the vehicle (CONTEXT.md, Vehicle Access). */
	it('offers a driver the entry and the sticker, not the edit', async () => {
		const wrapper = screen({ vehicle: { ...VEHICLE, may: ['view', 'log'] } })

		expect(wrapper.findAllComponents(NcButton).map((one) => one.text())).toEqual(['New entry', 'Costs'])
		expect(wrapper.findComponent(VehicleSticker).exists()).toBe(true)
	})

	/**
	 * A viewer adds nothing, so neither the button, the key nor the sticker's link opens the entry
	 * sheet, and no sticker is offered that would lead somebody to one.
	 */
	it('offers a viewer neither the entry, the sticker nor the edit', async () => {
		const viewer = { vehicle: { ...VEHICLE, may: ['view'] } }
		const wrapper = screen(viewer)

		expect(wrapper.findAllComponents(NcButton).map((one) => one.text())).toEqual(['Costs'])
		expect(wrapper.findComponent(VehicleSticker).exists()).toBe(false)
		press(document.body, 'n')
		await wrapper.vm.$nextTick()
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
		expect(screen({ ...viewer, enter: true }).findComponent(EntrySheet).exists()).toBe(false)
	})

	/**
	 * The section reads the papers once; the timeline carries the linked ones as paperclips, and the
	 * bookings their handover photos.
	 */
	it('hands the timeline and the bookings the papers the documents section listed', async () => {
		const wrapper = screen()
		const papers = [{ uuid: 'd-1', kind: 'receipt', linked_type: 'energy', linked_uuid: 'e-1' }]

		await wrapper.findComponent(VehicleDocuments).vm.$emit('listed', papers)

		expect(/** @type {any} */ (wrapper.findComponent(Timeline)).props('papers')).toEqual(papers)
		expect(/** @type {any} */ (wrapper.findComponent(VehicleBookings)).props('papers')).toEqual(papers)
	})

	/** What is coming due, then who has the car when, then the papers (docs/ui.md). */
	it('shows the vehicle\'s bookings between the due banner and the documents', () => {
		const wrapper = screen()

		const order = wrapper.findAllComponents(DueBanner).concat(
			wrapper.findAllComponents(VehicleBookings),
			wrapper.findAllComponents(VehicleDocuments),
		).map((one) => one.element)
		expect(order).toHaveLength(3)
		const following = (/** @type {Element} */ a, /** @type {Element} */ b) => Boolean(a.compareDocumentPosition(b) & Node.DOCUMENT_POSITION_FOLLOWING)
		expect(following(order[0], order[1]) && following(order[1], order[2])).toBe(true)
		expect(/** @type {any} */ (wrapper.findComponent(VehicleBookings)).props('vehicle')).toEqual(VEHICLE)
	})
})

describe('who has the car', () => {
	/** 2026-10-02 16:00 UTC: Friday 18:00 at +02:00. */
	const FRIDAY_SIX_PM = 1790956800

	afterEach(() => {
		vi.useRealTimers()
	})

	/** The header says what the overview row says, and the reader's own next booking. */
	it('says who has the car until when, and the reader\'s own next booking', () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-10-02T12:00:00Z'))
		const vehicle = {
			...VEHICLE,
			out_with: { user_id: 'anna', user_name: 'Anna Adler', ends_at: FRIDAY_SIX_PM, ends_at_off: 120 },
			my_next_booking: { uuid: 'b-1', starts_at: FRIDAY_SIX_PM + 86400, starts_at_off: 120, ends_at: FRIDAY_SIX_PM + 86400 + 7200, ends_at_off: 120 },
		}
		const wrapper = screen({ vehicle })

		// The test run's locale is American English; Intl sets thin spaces around the dash and a
		// narrow no-break space before PM.
		expect(wrapper.find('.vehicle__holder').text()).toBe('With Anna Adler until Fri, 10/02, 06:00 PM')
		expect(wrapper.find('.vehicle__next').text()).toBe('Your booking: Sat, 10/03, 6:00 – 8:00 PM')
	})

	/** Overdue in words, as on the overview. */
	it('says a car still out past its end is overdue', () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date('2026-10-03T08:00:00Z'))
		const wrapper = screen({ vehicle: { ...VEHICLE, out_with: { user_id: 'anna', user_name: 'Anna Adler', ends_at: FRIDAY_SIX_PM, ends_at_off: 120 } } })

		expect(wrapper.find('.vehicle__holder').text()).toBe('With Anna Adler, overdue since Fri, 10/02, 06:00 PM')
		expect(wrapper.find('.vehicle__holder').classes()).toContain('vehicle__holder--overdue')
	})

	it('says nothing of a car in the yard with no booking of the reader\'s', () => {
		const wrapper = screen({ vehicle: { ...VEHICLE, out_with: null, my_next_booking: null } })

		expect(wrapper.find('.vehicle__holder').exists()).toBe(false)
		expect(wrapper.find('.vehicle__next').exists()).toBe(false)
	})

	/** A booking made, taken or given back changes the header, which reads the vehicle. */
	it('reads the vehicle back once the bookings section wrote', async () => {
		vi.mocked(getVehicle).mockResolvedValue(/** @type {any} */ ({ ...VEHICLE, out_with: null }))
		const wrapper = screen()

		await wrapper.findComponent(Bookings).vm.$emit('changed')
		await flushPromises()

		expect(getVehicle).toHaveBeenCalledWith('v-1')
	})
})

describe('a booking becomes a trip', () => {
	const BACK = { uuid: 'b-8', state: 'returned', trip_draft: { started_at: 1790935200 }, may: ['log_trip'] }

	/** Once saved, the row links the trip, so the section reads its list again. */
	it('opens the entry sheet on the trip of a returned booking, and reads the bookings back once it is saved', async () => {
		const wrapper = screen()
		await flushPromises()

		await wrapper.findComponent(Bookings).vm.$emit('log', BACK)
		const sheet = /** @type {any} */ (wrapper.getComponent(EntrySheet))
		expect(sheet.props('booking')).toEqual(BACK)
		await sheet.vm.$emit('saved')
		await sheet.vm.$emit('close')
		await flushPromises()

		expect(reload).toHaveBeenCalledTimes(1)
		expect(readTimeline).toHaveBeenCalledTimes(2)
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})

	/** Voiding the trip there, or anywhere, changes what its booking's row says. */
	it('opens the entry sheet on the trip a booking became, and reads the bookings back after any write', async () => {
		const row = { type: 'trip', occurred_at: 1790935200, trip: { uuid: 't-1' } }
		const wrapper = screen()

		await wrapper.findComponent(Bookings).vm.$emit('open', row)
		const sheet = /** @type {any} */ (wrapper.getComponent(EntrySheet))
		expect(sheet.props('entry')).toEqual(row)
		await sheet.vm.$emit('saved')

		expect(reload).toHaveBeenCalledTimes(1)
	})

	/** The shell swaps the vehicle under the screen; a sheet on the last one's booking would log onto this one. */
	it('closes the booking\'s trip sheet when the vehicle changes', async () => {
		const wrapper = screen()

		await wrapper.findComponent(Bookings).vm.$emit('log', BACK)
		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })

		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})
})
