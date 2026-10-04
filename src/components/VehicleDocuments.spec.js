/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { attachDocument, detachDocument, fetchDocument, listBookings, listDocuments, LockedError, NotFoundError, readInbox, readTimeline, restoreDocument } from '../services/api.js'
import { useInboxStore } from '../store/inbox.js'
import { useVehiclesStore } from '../store/index.js'
import VehicleDocuments from './VehicleDocuments.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	attachDocument: vi.fn(),
	detachDocument: vi.fn(),
	fetchDocument: vi.fn(),
	listBookings: vi.fn(),
	listDocuments: vi.fn(),
	readInbox: vi.fn(),
	readTimeline: vi.fn(),
	restoreDocument: vi.fn(),
}))

// Nextcloud's picker is its own component, mounted by the library into the page; what this section
// decides is what it does with the file that comes back.
vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_unit: 'km', may: ['view', 'log', 'edit', 'delete', 'own'] }
/** A driver files papers on their own entries and bookings; the vehicle's own take `edit`. */
const DRIVEN = { ...VEHICLE, may: ['view', 'log'] }
/** A viewer reads the papers and keeps none. */
const VIEWED = { ...VEHICLE, may: ['view'] }

/** @type {import('../services/api.js').Document} */
const REGISTRATION = { uuid: 'd-1', kind: 'registration', file_id: 11, name: 'fahrzeugschein.pdf', mime: 'application/pdf', linked_type: null, linked_uuid: null, may: ['detach'] }
/** @type {import('../services/api.js').Document} */
const INVOICE = { uuid: 'd-2', kind: 'receipt', file_id: 12, name: 'invoice.pdf', mime: 'application/pdf', linked_type: 'maintenance', linked_uuid: 'm-1', may: ['detach'] }
/** @type {import('../services/api.js').Document} */
const GONE = { uuid: 'd-3', kind: 'insurance', file_id: 13, name: null, mime: null, linked_type: null, linked_uuid: null, may: ['detach'] }
/** @type {import('../services/api.js').Document} */
const SCRATCH = { uuid: 'd-4', kind: 'photo', file_id: 14, name: 'scratch.jpg', mime: 'image/jpeg', linked_type: 'booking', linked_uuid: 'b-1', may: ['detach'] }

const WORK = /** @type {any} */ ({ type: 'maintenance', occurred_at: 1788300000, occurred_at_off: 120, maintenance: { uuid: 'm-1', title: 'Inspection' }, may: ['edit', 'delete'] })
/** The driver's own fill-up, which their receipt may go on. */
const FILL = /** @type {any} */ ({ type: 'energy', occurred_at: 1788200000, occurred_at_off: 120, energy: { uuid: 'e-1', energy: 'diesel' }, may: ['edit', 'delete'] })
/** A booking handed over, so the screen offers it a photo. */
const TAKEN = /** @type {any} */ ({ uuid: 'b-1', state: 'returned', starts_at: 1788400000, starts_at_off: 120, ends_at: 1788407200, ends_at_off: 120, may: ['attach'] })

/** What the picker hands back next, or the close it rejects with. */
let picked = /** @type {Promise<any>} */ (Promise.resolve([]))
/** The buttons the section gave the picker, as a function of what is selected. */
let factory = /** @type {any} */ (null)

/**
 * @param {object} [vehicle] - the vehicle, and with it what the session may do on it
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section(vehicle = VEHICLE) {
	const wrapper = shallowMount(VehicleDocuments, {
		props: { vehicle },
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
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(listDocuments).mockResolvedValue([REGISTRATION, INVOICE, GONE])
	vi.mocked(attachDocument).mockResolvedValue([REGISTRATION, INVOICE, GONE])
	vi.mocked(detachDocument).mockResolvedValue([INVOICE, GONE])
	vi.mocked(readTimeline).mockImplementation(async (uuid, { type }) => ({ rows: type === 'maintenance' ? [WORK] : [], next: null }))
	vi.mocked(listBookings).mockResolvedValue([])
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

		expect(wrapper.text()).toContain('The file is no longer in the Files of whoever attached it')
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

	it('names a handover photo as the booking\'s', async () => {
		vi.mocked(listDocuments).mockResolvedValue([SCRATCH])

		const wrapper = await section()

		expect(wrapper.text()).toContain('Belongs to a booking')
	})

	/** The server refuses both to anyone without `log`; the section stops offering them. */
	it('offers neither adding nor removing to someone who may only view the vehicle', async () => {
		vi.mocked(listDocuments).mockResolvedValue([REGISTRATION, INVOICE, GONE].map((one) => ({ ...one, may: [] })))

		const wrapper = await section(VIEWED)

		expect(wrapper.findAll('.documents a').map((one) => one.text())).toEqual(['fahrzeugschein.pdf', 'invoice.pdf'])
		expect(wrapper.findAllComponents(NcButton)).toHaveLength(0)
	})

	/** Each paper says whether the session may take it off: the rule of the row it hangs on. */
	it('offers a driver adding, and removing only the papers the server says are theirs to remove', async () => {
		vi.mocked(listDocuments).mockResolvedValue([{ ...REGISTRATION, may: [] }, SCRATCH])

		const wrapper = await section(DRIVEN)

		expect(button(wrapper, 'Add document')).toBeDefined()
		expect(button(wrapper, 'Remove fahrzeugschein.pdf')).toBeUndefined()
		expect(button(wrapper, 'Remove scratch.jpg')).toBeDefined()
	})

	/** Telling somebody to attach what they cannot is no teaching. */
	it('says only that there are none to someone who may not attach one', async () => {
		vi.mocked(listDocuments).mockResolvedValue([])

		const wrapper = await section(VIEWED)

		expect(wrapper.find('.documents__empty').text()).toBe('No documents yet.')
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

	/** The screen asks once the role changed, since each paper's `may` follows it (src/views/VehicleView.vue). */
	it('reads the list again when the screen asks', async () => {
		const wrapper = await section()

		await /** @type {any} */ (wrapper.vm).reload()

		expect(listDocuments).toHaveBeenCalledTimes(2)
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

	/** A file from the inbox folder waits no more, and the count beside the menu says so. */
	it('reads the inbox count again once a file is attached', async () => {
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 3 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 2 })
		await useInboxStore().load()
		const wrapper = await section()
		await pick(wrapper, 42)

		await choose(wrapper, 'registration')
		await button(wrapper, 'Attach').vm.$emit('click')
		await flushPromises()

		expect(useInboxStore().count).toBe(2)
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

	/** Done when: handover photos attach to the booking. */
	it('offers a handed-over booking as what a photo belongs to', async () => {
		vi.mocked(listBookings).mockResolvedValue([TAKEN, { ...TAKEN, uuid: 'b-2', state: 'booked', may: ['edit', 'cancel'] }])
		const wrapper = await section()
		await pick(wrapper, 42)

		const belongs = select(wrapper, 'Belongs to')
		const booking = belongs.props('options').find((/** @type {any} */ one) => one.type === 'booking')
		expect(belongs.props('options').filter((/** @type {any} */ one) => one.type === 'booking')).toHaveLength(1)
		expect(booking.label).toContain('Booking')
		await choose(wrapper, 'photo')
		await belongs.vm.$emit('update:modelValue', booking)
		await button(wrapper, 'Attach').vm.$emit('click')
		await flushPromises()

		expect(attachDocument).toHaveBeenCalledWith('v-1', { file_id: 42, kind: 'photo', linked_type: 'booking', linked_uuid: 'b-1' })
	})

	/**
	 * Done when: a driver attaches a receipt to an entry they entered. Only the rows the server
	 * says are theirs to change, and one of them is required: the vehicle's own papers take `edit`.
	 */
	it('offers a driver only their own entries and bookings, and attaches nothing to the vehicle itself', async () => {
		vi.mocked(readTimeline).mockImplementation(async (uuid, { type }) => ({
			rows: type === 'maintenance' ? [{ ...WORK, may: [] }] : type === 'energy' ? [FILL] : [],
			next: null,
		}))
		vi.mocked(listBookings).mockResolvedValue([TAKEN])
		const wrapper = await section(DRIVEN)
		await pick(wrapper, 42)

		const belongs = select(wrapper, 'Belongs to')
		expect(belongs.props('options').map((/** @type {any} */ one) => one.id)).toEqual(['b-1', 'e-1'])
		await choose(wrapper, 'receipt')
		expect(button(wrapper, 'Attach').props('disabled')).toBe(true)
		await belongs.vm.$emit('update:modelValue', belongs.props('options')[1])
		expect(button(wrapper, 'Attach').props('disabled')).toBe(false)
	})

	/** On a busy pool the newest page of fill-ups may all be somebody else's. */
	it('reads on for a driver whose own entries are older than the newest page', async () => {
		vi.mocked(readTimeline).mockImplementation(async (uuid, { type, cursor }) => type !== 'energy'
			? { rows: [], next: null }
			: cursor === null ? { rows: [{ ...FILL, may: [] }], next: 'c-2' } : { rows: [FILL], next: 'c-3' })
		const wrapper = await section(DRIVEN)
		await pick(wrapper, 42)

		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['e-1'])
		expect(readTimeline).toHaveBeenCalledWith('v-1', { type: 'energy', cursor: 'c-2' })
		expect(readTimeline).not.toHaveBeenCalledWith('v-1', { type: 'energy', cursor: 'c-3' })
	})

	/** An invoice filed late belongs to a record far behind the newest page. */
	it('searches the whole history for what it belongs to', async () => {
		const OLDER = { ...WORK, occurred_at: 1705316400, maintenance: { uuid: 'm-0', title: 'Timing belt' } }
		vi.mocked(readTimeline).mockImplementation(async (uuid, { type, cursor }) => type !== 'maintenance'
			? { rows: [], next: null }
			: cursor === null ? { rows: [WORK], next: 'c-2' } : { rows: [OLDER], next: null })
		const wrapper = await section()
		await pick(wrapper, 42)
		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['m-1'])

		await select(wrapper, 'Belongs to').vm.$emit('search', 'belt')
		await flushPromises()

		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['m-0'])
		expect(listBookings).toHaveBeenCalledWith('v-1', { from: 0 })

		await select(wrapper, 'Belongs to').vm.$emit('search', '')
		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['m-1'])
	})

	/** The history is read once a dialog, not once a keystroke. */
	it('reads the history once however often the search changes', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)
		vi.mocked(readTimeline).mockClear()

		await select(wrapper, 'Belongs to').vm.$emit('search', 'ins')
		await select(wrapper, 'Belongs to').vm.$emit('search', 'insp')
		await flushPromises()

		expect(readTimeline).toHaveBeenCalledTimes(3)
		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['m-1'])
	})

	/** Their own may lie further back than the newest pages, where only a search reaches. */
	it('tells a driver with nothing of their own among the newest to search older ones', async () => {
		vi.mocked(readTimeline).mockImplementation(async (uuid, { type }) => ({ rows: type === 'maintenance' ? [{ ...WORK, may: [] }] : [], next: null }))
		const wrapper = await section(DRIVEN)
		await pick(wrapper, 42)

		expect(select(wrapper, 'Belongs to').props('options')).toEqual([])
		expect(wrapper.get('.dialog').findComponent(NcNoteCard).props('text')).toBe('You can attach a paper only to an entry or a booking of your own. None is among the newest; type to search older ones.')
	})

	/** The vehicle itself is the manager's default, so with no rows there is nothing to choose. */
	it('offers a manager no choice on a vehicle with no rows', async () => {
		vi.mocked(readTimeline).mockResolvedValue({ rows: [], next: null })
		const wrapper = await section()
		await pick(wrapper, 42)

		expect(select(wrapper, 'Belongs to')).toBeUndefined()
	})

	/** A failed read is not the history: the next keystroke asks again. */
	it('reads the history again after a failed read', async () => {
		const wrapper = await section()
		await pick(wrapper, 42)
		vi.mocked(readTimeline).mockClear()
		vi.mocked(readTimeline).mockRejectedValueOnce(new Error('offline'))

		await select(wrapper, 'Belongs to').vm.$emit('search', 'ins')
		await flushPromises()
		await select(wrapper, 'Belongs to').vm.$emit('search', 'insp')
		await flushPromises()

		expect(select(wrapper, 'Belongs to').props('options').map((/** @type {any} */ one) => one.id)).toEqual(['m-1'])
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

describe('saving a document', () => {
	/** Followed, a refusal would replace the app with a page of JSON. */
	it('says in place when the file is gone, and leaves the page where it is', async () => {
		vi.mocked(fetchDocument).mockRejectedValue(new NotFoundError('No such document'))
		const wrapper = await section()

		const click = new MouseEvent('click', { cancelable: true })
		wrapper.get('.documents a').element.dispatchEvent(click)
		await flushPromises()

		expect(click.defaultPrevented).toBe(true)
		expect(fetchDocument).toHaveBeenCalledWith('v-1', 'd-1')
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This document is gone: it was removed, or its file is no longer in the Files of whoever attached it.')
	})

	it('drops a refusal that comes after the screen moved to another vehicle', async () => {
		/** @type {(error: Error) => void} */
		let refuse = () => {}
		vi.mocked(fetchDocument).mockReturnValue(new Promise((resolve, reject) => { refuse = reject }))
		const wrapper = await section()

		await wrapper.get('.documents a').trigger('click')
		await wrapper.setProps({ vehicle: { ...VEHICLE, uuid: 'v-2' } })
		await flushPromises()
		refuse(new NotFoundError('No such document'))
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
	})

	it('says so when somebody is still writing the file', async () => {
		vi.mocked(fetchDocument).mockRejectedValue(new LockedError('Being written, try again'))
		const wrapper = await section()

		await wrapper.get('.documents a').trigger('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('Somebody is saving this file right now. Try again in a moment.')
	})

	it('saves the file under its name when it comes', async () => {
		vi.mocked(fetchDocument).mockResolvedValue(new Blob(['%PDF']))
		vi.stubGlobal('URL', { createObjectURL: () => 'blob:paper', revokeObjectURL: vi.fn() })
		const saved = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
		const wrapper = await section()

		await wrapper.get('.documents a').trigger('click')
		await flushPromises()

		expect(saved).toHaveBeenCalledOnce()
		const anchor = /** @type {HTMLAnchorElement} */ (saved.mock.contexts[0])
		expect([anchor.getAttribute('href'), anchor.download]).toEqual(['blob:paper', 'fahrzeugschein.pdf'])
		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		saved.mockRestore()
		vi.unstubAllGlobals()
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

	/** The toast in the app shell makes the undo; the section shows what it brought back. */
	it('offers the way back, and shows the list the undo answered', async () => {
		vi.mocked(restoreDocument).mockResolvedValue([REGISTRATION, INVOICE, GONE])
		const wrapper = await section()
		const store = useVehiclesStore()

		await button(wrapper, 'Remove fahrzeugschein.pdf').vm.$emit('click')
		await flushPromises()
		expect(store.detached).toEqual({ vehicle: 'v-1', document: 'd-1' })

		await store.restore()
		await flushPromises()

		expect(wrapper.findAll('.documents a').map((one) => one.text())).toEqual(['fahrzeugschein.pdf', 'invoice.pdf'])
		expect(wrapper.emitted('listed')?.at(-1)).toEqual([[REGISTRATION, INVOICE, GONE]])
	})

	it('leaves alone a list the undo answered for another vehicle', async () => {
		vi.mocked(restoreDocument).mockResolvedValue([REGISTRATION])
		const wrapper = await section()
		const store = useVehiclesStore()
		await store.detach('v-2', REGISTRATION)

		await store.restore()
		await flushPromises()

		expect(wrapper.findAll('.documents a').map((one) => one.text())).toEqual(['fahrzeugschein.pdf', 'invoice.pdf'])
	})
})
