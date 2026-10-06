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
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { isProxy } from 'vue'

import { closeGap, ConflictError, deleteEntry, getVehicle, readGaps, readTimeline, RefusedError, resetReading } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import { fullMoment } from '../utils/format.js'
import Timeline from './Timeline.vue'
import TimelineRow from './TimelineRow.vue'

// The network is the api client's own seam (api.spec.js); what this component decides is which
// page to ask for and how the answer is laid out.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	readTimeline: vi.fn(),
	readGaps: vi.fn(),
	closeGap: vi.fn(),
	deleteEntry: vi.fn(),
	getVehicle: vi.fn(),
	resetReading: vi.fn(),
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
 * Two Gaps the September rows opened, one of them bracketed from August: a Gap belongs to the month
 * of the trip whose claim opened it.
 */
const GAPS = [
	{ trip: 't-2', distance: 40, from_at: 1786780000, from_at_off: 120, to_at: 1788217200, to_at_off: 120 },
	{ trip: 't-1', distance: 1250, from_at: 1788220000, from_at_off: 120, to_at: 1788391800, to_at_off: 120 },
]

/**
 * The timeline, mounted and done with its first read. Shallow: what a row says is
 * TimelineRow.spec.js's question.
 *
 * @param {object} [vehicle] - the vehicle it is opened on
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted timeline
 */
async function timeline(vehicle = VEHICLE) {
	const wrapper = shallowMount(Timeline, {
		props: { vehicle },
		global: {
			renderStubDefaultSlot: true,
			// The question's buttons are in the dialog's own slot, which a plain stub drops.
			stubs: {
				NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' },
				NcEmptyContent: { template: '<div class="empty"><slot name="action" /></div>' },
			},
		},
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
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [...SEPTEMBER, ...AUGUST], next: null }))
	vi.mocked(readGaps).mockResolvedValue(GAPS)
})

describe('a row opened for editing', () => {
	/** The sheet is the screen's to open (src/views/VehicleView.vue); the timeline says which row. */
	it('hands the tapped row up', async () => {
		const wrapper = await timeline()

		await wrapper.findAllComponents(TimelineRow)[1].vm.$emit('open', SEPTEMBER[1])

		expect(wrapper.emitted('open')).toEqual([[SEPTEMBER[1]]])
	})

	it('reads itself again when an Entry is brought back', async () => {
		await timeline()
		expect(readTimeline).toHaveBeenCalledTimes(1)

		useVehiclesStore().restored++
		await flushPromises()

		expect(readTimeline).toHaveBeenCalledTimes(2)
	})
})

describe('the papers', () => {
	/** Documents are not rows of their own: a registration has no date to sort by. */
	it('hands each row the documents linked to its entry', async () => {
		const fill = { type: 'energy', occurred_at: 1788300000, occurred_at_off: 120, energy: { uuid: 'e-1', energy: 'diesel', amount: 40000 } }
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [SEPTEMBER[0], fill], next: null }))
		const receipt = { uuid: 'd-1', kind: 'receipt', linked_type: 'energy', linked_uuid: 'e-1', name: 'r.pdf' }
		const registration = { uuid: 'd-2', kind: 'registration', linked_type: null, linked_uuid: null, name: 'z.pdf' }

		const wrapper = await timeline()
		await wrapper.setProps({ papers: [receipt, registration] })

		const rows = /** @type {any[]} */ (wrapper.findAllComponents(TimelineRow))
		expect(rows).toHaveLength(2)
		expect(rows[0].props('papers')).toEqual([])
		expect(rows[1].props('papers')).toEqual([receipt])
	})
})

describe('the month header under Logbook Mode', () => {
	const LOGBOOK = { ...VEHICLE, logbook_mode: true }

	/** A month's header states all its Gaps at once, however far down the rows are read. */
	it('states the unaccounted kilometres of the month the Gaps were opened in', async () => {
		const wrapper = await timeline(LOGBOOK)

		expect(readGaps).toHaveBeenCalledWith('v-1')
		const [september, august] = months(wrapper)
		expect(september).toContain('1,290 km unaccounted for')
		expect(august).not.toContain('unaccounted')
	})

	/** A vehicle that keeps no logbook is asked nothing, so nothing is read for it either. */
	it('says nothing of Gaps off the mode', async () => {
		const wrapper = await timeline()

		expect(readGaps).not.toHaveBeenCalled()
		expect(months(wrapper).join(' ')).not.toContain('unaccounted')
	})

	it('refuses the list rather than state a month without its Gaps, and retries both', async () => {
		vi.mocked(readGaps).mockRejectedValueOnce(new Error('The server answered 500'))
		const wrapper = await timeline(LOGBOOK)
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(months(wrapper)).toHaveLength(0)

		await more(wrapper).vm.$emit('click')
		await flushPromises()

		expect(readGaps).toHaveBeenCalledTimes(2)
		expect(months(wrapper)[0]).toContain('1,290 km unaccounted for')
	})

	it('reads the Gaps when the mode is switched on under it', async () => {
		const wrapper = await timeline()

		await wrapper.setProps({ vehicle: LOGBOOK })
		await flushPromises()

		expect(months(wrapper)[0]).toContain('1,290 km unaccounted for')
	})
})

describe('closing a Gap', () => {
	const LOGBOOK = { ...VEHICLE, logbook_mode: true }

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
	 * @param {string} label - what the button says
	 * @return {any} the button
	 */
	function button(wrapper, label) {
		return wrapper.findAllComponents(NcButton).find((one) => one.text() === label)
	}

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
	 * @param {string} uuid - the trip whose row offers it
	 * @return {Promise<void>} when the question is on screen
	 */
	async function offer(wrapper, uuid) {
		const opened = /** @type {any} */ (wrapper.findAllComponents(TimelineRow)
			.find((/** @type {any} */ one) => one.props('entry').trip?.uuid === uuid))
		await opened.vm.$emit('closeGap', opened.props('gap'))
		await flushPromises()
	}

	it('hands each trip the Gap its claim opened', async () => {
		const wrapper = await timeline(LOGBOOK)

		const gaps = wrapper.findAllComponents(TimelineRow).map((/** @type {any} */ one) => one.props('gap'))
		expect(gaps).toEqual([GAPS[1], null, GAPS[0]])
	})

	it('asks first, naming the kilometres and the two moments', async () => {
		const wrapper = await timeline(LOGBOOK)

		await offer(wrapper, 't-1')

		const question = wrapper.get('.dialog').text()
		expect(question).toContain('1,250 km')
		expect(question).toContain(fullMoment(GAPS[1].from_at, GAPS[1].from_at_off))
		expect(question).toContain(fullMoment(GAPS[1].to_at, GAPS[1].to_at_off))
		expect(closeGap).not.toHaveBeenCalled()
	})

	it('closes nothing when the question is cancelled', async () => {
		const wrapper = await timeline(LOGBOOK)
		await offer(wrapper, 't-1')

		await button(wrapper, 'Cancel').vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(closeGap).not.toHaveBeenCalled()
	})

	it('closes the confirmed Gap and reads the timeline again', async () => {
		vi.mocked(closeGap).mockResolvedValue(/** @type {any} */ ({ uuid: 't-9', reconciled: true }))
		const wrapper = await timeline(LOGBOOK)
		await offer(wrapper, 't-1')

		await button(wrapper, 'Record private trip').vm.$emit('click')
		await flushPromises()

		expect(closeGap).toHaveBeenCalledWith('v-1', GAPS[1])
		expect(readTimeline).toHaveBeenCalledTimes(2)
		expect(readGaps).toHaveBeenCalledTimes(2)
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	it('keeps the question open when the server refuses', async () => {
		vi.mocked(closeGap).mockRejectedValueOnce(new Error('The server answered 500'))
		const wrapper = await timeline(LOGBOOK)
		await offer(wrapper, 't-1')

		await button(wrapper, 'Record private trip').vm.$emit('click')
		await flushPromises()

		expect(wrapper.get('.dialog').findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(button(wrapper, 'Try again').props('disabled')).toBe(false)
	})

	it('offers no second try at a Gap that has moved, and reads the list again', async () => {
		vi.mocked(closeGap).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		const wrapper = await timeline(LOGBOOK)
		await offer(wrapper, 't-1')

		await button(wrapper, 'Record private trip').vm.$emit('click')
		await flushPromises()

		expect(wrapper.get('.dialog').findComponent(NcNoteCard).props('text')).toContain('changed')
		expect(button(wrapper, 'Record private trip').props('disabled')).toBe(true)
		expect(readGaps).toHaveBeenCalledTimes(2)
	})
})

describe('voiding an overtaken reconciliation', () => {
	/** @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline */
	async function voidFirst(wrapper) {
		await wrapper.findAllComponents(TimelineRow)[0].vm.$emit('void', SEPTEMBER[0])
		await flushPromises()
	}

	/** The void holds its way back like any other (src/components/UndoToast.vue). */
	it('voids the trip, offers the undo and reads the list again', async () => {
		vi.mocked(deleteEntry).mockResolvedValue(/** @type {any} */ ({ uuid: 't-1', updated_at: 1788400000 }))
		vi.mocked(getVehicle).mockResolvedValue(/** @type {any} */ (VEHICLE))
		const wrapper = await timeline()

		await voidFirst(wrapper)

		expect(deleteEntry).toHaveBeenCalledWith('v-1', 'trip', SEPTEMBER[0].trip)
		expect(useVehiclesStore().struck?.entry).toEqual({ uuid: 't-1', updated_at: 1788400000 })
		expect(readTimeline).toHaveBeenCalledTimes(2)
	})

	it('says why when the server refuses, and reads the list again', async () => {
		vi.mocked(deleteEntry).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		const wrapper = await timeline()

		await voidFirst(wrapper)

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This trip has changed since it was read. The timeline shows it as it is now.')
		expect(readTimeline).toHaveBeenCalledTimes(2)
	})
})

describe('answering a reading in question', () => {
	const LOWER = { uuid: 'r-1', value: 30, origin: 'observed', flagged: true, updated_at: 1788217300 }

	/** @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline */
	async function replaced(wrapper) {
		await wrapper.findAllComponents(TimelineRow)[1].vm.$emit('reset', LOWER)
		await flushPromises()
	}

	it('answers that the counter was replaced and reads the list again', async () => {
		vi.mocked(resetReading).mockResolvedValue(/** @type {any} */ ({ ...LOWER, kind: 'reset', flagged: false }))
		const wrapper = await timeline()

		await replaced(wrapper)

		expect(resetReading).toHaveBeenCalledWith('v-1', LOWER)
		expect(readTimeline).toHaveBeenCalledTimes(2)
	})

	it('says why when the server refuses, and reads the list again', async () => {
		vi.mocked(resetReading).mockRejectedValueOnce(new ConflictError('Changed since you read it'))
		const wrapper = await timeline()

		await replaced(wrapper)

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This reading has changed since it was read. The timeline shows it as it is now.')
		expect(readTimeline).toHaveBeenCalledTimes(2)
	})

	/** Another entry since may have settled the question: said in words, not the server's. */
	it('says so when the reading is no longer in question', async () => {
		vi.mocked(resetReading).mockRejectedValueOnce(new RefusedError('only a reading in question…', 'not_in_question'))
		const wrapper = await timeline()

		await replaced(wrapper)

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This reading is no longer in question. The timeline shows it as it is now.')
	})
})

describe('the timeline', () => {
	it('reads the newest rows of every kind when it opens', async () => {
		const wrapper = await timeline()

		expect(readTimeline).toHaveBeenCalledWith('v-1', { type: '', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
	})

	/**
	 * A row's month is the offset's answer, not the reader's clock's (docs/architecture.md#time).
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

	it('asks again from the top when a chip narrows it', async () => {
		const wrapper = await timeline()
		expect(wrapper.findAllComponents(NcRadioGroupButton).map((one) => String(one.props('label'))))
			.toEqual(['All', 'Trips', 'Odometer', 'Energy', 'Maintenance', 'Expenses'])
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		await chip(wrapper, 'Trips')

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: 'trip', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(1)
	})

	it('asks for the expenses under Expenses, and lists the cost kinds each as a row', async () => {
		const wrapper = await timeline()
		const COSTS = [
			{ type: 'expense', occurred_at: 1788391800, occurred_at_off: 120, expense: { uuid: 'x-1', amount: 1200 } },
			{ type: 'energy', occurred_at: 1788391800, occurred_at_off: 120, energy: { uuid: 'x-1', energy: 'diesel', amount: 40000 }, flags: [], readings: [] },
			{ type: 'maintenance', occurred_at: 1788391800, occurred_at_off: 120, maintenance: { uuid: 'x-2', title: 'Oil' }, readings: [] },
		]
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: COSTS, next: null }))

		await chip(wrapper, 'Expenses')

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: 'expense', cursor: null })
		// Two kinds may share a uuid's shape; the key keeps both rows.
		expect(wrapper.findAllComponents(TimelineRow).map((/** @type {any} */ one) => one.props('entry').type))
			.toEqual(['expense', 'energy', 'maintenance'])
	})

	/** Fifty rows, then more on scroll (docs/ui.md), newest first throughout. */
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

	/** No cursor is the last page; no empty page is fetched (docs/architecture.md#the-timeline). */
	it('stops offering more when the last page is in', async () => {
		const wrapper = await timeline()

		expect(wrapper.findAllComponents(NcButton)).toHaveLength(0)
	})

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

	/** Empty states do the teaching (docs/ui.md). */
	it('teaches rather than saying nothing', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [], next: null }))
		const wrapper = await timeline()

		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(true)
		expect(months(wrapper)).toHaveLength(0)
	})

	it.each([
		[['view', 'log', 'edit'], ['New entry', 'Import from a file…']],
		[['view', 'log'], ['New entry']],
		[['view'], []],
	])('offers a reader who may %j a first entry: %j', async (may, offered) => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: [], next: null }))
		const wrapper = await timeline({ ...VEHICLE, may })
		const empty = wrapper.findComponent(NcEmptyContent)

		expect(empty.findAllComponents(NcButton).map((one) => one.text())).toEqual(offered)
		if (offered.length > 0) {
			await empty.findAllComponents(NcButton).at(0)?.vm.$emit('click')
			expect(wrapper.emitted('new')).toHaveLength(1)
		}
		if (offered.length > 1) {
			await empty.findAllComponents(NcButton).at(1)?.vm.$emit('click')
			expect(wrapper.emitted('import')).toHaveLength(1)
		}
	})

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

		// One observer per sentinel; the newest watches the bottom as the list now stands.
		const scrolledTo = /** @type {(entries: {isIntersecting: boolean}[]) => void} */ (seen.at(-1))
		scrolledTo([{ isIntersecting: true }])
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: '', cursor: '1788217200:odometer:7' })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(3)
		vi.unstubAllGlobals()
	})

	/** A list that stayed would show one vehicle's journeys under another's name. */
	it('starts again when the screen is moved to another vehicle', async () => {
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: SEPTEMBER, next: '1788217200:odometer:7' }))
		const wrapper = await timeline()
		vi.mocked(readTimeline).mockResolvedValue(/** @type {any} */ ({ rows: AUGUST, next: null }))

		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2', plate: 'B-ZZ 9' } })
		await flushPromises()

		expect(readTimeline).toHaveBeenLastCalledWith('v-2', { type: '', cursor: null })
		expect(wrapper.findAllComponents(TimelineRow)).toHaveLength(1)
	})

	/** The scroll stops asking after a refusal, so the retry is the only way on. */
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

	/** Called by the screen after the entry sheet writes (src/views/VehicleView.vue). */
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

describe('a long history', () => {
	/**
	 * Page `n` of a history that goes on: two Readings a day apart, under the cursor of the next.
	 *
	 * @param {number} n - which page, from 1
	 * @return {{rows: object[], next: string}} the page as the server answers it
	 */
	function nth(n) {
		const rows = [0, 1].map((i) => {
			const at = 1788391800 - ((n - 1) * 2 + i) * 86400

			return { type: 'odometer', occurred_at: at, occurred_at_off: 120, odometer: { uuid: `r-${n}-${i}`, value: 150000 - n * 10 - i, flagged: false } }
		})

		return { rows, next: `cursor-${n}` }
	}

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
	 * @return {string[]} the uuid of each row on screen, top to bottom
	 */
	function shown(wrapper) {
		return wrapper.findAllComponents(TimelineRow).map((/** @type {any} */ one) => one.props('entry').odometer.uuid)
	}

	/**
	 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted timeline
	 * @return {any} the button above the rows that brings newer ones back, or undefined
	 */
	function newer(wrapper) {
		return wrapper.findAllComponents(NcButton).find((/** @type {any} */ one) => one.text() === 'Show newer entries')
	}

	/**
	 * The timeline scrolled through `count` pages, each read in turn.
	 *
	 * @param {number} count - how many pages
	 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted timeline
	 */
	async function scrolled(count) {
		let n = 0
		vi.mocked(readTimeline).mockImplementation(async () => /** @type {any} */ (nth(++n)))
		const wrapper = await timeline()
		for (let i = 1; i < count; i++) {
			await more(wrapper).vm.$emit('click')
			await flushPromises()
		}

		return wrapper
	}

	it('keeps at most four pages on screen, the newest read last', async () => {
		const wrapper = await scrolled(6)

		expect(shown(wrapper)).toEqual(['r-3-0', 'r-3-1', 'r-4-0', 'r-4-1', 'r-5-0', 'r-5-1', 'r-6-0', 'r-6-1'])
		expect(newer(wrapper)).toBeDefined()
	})

	it('brings newer pages back without asking the server again', async () => {
		const wrapper = await scrolled(6)

		await newer(wrapper).vm.$emit('click')
		await newer(wrapper).vm.$emit('click')

		expect(readTimeline).toHaveBeenCalledTimes(6)
		expect(shown(wrapper)[0]).toBe('r-1-0')
		expect(newer(wrapper)).toBeUndefined()
	})

	it('walks back down through pages it has before asking for the next', async () => {
		const wrapper = await scrolled(6)
		await newer(wrapper).vm.$emit('click')

		await more(wrapper).vm.$emit('click')
		await flushPromises()
		expect(readTimeline).toHaveBeenCalledTimes(6)
		expect(shown(wrapper).at(-1)).toBe('r-6-1')

		await more(wrapper).vm.$emit('click')
		await flushPromises()
		expect(readTimeline).toHaveBeenLastCalledWith('v-1', { type: '', cursor: 'cursor-6' })
		expect(shown(wrapper).at(-1)).toBe('r-7-1')
	})

	/** A page that lands after the reader went back up goes below; it does not pull them down. */
	it('leaves the window where the reader moved it while a page was on its way', async () => {
		const wrapper = await scrolled(5)
		/** @type {(page: any) => void} */
		let answer = () => {}
		vi.mocked(readTimeline).mockImplementation(() => new Promise((resolve) => { answer = resolve }))
		const asking = more(wrapper).vm.$emit('click')
		await flushPromises()

		await newer(wrapper).vm.$emit('click')
		answer(nth(6))
		await asking
		await flushPromises()

		expect(shown(wrapper)[0]).toBe('r-1-0')
		expect(more(wrapper).text()).toBe('Load more')
	})

	/** A refused page is the server's; the pages read before it are still the way down. */
	it('shows the pages it has below after a refused one, and retries only at the end', async () => {
		const wrapper = await scrolled(5)
		vi.mocked(readTimeline).mockRejectedValue(new Error('The server answered 500'))
		await more(wrapper).vm.$emit('click')
		await flushPromises()
		await newer(wrapper).vm.$emit('click')

		expect(more(wrapper).text()).toBe('Load more')
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		await more(wrapper).vm.$emit('click')
		await flushPromises()

		expect(readTimeline).toHaveBeenCalledTimes(6)
		expect(shown(wrapper).at(-1)).toBe('r-5-1')
		expect(more(wrapper).text()).toBe('Try again')
	})

	it('hands the rows over unwatched', async () => {
		const wrapper = await scrolled(2)

		const row = /** @type {any} */ (wrapper.findAllComponents(TimelineRow)[0])
		expect(isProxy(row.props('entry'))).toBe(false)
	})
})
