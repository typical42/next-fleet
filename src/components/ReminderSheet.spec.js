/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcFormBoxSwitch from '@nextcloud/vue/components/NcFormBoxSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, createReminder, deleteEntry, dismissReminder, listReminders, reminderTemplates, snoozeReminder, updateEntry } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import ReminderSheet from './ReminderSheet.vue'

// The network is the api client's seam, and the store is left real: a delete goes through it so
// the undo toast can offer it back.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	createReminder: vi.fn(),
	deleteEntry: vi.fn(),
	dismissReminder: vi.fn(),
	listReminders: vi.fn(),
	reminderTemplates: vi.fn(),
	snoozeReminder: vi.fn(),
	updateEntry: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_unit: 'km' }

/** @type {any[]} */
const TEMPLATES = [
	{ key: 'oil_change', mode: 'either', recur_months: 12, recur_odo: 15000, lead_odo: 1000 },
	{ key: 'tyre_swap', mode: 'date', recur_months: 6, recur_odo: null, lead_odo: null },
	{ key: 'hu_au', mode: 'date', recur_months: 24, recur_odo: null, lead_odo: null },
]

/** @type {any} */
const OWN = {
	uuid: 'r-1',
	updated_at: 1700000100,
	template_key: null,
	title: 'Insurance renewal',
	mode: 'date',
	due_date: '2026-12-31',
	due_odo: null,
	lead_odo: null,
	warn_month_before: true,
	warn_month_start: false,
	warn_due_date: true,
	recur_months: 12,
	recur_odo: null,
	state: 'planned',
	snoozed_until: null,
	estimate: null,
}

/**
 * @param {object|null} [reminder] - the reminder to edit, or nothing for a new one
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the sheet, past its first read
 */
async function sheet(reminder = null) {
	const wrapper = shallowMount(ReminderSheet, {
		props: { vehicle: VEHICLE, reminder },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the field's label
 * @return {any} the text field, select, switch or picker labelled so, or undefined
 */
function field(wrapper, label) {
	return [
		...wrapper.findAllComponents(NcTextField),
		...wrapper.findAllComponents(NcSelect),
		...wrapper.findAllComponents(NcFormBoxSwitch),
		...wrapper.findAllComponents(NcDateTimePickerNative),
	// A select's `label` names the option key; its visible label is `inputLabel`.
	].find((/** @type {any} */ one) => (one.props('inputLabel') ?? one.props('label')) === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @return {string} what the note card says, or nothing when there is none
 */
function noted(wrapper) {
	const card = wrapper.findComponent(NcNoteCard)

	return card.exists() ? card.props('text') ?? '' : ''
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - what the button says
 * @return {Promise<void>} when the click has been answered
 */
async function click(wrapper, text) {
	await /** @type {any} */ (wrapper.findAllComponents(NcButton).find((one) => one.text() === text)).vm.$emit('click')
	await flushPromises()
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the select's label
 * @param {string} id - the option to pick
 */
async function pick(wrapper, label, id) {
	const select = field(wrapper, label)
	await select.vm.$emit('update:modelValue', select.props('options').find((/** @type {{id: string}} */ one) => one.id === id))
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(reminderTemplates).mockResolvedValue(TEMPLATES)
	vi.mocked(createReminder).mockImplementation(async (uuid, fields) => ({ ...OWN, ...fields }))
	vi.mocked(updateEntry).mockImplementation(async (uuid, type, reminder, fields) => ({ ...reminder, ...fields }))
	vi.mocked(snoozeReminder).mockResolvedValue(OWN)
	vi.mocked(dismissReminder).mockResolvedValue(OWN)
	vi.mocked(deleteEntry).mockImplementation(async (uuid, type, reminder) => ({ ...reminder, updated_at: 1700000900 }))
})

afterEach(() => {
	vi.useRealTimers()
})

describe('a new reminder', () => {
	/** Prefilled is visibly editable (docs/ui.md): the template's recurrence lands in the fields. */
	it('starts from a template, which fills in what it knows', async () => {
		const wrapper = await sheet()

		expect(reminderTemplates).toHaveBeenCalledWith('v-1')
		await pick(wrapper, 'Reminder', 'oil_change')

		expect(field(wrapper, 'Title')).toBeUndefined()
		expect(field(wrapper, 'Due by').props('modelValue').id).toBe('either')
		expect(field(wrapper, 'Repeat every (months)').props('modelValue')).toBe('12')
		expect(field(wrapper, 'Repeat every (km)').props('modelValue')).toBe('15000')
		expect(field(wrapper, 'Warn before (km)').props('modelValue')).toBe('1000')
	})

	/** A template's title translates, so the sheet sends its key and never a title beside it. */
	it('saves a template by its key, with only the fields its mode reads', async () => {
		const wrapper = await sheet()
		await pick(wrapper, 'Reminder', 'tyre_swap')
		await field(wrapper, 'Due date').vm.$emit('update:modelValue', new Date(2027, 3, 15))

		await click(wrapper, 'Add reminder')

		expect(createReminder).toHaveBeenCalledWith('v-1', {
			template_key: 'tyre_swap',
			mode: 'date',
			due_date: '2027-04-15',
			recur_months: 6,
			warn_month_before: true,
			warn_month_start: false,
			warn_due_date: true,
		})
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('saves an own title by the counter, grouped as the locale writes it', async () => {
		const wrapper = await sheet()
		await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Chain')
		await pick(wrapper, 'Due by', 'odo')
		await field(wrapper, 'Due at (km)').vm.$emit('update:modelValue', '160.000')

		await click(wrapper, 'Add reminder')

		expect(createReminder).toHaveBeenCalledWith('v-1', { title: 'Chain', mode: 'odo', due_odo: 160000, lead_odo: null, recur_odo: null })
		expect(field(wrapper, 'Warn a month before')).toBeUndefined()
	})

	/** A failed save is never lost: the sheet stays open with its values and says why. */
	it('stays open with what it holds when the server refuses', async () => {
		vi.mocked(createReminder).mockRejectedValue(new Error('due_date is a field every reminder by date carries'))
		const wrapper = await sheet()
		await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Wax')

		await click(wrapper, 'Add reminder')

		expect(noted(wrapper)).toBe('due_date is a field every reminder by date carries')
		expect(field(wrapper, 'Title').props('modelValue')).toBe('Wax')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	it('asks again for a counter it cannot read, and sends nothing', async () => {
		const wrapper = await sheet()
		await field(wrapper, 'Title').vm.$emit('update:modelValue', 'Chain')
		await pick(wrapper, 'Due by', 'odo')
		await field(wrapper, 'Due at (km)').vm.$emit('update:modelValue', '7,2')

		await click(wrapper, 'Add reminder')

		expect(createReminder).not.toHaveBeenCalled()
		expect(noted(wrapper)).toBe('Due at (km) is not a whole number.')
	})
})

describe('an existing reminder', () => {
	it('opens with what it says and writes the whole of it back under its token', async () => {
		const wrapper = await sheet(OWN)
		expect(field(wrapper, 'Reminder')).toBeUndefined()
		expect(field(wrapper, 'Title').props('modelValue')).toBe('Insurance renewal')

		await field(wrapper, 'Repeat every (months)').vm.$emit('update:modelValue', '')
		await click(wrapper, 'Save')

		expect(updateEntry).toHaveBeenCalledWith('v-1', 'reminder', OWN, {
			title: 'Insurance renewal',
			mode: 'date',
			due_date: '2026-12-31',
			recur_months: null,
			warn_month_before: true,
			warn_month_start: false,
			warn_due_date: true,
		})
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	it('sends no title for a template, whose title translates', async () => {
		const wrapper = await sheet({ ...OWN, template_key: 'hu_au', title: null })

		expect(field(wrapper, 'Title')).toBeUndefined()
		await click(wrapper, 'Save')

		expect(vi.mocked(updateEntry).mock.calls[0][3]).not.toHaveProperty('title')
	})

	it('snoozes for a week, a month, or until a day', async () => {
		vi.useFakeTimers({ toFake: ['Date'] })
		vi.setSystemTime(new Date(2026, 8, 22, 10))
		const wrapper = await sheet(OWN)

		await click(wrapper, 'Snooze 1 week')
		await click(wrapper, 'Snooze 1 month')
		await field(wrapper, 'Snooze until').vm.$emit('update:modelValue', new Date(2026, 10, 3))
		await click(wrapper, 'Snooze until then')

		expect(vi.mocked(snoozeReminder).mock.calls.map((call) => call[2])).toEqual(['2026-09-29', '2026-10-22', '2026-11-03'])
		expect(snoozeReminder).toHaveBeenCalledWith('v-1', OWN, '2026-09-29')
	})

	it('skips this occurrence', async () => {
		const wrapper = await sheet(OWN)

		await click(wrapper, 'Skip this time')

		expect(dismissReminder).toHaveBeenCalledWith('v-1', OWN)
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	/** Nothing asks "are you sure?": the undo toast offers it back (docs/ui.md). */
	it('deletes, and leaves the way back with the store', async () => {
		const wrapper = await sheet(OWN)

		await click(wrapper, 'Delete reminder')

		expect(deleteEntry).toHaveBeenCalledWith('v-1', 'reminder', { uuid: 'r-1', updated_at: 1700000100 })
		expect(useVehiclesStore().struck).toEqual({ vehicle: 'v-1', type: 'reminder', entry: expect.objectContaining({ updated_at: 1700000900 }) })
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	/**
	 * A refused snooze is not a refused save: the note says what snoozing again does, and doing so
	 * acts on the reminder as it now stands.
	 */
	it('snoozes again under the token the list now holds', async () => {
		vi.mocked(snoozeReminder).mockRejectedValueOnce(new ConflictError('stale'))
		vi.mocked(listReminders).mockResolvedValue([{ ...OWN, updated_at: 1700000500 }])
		const wrapper = await sheet(OWN)

		await click(wrapper, 'Snooze 1 week')
		expect(noted(wrapper)).toContain('Doing the same again acts on it as it now stands.')
		await click(wrapper, 'Snooze 1 week')

		expect(vi.mocked(snoozeReminder).mock.calls[1][1]).toEqual(expect.objectContaining({ updated_at: 1700000500 }))
		expect(updateEntry).not.toHaveBeenCalled()
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})

	/** A refused write reads the reminder back and writes what is on screen under the new token. */
	it('saves anyway under the token the list now holds', async () => {
		vi.mocked(updateEntry).mockRejectedValueOnce(new ConflictError('stale'))
		vi.mocked(listReminders).mockResolvedValue([{ ...OWN, updated_at: 1700000500 }])
		const wrapper = await sheet(OWN)

		await click(wrapper, 'Save')
		expect(noted(wrapper)).toContain('This reminder was changed somewhere else')
		await click(wrapper, 'Save anyway')

		expect(listReminders).toHaveBeenCalledWith('v-1')
		expect(vi.mocked(updateEntry).mock.calls[1][2]).toEqual(expect.objectContaining({ updated_at: 1700000500 }))
		expect(wrapper.emitted('saved')).toHaveLength(1)
	})
})
