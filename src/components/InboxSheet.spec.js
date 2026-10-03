/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { attachDocument, listBookings, NotFoundError, readTimeline } from '../services/api.js'
import InboxSheet from './InboxSheet.vue'

// The network is the api client's own seam (api.spec.js).
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	attachDocument: vi.fn(),
	listBookings: vi.fn(),
	readTimeline: vi.fn(),
}))

const OWNED = { uuid: 'v-1', plate: 'B-XY 123', name: 'Golf', may: ['view', 'log', 'edit', 'delete', 'own'] }
/** A driver files only on rows of their own. */
const DRIVEN = { uuid: 'v-2', plate: 'M-AB 1', name: null, may: ['view', 'log'], energy_types: ['diesel'] }
/** A viewer files nothing, so the sheet does not offer the vehicle. */
const VIEWED = { uuid: 'v-3', plate: 'HH-CD 42', name: null, may: ['view'] }

const FILE = { file_id: 42, name: 'IMG_0815.jpg', mime: 'image/jpeg', mtime: 1788300000, size: 2048 }

/** The driver's own fill-up, newest of their rows. */
const FILL = /** @type {any} */ ({ type: 'energy', occurred_at: 1788200000, occurred_at_off: 120, energy: { uuid: 'e-1', energy: 'diesel' }, may: ['edit', 'delete'] })
const WORK = /** @type {any} */ ({ type: 'maintenance', occurred_at: 1788100000, occurred_at_off: 120, maintenance: { uuid: 'm-1', title: 'Inspection' }, may: ['edit', 'delete'] })
/** Handed over after the fill-up: newer, but a receipt is an entry's. */
const TAKEN = /** @type {any} */ ({ uuid: 'b-1', state: 'returned', starts_at: 1788250000, starts_at_off: 120, ends_at: 1788257200, ends_at_off: 120, may: ['attach'] })

/**
 * @param {object} [props] - what the screen hands the sheet
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the sheet, once its rows were read
 */
async function sheet(props = {}) {
	const wrapper = shallowMount(InboxSheet, {
		props: { file: FILE, vehicles: [VIEWED, OWNED, DRIVEN], preferred: null, ...props },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} label - the field's label
 * @return {any} the select
 */
function select(wrapper, label) {
	return wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @return {any} the Attach button
 */
function attachButton(wrapper) {
	return button(wrapper, 'Attach')
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} text - what the button says
 * @return {any} that button, or undefined when the sheet does not offer it
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sheet
 * @param {string} uuid - the vehicle to pick
 */
async function pickVehicle(wrapper, uuid) {
	const field = select(wrapper, 'Vehicle')
	await field.vm.$emit('update:modelValue', field.props('options').find((/** @type {any} */ one) => one.id === uuid))
	await flushPromises()
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(attachDocument).mockResolvedValue([])
	vi.mocked(readTimeline).mockImplementation(async (uuid, { type }) => ({
		rows: uuid === 'v-2' ? { energy: [FILL], maintenance: [WORK], expense: [] }[/** @type {string} */ (type)] ?? [] : [],
		next: null,
	}))
	vi.mocked(listBookings).mockImplementation(async (uuid) => (uuid === 'v-2' ? [TAKEN] : []))
})

describe('the inbox sheet', () => {
	/** Attaching takes `log` at least (docs/architecture.md#documents); a viewer's car is not offered. */
	it('offers the vehicles the session may file papers on, the first of them picked', async () => {
		const wrapper = await sheet()

		expect(select(wrapper, 'Vehicle').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['v-1', 'v-2'])
		expect(select(wrapper, 'Vehicle').props('modelValue').id).toBe('v-1')
	})

	it('picks the vehicle used last', async () => {
		const wrapper = await sheet({ preferred: 'v-2' })

		expect(select(wrapper, 'Vehicle').props('modelValue').id).toBe('v-2')
	})

	/** The common case is two taps: the file, then Attach. A photographed receipt is a receipt. */
	it('attaches a receipt to the vehicle in one more tap', async () => {
		const wrapper = await sheet()
		expect(select(wrapper, 'What it is').props('modelValue').id).toBe('receipt')

		await attachButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(attachDocument).toHaveBeenCalledWith('v-1', { file_id: 42, kind: 'receipt' })
		expect(wrapper.emitted('attached')).toEqual([['v-1']])
	})

	/**
	 * A driver's paper must hang on a row of their own, so one is picked for them: their newest
	 * entry, since a receipt is a fill-up's or an invoice's, not a booking's.
	 */
	it('picks a driver\'s newest own entry, and attaches to it', async () => {
		const wrapper = await sheet({ preferred: 'v-2' })

		expect(select(wrapper, 'Belongs to').props('modelValue').id).toBe('e-1')

		await attachButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(attachDocument).toHaveBeenCalledWith('v-2', { file_id: 42, kind: 'receipt', linked_type: 'energy', linked_uuid: 'e-1' })
	})

	it('reads the rows of the vehicle picked', async () => {
		const wrapper = await sheet()
		expect(select(wrapper, 'Belongs to')).toBeUndefined()

		await pickVehicle(wrapper, 'v-2')

		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['b-1', 'e-1', 'm-1'])
		expect(select(wrapper, 'Belongs to').props('modelValue').id).toBe('e-1')
	})

	it('tells a driver with no row of their own why Attach waits', async () => {
		vi.mocked(readTimeline).mockResolvedValue({ rows: [], next: null })
		vi.mocked(listBookings).mockResolvedValue([])

		const wrapper = await sheet({ preferred: 'v-2' })

		expect(attachButton(wrapper).props('disabled')).toBe(true)
		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('only to an entry or a booking of your own')
	})

	/** A failed read is not "none of your own": it says what went wrong and reads again on request. */
	it('says the rows could not be read, and reads them again', async () => {
		vi.mocked(listBookings).mockRejectedValueOnce(new Error('The server answered 500'))
		const wrapper = await sheet({ preferred: 'v-2' })

		expect(wrapper.findComponent(NcNoteCard).props('type')).toBe('error')
		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('The server answered 500')
		expect(attachButton(wrapper).props('disabled')).toBe(true)

		await wrapper.findAllComponents(NcButton).find((one) => one.text() === 'Try again')?.vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		expect(select(wrapper, 'Belongs to').props('modelValue').id).toBe('e-1')
	})

	/** A 404 is the file not being the person's own any more (docs/architecture.md#documents). */
	it('stays open with what went wrong', async () => {
		vi.mocked(attachDocument).mockRejectedValue(new NotFoundError('No such vehicle'))
		const wrapper = await sheet()

		await attachButton(wrapper).vm.$emit('click')
		await flushPromises()

		expect(wrapper.emitted('attached')).toBeUndefined()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('Only a file of your own can be attached, not one shared with you.')
	})

	/** A receipt for a cost nobody entered yet: the entry is logged from it, then it is filed there. */
	it('offers to log a new cost from the file on the vehicle picked', async () => {
		const wrapper = await sheet({ preferred: 'v-2' })

		await button(wrapper, 'New maintenance').vm.$emit('click')

		expect(wrapper.emitted('log')).toEqual([[{ vehicle: 'v-2', type: 'maintenance', kind: 'receipt' }]])
	})

	/** The entry sheet offers no fill-up to a vehicle that names no energy, and neither does this. */
	it('offers a fill-up only on a vehicle that takes one', async () => {
		const wrapper = await sheet()
		expect(button(wrapper, 'New fill-up')).toBeUndefined()
		expect(button(wrapper, 'New expense')).toBeDefined()

		await pickVehicle(wrapper, 'v-2')

		expect(button(wrapper, 'New fill-up')).toBeDefined()
	})

	it('says so when no vehicle takes papers from the session', async () => {
		const wrapper = await sheet({ vehicles: [VIEWED] })

		expect(attachButton(wrapper).props('disabled')).toBe(true)
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('None of your vehicles takes papers from you.')
	})
})
