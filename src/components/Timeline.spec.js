/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { readTimeline } from '../services/api.js'
import Timeline from './Timeline.vue'
import TimelineRow from './TimelineRow.vue'

// The network is the api client's own seam (api.spec.js); what this component decides is which
// page to ask for and how the answer is laid out.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readTimeline: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_unit: 'km' }

/** Two rows in September 2026 and one in the August before it, as the server orders them. */
const SEPTEMBER = [
	{ type: 'trip', occurred_at: 1788391800, occurred_at_off: 120, trip: { uuid: 't-1', category: 'business', distance: 82, end_odo: null }, reading: null },
	{ type: 'odometer', occurred_at: 1788217200, occurred_at_off: 120, odometer: { uuid: 'r-1', value: 148320, flagged: false } },
]
const AUGUST = [
	{ type: 'trip', occurred_at: 1786788000, occurred_at_off: 120, trip: { uuid: 't-2', category: 'private', distance: 12, end_odo: null }, reading: null },
]

/**
 * The timeline, mounted and done with its first read. shallowMount, so the rows are counted rather
 * than re-read: what one of them says is TimelineRow's own question (TimelineRow.spec.js).
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted timeline
 */
async function timeline() {
	const wrapper = shallowMount(Timeline, {
		props: { vehicle: VEHICLE },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
 * @return {string[]} the month headers, in the order they appear
 */
function months(wrapper) {
	return wrapper.findAll('.timeline__month').map((one) => one.text())
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
 * @param {string} label - the chip, as it reads on screen
 * @return {Promise<void>} when the chip has been taken and the read it caused is through
 */
async function chip(wrapper, label) {
	const chosen = /** @type {any} */ (wrapper.findAllComponents(NcRadioGroupButton)
		.find((/** @type {any} */ one) => one.props('label') === label))
	await wrapper.findComponent(NcRadioGroup).vm.$emit('update:modelValue', chosen.props('value'))
	await flushPromises()
}

/**
 * The button under the rows: the way on to the next page for anyone whose browser never fires the
 * observer, and the retry when a page came back refused.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
 * @return {any} the button, or undefined when there is no next page
 */
function more(wrapper) {
	return wrapper.findAllComponents(NcButton).at(-1)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [...SEPTEMBER, ...AUGUST], next: null }))
})

describe('the timeline', () => {
	/** The newest rows of every kind, which is what the screen opens on (docs/ui.md). */
	it('reads the newest rows of every kind when it opens', async () => {
		const wrapper = await timeline()

		expect(readTimeline).toHaveBeenCalledWith('v-1', { type: '', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
	})

	/**
	 * The month is a sticky header, so it is stated once above the rows that belong to it
	 * (docs/ui.md) - and which month a row belongs to is the offset's answer, not the reader's
	 * clock's (docs/architecture.md#time). The words themselves are the locale's
	 * (src/utils/format.js).
	 */
	it('states each month once, above the rows under it', async () => {
		const wrapper = await timeline()

		const stated = months(wrapper)
		expect(stated).toHaveLength(2)
		expect(stated[0]).toContain('2026')
		expect(stated[0]).not.toBe(stated[1])
		expect(wrapper.findAll('.timeline__group').map((group) => group.findAllComponents(TimelineRow).length))
			.toEqual([2, 1])
	})

	/**
	 * A chip is a different question, so it is asked from the top: continuing under a cursor the
	 * previous chip handed out would start the narrower list halfway down
	 * (docs/architecture.md#the-timeline).
	 */
	it('asks again from the top when a chip narrows it', async () => {
		const wrapper = await timeline()
		expect(wrapper.findAllComponents(NcRadioGroupButton).map((one) => String(one.props('label'))))
			.toEqual(['All', 'Trips', 'Odometer'])
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		await chip(wrapper, 'Trips')

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: 'trip', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(1)
	})

	/**
	 * Fifty rows, then more on scroll (docs/ui.md). The cursor is the server's own word handed back
	 * and the rows that come with it go under the ones already there, newest first throughout.
	 */
	it('continues under the cursor the last page answered with', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		await more(wrapper).vm.$emit('click')
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: '', cursor: '1788217200:odometer:7' })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
		expect(months(wrapper)).toHaveLength(2)
	})

	/**
	 * A page that answers with no cursor is the last one, so nobody fetches an empty page to find
	 * that out (docs/architecture.md#the-timeline).
	 */
	it('stops offering more when the last page is in', async () => {
		const wrapper = await timeline()

		expect(wrapper.findAllComponents(NcButton)).toHaveLength(0)
	})

	/**
	 * A failed read is not a lost list: the rows already on screen stay, and the way on becomes the
	 * retry - the same answer a failed save gets (docs/ui.md).
	 */
	it('keeps what it has when a page comes back refused, and offers the retry', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockRejectedValue(new Error('The server answered 500'))

		await more(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(2)
		expect(more(wrapper).text()).toBe('Try again')
	})

	/** Empty states do the teaching (docs/ui.md), and a vehicle nobody has driven yet has one. */
	it('teaches rather than saying nothing', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [], next: null }))
		const wrapper = await timeline()

		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(true)
		expect(months(wrapper)).toHaveLength(0)
	})

	/**
	 * "More on scroll" is the sentinel below the last row coming into view. The button beside it is
	 * the way on without an observer and the retry when a page failed; the scroll is what a driver
	 * actually does.
	 */
	it('asks for the next page when the bottom is scrolled into view', async () => {
		/** @type {((entries: {isIntersecting: boolean}[]) => void)[]} */
		const seen = []
		vi.stubGlobal('IntersectionObserver', class {

			constructor(/** @type {any} */ callback) {
				seen.push(callback)
			}

			observe() {}
			unobserve() {}
			disconnect() {}

		})
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		// The observer the sentinel is under: the component builds one per sentinel, and the newest
		// is the one watching the bottom as the list now stands.
		const scrolledTo = /** @type {(entries: {isIntersecting: boolean}[]) => void} */ (seen.at(-1))
		scrolledTo([{ isIntersecting: true }])
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: '', cursor: '1788217200:odometer:7' })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
		vi.unstubAllGlobals()
	})

	/**
	 * The shell keeps one vehicle screen and swaps the vehicle under it (src/App.vue), so this
	 * component outlives the vehicle it was opened on. A list that stayed would show one vehicle's
	 * journeys under another's name, and the next page would ask for them under the new uuid.
	 */
	it('starts again when the screen is moved to another vehicle', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2', plate: 'B-ZZ 9' } })
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-2', { type: '', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(1)
	})

	/**
	 * The retry is the one click that has to work after a refusal: the scroll stops asking on its
	 * own - it would ask on every pixel and get the same answer - and a list with no way on is a
	 * list that ends at the failure.
	 */
	it('takes the retry after a refusal and carries on', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockRejectedValue(new Error('The server answered 500'))
		await more(wrapper).vm.$emit('click')
		await flushPromises()

		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))
		await more(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	/**
	 * A chip taken while the previous page is still in the air is still the question that was asked
	 * last, so the answer to the older one is dropped rather than rendered under the new chip.
	 */
	it('drops the answer to a question the reader has moved on from', async () => {
		/** @type {(page: any) => void} */
		let answerFirst = () => {}
		vi.mocked(readTimeline).mockReturnValueOnce(new Promise((resolve) => {
			answerFirst = resolve
		}))
		const wrapper = shallowMount(Timeline, {
			props: { vehicle: VEHICLE },
			global: { renderStubDefaultSlot: true },
		})

		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))
		await chip(wrapper, 'Trips')
		answerFirst({ rows: SEPTEMBER, next: null })
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: 'trip', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(1)
	})

	/**
	 * The screen reads the timeline back after the entry sheet writes to it: the row that was just
	 * entered is the one the driver is looking for, and it belongs at the top rather than after a
	 * reload of the page (src/views/VehicleView.vue).
	 */
	it('reads the whole list again when the screen says something was written', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))
		await more(wrapper).vm.$emit('click')
		await flushPromises()
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)

		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: null }))
		await wrapper.vm.reload()
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: '', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(2)
	})
})
