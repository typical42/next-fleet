/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import EntrySheet from '../components/EntrySheet.vue'
import KpiHeader from '../components/KpiHeader.vue'
import Timeline from '../components/Timeline.vue'
import VehicleDocuments from '../components/VehicleDocuments.vue'
import VehicleSheet from '../components/VehicleSheet.vue'
import VehicleSticker from '../components/VehicleSticker.vue'
import { readKpis, readTimeline } from '../services/api.js'
import VehicleView from './VehicleView.vue'

// The timeline is mounted for real below, so its one call out is stubbed here. What it does with
// the answer is its own question (src/components/Timeline.spec.js).
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readTimeline: vi.fn(),
	readKpis: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }

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
		global: { renderStubDefaultSlot: true, stubs: { Timeline: false, KpiHeader: false } },
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

	/** The section reads the papers once, and the timeline carries the linked ones as paperclips. */
	it('hands the timeline the papers the documents section listed', async () => {
		const wrapper = screen()
		const papers = [{ uuid: 'd-1', kind: 'receipt', linked_type: 'energy', linked_uuid: 'e-1' }]

		await wrapper.findComponent(VehicleDocuments).vm.$emit('listed', papers)

		expect(/** @type {any} */ (wrapper.findComponent(Timeline)).props('papers')).toEqual(papers)
	})
})
