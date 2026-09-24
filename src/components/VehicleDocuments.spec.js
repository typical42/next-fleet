/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { attachDocument, detachDocument, listDocuments, NotFoundError, readTimeline } from '../services/api.js'
import VehicleDocuments from './VehicleDocuments.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	attachDocument: vi.fn(),
	detachDocument: vi.fn(),
	listDocuments: vi.fn(),
	readTimeline: vi.fn(),
}))

// Nextcloud's picker is its own component, mounted by the library into the page; what this section
// decides is what it does with the file that comes back.
vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_unit: 'km' }

/** @type {import('../services/api.js').Document} */
const REGISTRATION = { uuid: 'd-1', kind: 'registration', file_id: 11, name: 'fahrzeugschein.pdf', mime: 'application/pdf', linked_type: null, linked_uuid: null }
/** @type {import('../services/api.js').Document} */
const INVOICE = { uuid: 'd-2', kind: 'receipt', file_id: 12, name: 'invoice.pdf', mime: 'application/pdf', linked_type: 'maintenance', linked_uuid: 'm-1' }
/** @type {import('../services/api.js').Document} */
const GONE = { uuid: 'd-3', kind: 'insurance', file_id: 13, name: null, mime: null, linked_type: null, linked_uuid: null }

const WORK = /** @type {any} */ ({ type: 'maintenance', occurred_at: 1788300000, occurred_at_off: 120, maintenance: { uuid: 'm-1', title: 'Inspection' } })

/** What the picker hands back next, or the close it rejects with. */
let picked = /** @type {Promise<any>} */ (Promise.resolve([]))
/** The buttons the section gave the picker, as a function of what is selected. */
let factory = /** @type {any} */ (null)

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section() {
	const wrapper = shallowMount(VehicleDocuments, {
		props: { vehicle: VEHICLE },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} text - the button's words, or its label where it shows fewer
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text || one.props('ariaLabel') === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} label - the field's label
 * @return {any} the select
 */
function select(wrapper, label) {
	return wrapper.findAllComponents(NcSelect).find((/** @type {any} */ one) => one.props('inputLabel') === label)
}

/**
 * Chooses what the picked file is.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} kind - the code
 */
async function choose(wrapper, kind) {
	const field = select(wrapper, 'What it is')
	await field.vm.$emit('update:modelValue', field.props('options').find((/** @type {any} */ one) => one.id === kind))
}

/**
 * Picks a file and waits for the question about it.
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {number} fileid - the file picked
 */
async function pick(wrapper, fileid) {
	picked = Promise.resolve([{ fileid, basename: 'bill.pdf' }])
	await button(wrapper, 'Add document').vm.$emit('click')
	await flushPromises()
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listDocuments).mockResolvedValue([REGISTRATION, INVOICE, GONE])
	vi.mocked(attachDocument).mockResolvedValue([REGISTRATION, INVOICE, GONE])
	vi.mocked(detachDocument).mockResolvedValue([INVOICE, GONE])
	vi.mocked(readTimeline).mockImplementation(async (uuid, { type }) => ({ rows: type === 'maintenance' ? [WORK] : [], next: null }))
	factory = null
	const builder = {
		setMultiSelect: () => builder,
		allowDirectories: () => builder,
		setButtonFactory: (/** @type {any} */ given) => {
			factory = given
			return builder
		},
		build: () => ({ pickNodes: () => picked }),
	}
	vi.mocked(getFilePickerBuilder).mockReturnValue(/** @type {any} */ (builder))
})

describe('the documents section', () => {
	/** Grouped by kind in a fixed order, since a vehicle's papers are looked for by what they are. */
	it('lists the papers under their kinds, each a link to our download', async () => {
		const wrapper = await section()

		expect(listDocuments).toHaveBeenCalledWith('v-1')
		expect(wrapper.findAll('.documents__kind').map((one) => one.text())).toEqual(['Registration', 'Insurance policy', 'Receipt'])
		const links = wrapper.findAll('.documents a')
		expect(links.map((one) => one.text())).toEqual(['fahrzeugschein.pdf', 'invoice.pdf'])
		expect(links[0].attributes('href')).toContain('/apps/nextfleet/vehicles/v-1/documents/d-1')
	})

	it('says a file is gone rather than offering a dead link', async () => {
		const wrapper = await section()

		expect(wrapper.text()).toContain('The file is gone from Files')
	})

	it('names the kind of entry a paper belongs to', async () => {
		const wrapper = await section()

		expect(wrapper.text()).toContain('Belongs to a maintenance record')
	})

	/** The timeline carries the paperclips, so it needs the same list (src/views/VehicleView.vue). */
	it('hands the list up each time it changes', async () => {
		const wrapper = await section()
		await button(wrapper, 'Remove fahrzeugschein.pdf').vm.$emit('click')
		await flushPromises()

		expect(wrapper.emitted('listed')).toEqual([[[REGISTRATION, INVOICE, GONE]], [[INVOICE, GONE]]])
	})

	it('teaches rather than saying nothing', async () => {
		vi.mocked(listDocuments).mockResolvedValue([])

		const wrapper = await section()

		expect(wrapper.text()).toContain('No documents yet')
	})

	it('says so when the list is refused', async () => {
		vi.mocked(listDocuments).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await section()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('500')
	})

	it('reads the list again when the screen is moved to another vehicle', async () => {
		const wrapper = await section()

		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()

		expect(listDocuments).toHaveBeenLastCalledWith('v-2')
	})

	/** The first vehicle's list arriving last would put its papers, and its paperclips, on the second. */
	it('drops the answer for a vehicle the screen has moved on from', async () => {
		/** @type {(list: any) => void} */
		let late = () => {}
		vi.mocked(listDocuments).mockReturnValueOnce(new Promise((resolve) => { late = resolve }))
		const wrapper = await section()

		vi.mocked(listDocuments).mockResolvedValueOnce([INVOICE])
		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()
		late([REGISTRATION])
		await flushPromises()

		expect(wrapper.findAll('.documents a').map((one) => one.text())).toEqual(['invoice.pdf'])
		expect(wrapper.emitted('listed')?.at(-1)).toEqual([[INVOICE]])
	})

	/** A list that did not arrive leaves no paperclips from the vehicle before. */
	it('hands up an empty list when the read is refused', async () => {
		vi.mocked(listDocuments).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await section()

		expect(wrapper.emitted('listed')).toEqual([[[]]])
	})
})

describe('adding a document', () => {
	/** One file at a time from Files; nothing is uploaded here (docs/architecture.md#documents). */
	it('attaches the picked file as the kind chosen, to the vehicle itself', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)

		expect(wrapper.get('.dialog').text()).toContain('bill.pdf')
		await choose(wrapper, 'registration')
		await button(wrapper, 'Attach').vm.$emit('click')
		await flushPromises()

		expect(attachDocument).toHaveBeenCalledWith('v-1', { file_id: 42, kind: 'registration' })
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	/** The picker brings no button of its own, and one with nothing selected would pick nothing. */
	it('gives the picker one button, offered once a file is selected', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)

		expect(factory([], '/', 'files')).toEqual([expect.objectContaining({ label: 'Choose', variant: 'primary', disabled: true })])
		expect(factory([{ fileid: 42 }], '/', 'files')[0].disabled).toBe(false)
	})

	it('attaches nothing until the kind is chosen', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)

		expect(button(wrapper, 'Attach').props('disabled')).toBe(true)
	})

	/** A workshop invoice belongs to its maintenance record, which is where the paperclip shows. */
	it('links it to an entry of the vehicle when one is chosen', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)

		const belongs = select(wrapper, 'Belongs to')
		const options = belongs.props('options')
		expect(options.map((/** @type {any} */ one) => one.label)).toEqual([expect.stringContaining('Inspection')])
		await choose(wrapper, 'receipt')
		await belongs.vm.$emit('update:modelValue', options[0])
		await button(wrapper, 'Attach').vm.$emit('click')
		await flushPromises()

		expect(attachDocument).toHaveBeenCalledWith('v-1', { file_id: 42, kind: 'receipt', linked_type: 'maintenance', linked_uuid: 'm-1' })
	})

	it('does nothing when the picker is closed', async () => {
		const wrapper = await section()
		picked = Promise.reject(new FilePickerClosed())

		await button(wrapper, 'Add document').vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	/**
	 * The server refuses a file shared with the person as it refuses one that is not there, and says
	 * why only in its own words; the section says what it means.
	 */
	it('keeps the question open and says why when the file is refused', async () => {
		vi.mocked(attachDocument).mockRejectedValue(new NotFoundError('No such vehicle'))
		const wrapper = await section()
		await pick(wrapper, 42)

		await choose(wrapper, 'receipt')
		await button(wrapper, 'Attach').vm.$emit('click')
		await flushPromises()

		expect(wrapper.find('.dialog').exists()).toBe(true)
		expect(wrapper.find('.dialog').findComponent(NcNoteCard).props('text')).toContain('your own')
	})
})

describe('removing a document', () => {
	it('takes the paper off and shows the list as it now stands', async () => {
		const wrapper = await section()

		await button(wrapper, 'Remove fahrzeugschein.pdf').vm.$emit('click')
		await flushPromises()

		expect(detachDocument).toHaveBeenCalledWith('v-1', 'd-1')
		expect(wrapper.findAll('.documents a').map((one) => one.text())).toEqual(['invoice.pdf'])
	})

	it('says so when the removal is refused, and keeps the list', async () => {
		vi.mocked(detachDocument).mockRejectedValue(new Error('Not yours'))
		const wrapper = await section()

		await button(wrapper, 'Remove fahrzeugschein.pdf').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toContain('Not yours')
		expect(wrapper.findAll('.documents a')).toHaveLength(2)
	})
})
