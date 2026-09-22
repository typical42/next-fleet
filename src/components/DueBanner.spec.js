/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { listReminders, reminderTemplates } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import DueBanner from './DueBanner.vue'
import InspectionSticker from './InspectionSticker.vue'
import ReminderSheet from './ReminderSheet.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	listReminders: vi.fn(),
	reminderTemplates: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_unit: 'km', odo_value: 148320, jurisdiction: 'de', vehicle_type: 'car' }

/** @type {any[]} */
const TEMPLATES = [
	{ key: 'oil_change', mode: 'either', recur_months: 12, recur_odo: 15000, lead_odo: 1000, first_due_months: null },
	{ key: 'hu_au', mode: 'date', recur_months: 24, recur_odo: null, lead_odo: null, first_due_months: 36 },
]

/**
 * @param {any} fields - what differs from a planned reminder by date
 * @return {any} one reminder as the list answers it
 */
function reminder(fields) {
	return {
		uuid: fields.uuid,
		updated_at: 1700000100,
		template_key: null,
		title: null,
		mode: 'date',
		due_date: null,
		due_odo: null,
		lead_odo: null,
		warn_month_before: true,
		warn_month_start: false,
		warn_due_date: true,
		recur_months: null,
		recur_odo: null,
		state: 'planned',
		snoozed_until: null,
		estimate: null,
		...fields,
	}
}

const HU = reminder({ uuid: 'r-hu', template_key: 'hu_au', due_date: '2026-09-30', state: 'warned' })
const OIL = reminder({ uuid: 'r-oil', template_key: 'oil_change', mode: 'odo', due_odo: 150000, estimate: '2026-11-02' })
const CHAIN = reminder({ uuid: 'r-chain', title: 'Chain', mode: 'odo', due_odo: 160000 })
const INSURANCE = reminder({ uuid: 'r-ins', title: 'Insurance renewal', due_date: '2026-09-01', state: 'overdue' })
const DONE = reminder({ uuid: 'r-done', title: 'Old', due_date: '2026-01-01', state: 'dismissed' })

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the banner, past its first read
 */
async function banner() {
	const wrapper = shallowMount(DueBanner, {
		props: { vehicle: VEHICLE },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the banner
 * @return {string[]} each listed row's text, top to bottom
 */
function rows(wrapper) {
	return wrapper.findAll('.due__row').map((row) => row.text())
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the banner
 * @return {Promise<void>} when + Reminder has been clicked
 */
async function add(wrapper) {
	await /** @type {any} */ (wrapper.findAllComponents(NcButton).find((one) => one.text() === '+ Reminder')).vm.$emit('click')
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the banner
 * @return {any} the reminder sheet it has open
 */
function sheetOf(wrapper) {
	return wrapper.findComponent(ReminderSheet)
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(listReminders).mockResolvedValue([CHAIN, OIL, HU, INSURANCE, DONE])
	vi.mocked(reminderTemplates).mockResolvedValue(TEMPLATES)
})

describe('the due banner', () => {
	it('lists the open reminders, most urgent first', async () => {
		const wrapper = await banner()

		expect(listReminders).toHaveBeenCalledWith('v-1')
		const listed = rows(wrapper)
		expect(listed).toHaveLength(4)
		expect(listed[0]).toContain('Insurance renewal')
		expect(listed[0]).toContain('Overdue')
		expect(listed[1]).toContain('Technical inspection (HU/AU)')
		expect(listed[1]).toContain('Coming up')
		expect(listed[2]).toContain('Oil change')
		expect(listed[3]).toContain('Chain')
	})

	/** Rule 5: an estimate only with enough data, and otherwise the banner says so. */
	it('states a due date, a due km, and an estimate or the lack of one', async () => {
		const wrapper = await banner()
		const [insurance, , oil, chain] = rows(wrapper)

		expect(insurance).toMatch(/Due .*2026/)
		expect(oil).toContain('150,000 km')
		expect(oil).toMatch(/Expected around .*2026/)
		expect(chain).toContain('160,000 km')
		expect(chain).toContain('Not enough data yet')
		expect(insurance).not.toContain('Not enough data yet')
	})

	it('opens an empty sheet from + Reminder and a filled one from a row', async () => {
		const wrapper = await banner()
		expect(wrapper.findComponent(ReminderSheet).exists()).toBe(false)

		await add(wrapper)
		expect(sheetOf(wrapper).props('reminder')).toBeNull()
		await sheetOf(wrapper).vm.$emit('close')

		await wrapper.find('.due__row button').trigger('click')
		expect(sheetOf(wrapper).props('reminder')).toEqual(INSURANCE)
	})

	/**
	 * "Done" is the maintenance that closes a reminder, so it asks the screen for the entry sheet
	 * on that reminder rather than opening the reminder sheet.
	 */
	it('asks for a maintenance record on a reminder from its Done', async () => {
		const wrapper = await banner()

		const second = wrapper.findAll('.due__row')[1]
		await /** @type {any} */ (second.findAllComponents(NcButton).find((/** @type {any} */ one) => one.text() === 'Done')).vm.$emit('click')

		expect(wrapper.emitted('done')).toEqual([['r-hu']])
		expect(wrapper.findComponent(ReminderSheet).exists()).toBe(false)
	})

	/** Only the list carries the estimate and today's state, so a write is followed by a read. */
	it('reads the list again after the sheet wrote and after an undo', async () => {
		const wrapper = await banner()
		await add(wrapper)

		await sheetOf(wrapper).vm.$emit('saved')
		await flushPromises()
		expect(wrapper.findComponent(ReminderSheet).exists()).toBe(false)
		expect(listReminders).toHaveBeenCalledTimes(2)

		useVehiclesStore().restored++
		await flushPromises()
		expect(listReminders).toHaveBeenCalledTimes(3)
	})

	/** A km reminder's state and estimate move with the counter, and another vehicle is another list. */
	it('reads again when the counter moves or the vehicle changes', async () => {
		const wrapper = await banner()

		await wrapper.setProps({ vehicle: { ...VEHICLE, odo_value: 149000 } })
		await flushPromises()
		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()

		expect(vi.mocked(listReminders).mock.calls).toEqual([['v-1'], ['v-1'], ['v-2']])
	})

	/** A slow answer for the vehicle left behind must not land under the one now shown. */
	it('drops an answer for a vehicle it has moved on from', async () => {
		/** @type {(value: any) => void} */
		let late = () => {}
		vi.mocked(listReminders)
			.mockReturnValueOnce(new Promise((resolve) => { late = resolve }))
			.mockResolvedValueOnce([HU])
		// Mounted without banner(), whose flush would wait on the answer held back here.
		/** @type {import('@vue/test-utils').VueWrapper} */
		const wrapper = shallowMount(DueBanner, { props: { vehicle: VEHICLE }, global: { renderStubDefaultSlot: true } })

		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()
		late([INSURANCE])
		await flushPromises()

		expect(rows(wrapper)).toHaveLength(1)
		expect(rows(wrapper)[0]).toContain('Technical inspection (HU/AU)')
	})

	/** The counter has passed the due km, so there is nothing left to estimate, and no lack of data. */
	it('states no estimate for a reminder by km that is already due', async () => {
		// Snoozed, so the state alone does not say the km is reached; the counter (148 320) does.
		vi.mocked(listReminders).mockResolvedValue([reminder({ uuid: 'r-due', title: 'Chain', mode: 'odo', due_odo: 140000, state: 'snoozed' })])
		const wrapper = await banner()

		expect(rows(wrapper)[0]).not.toContain('Not enough data yet')
	})

	it('shows no list when nothing is open', async () => {
		vi.mocked(listReminders).mockResolvedValue([DONE])
		const wrapper = await banner()

		expect(rows(wrapper)).toEqual([])
		expect(wrapper.findAllComponents(NcButton).some((one) => one.text() === '+ Reminder')).toBe(true)
	})
})

describe('the HU/AU sticker question', () => {
	it('is asked where the jurisdiction has an inspection and the vehicle has no HU/AU reminder', async () => {
		vi.mocked(listReminders).mockResolvedValue([OIL, DONE])
		const wrapper = await banner()

		expect(reminderTemplates).toHaveBeenCalledWith('v-1')
		expect(/** @type {any} */ (wrapper.findComponent(InspectionSticker)).props('template')).toEqual(TEMPLATES[1])
	})

	it('is not asked once the vehicle has one', async () => {
		const wrapper = await banner()

		expect(wrapper.findComponent(InspectionSticker).exists()).toBe(false)
	})

	it('is not asked where no inspection is required', async () => {
		vi.mocked(listReminders).mockResolvedValue([OIL])
		vi.mocked(reminderTemplates).mockResolvedValue([TEMPLATES[0]])
		const wrapper = await banner()

		expect(wrapper.findComponent(InspectionSticker).exists()).toBe(false)
	})

	/** The vehicle sheet asks the same question behind the banner's back. */
	it('reads the list again when a reminder was added anywhere', async () => {
		vi.mocked(listReminders).mockResolvedValue([OIL])
		const wrapper = await banner()
		vi.mocked(listReminders).mockResolvedValue([OIL, HU])

		useVehiclesStore().reminded++
		await flushPromises()

		expect(wrapper.findComponent(InspectionSticker).exists()).toBe(false)
		expect(rows(wrapper)).toHaveLength(2)
	})

	/** A change of country or type is another set of templates. */
	it('asks the templates again when the jurisdiction or the type changes', async () => {
		const wrapper = await banner()

		await wrapper.setProps({ vehicle: { ...VEHICLE, jurisdiction: 'generic' } })
		await flushPromises()
		await wrapper.setProps({ vehicle: { ...VEHICLE, jurisdiction: 'generic', vehicle_type: 'truck' } })
		await flushPromises()

		expect(reminderTemplates).toHaveBeenCalledTimes(3)
	})
})
