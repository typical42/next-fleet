/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import EntrySheet from '../components/EntrySheet.vue'
import InboxSheet from '../components/InboxSheet.vue'
import { attachDocument, readInbox, thumbnailUrl } from '../services/api.js'
import { useInboxStore } from '../store/inbox.js'
import InboxView from './InboxView.vue'

// The network is the api client's own seam (api.spec.js); the store is left real, because the
// navigation's count is read off it.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	attachDocument: vi.fn(),
	readInbox: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', plate: 'B-XY 123', may: ['view', 'log', 'edit'] }
const OTHER = { uuid: 'v-2', plate: 'M-AB 1', may: ['view', 'log'] }

const PHOTO = { file_id: 42, name: 'IMG_0815.jpg', mime: 'image/jpeg', mtime: 1788300000, size: 2048 }
const BILL = { file_id: 43, name: 'Werkstatt.pdf', mime: 'application/pdf', mtime: 1788200000, size: 4096 }
const FOLDER = { file_id: 7, path: '/Belege R&D' }

/**
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the screen, once the inbox was read
 */
async function screen() {
	const wrapper = shallowMount(InboxView, {
		props: { vehicles: [VEHICLE, OTHER] },
		global: {
			renderStubDefaultSlot: true,
			// A stub renders only the default slot, and the way out of an empty state is in `action`.
			stubs: { NcEmptyContent: { props: ['name', 'description'], template: '<div><slot name="action" /></div>' } },
		},
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the screen
 * @return {string[]} the names on the tiles, in order
 */
function tiles(wrapper) {
	return wrapper.findAll('.inbox__file').map((one) => one.find('.inbox__name').text())
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(readInbox).mockResolvedValue({ folder: FOLDER, files: [PHOTO, BILL], count: 2 })
	vi.mocked(attachDocument).mockResolvedValue([])
})

describe('the inbox screen', () => {
	/** Read again on opening: files arrive by auto-upload while the app is open elsewhere. */
	it('shows each waiting file as a thumbnail, newest first', async () => {
		const wrapper = await screen()

		expect(readInbox).toHaveBeenCalledOnce()
		expect(tiles(wrapper)).toEqual(['IMG_0815.jpg', 'Werkstatt.pdf'])
		expect(wrapper.findAll('.inbox__file img').map((one) => one.attributes('src')))
			.toEqual([thumbnailUrl(PHOTO), thumbnailUrl(BILL)])
	})

	it('says how many more wait than it shows', async () => {
		vi.mocked(readInbox).mockResolvedValue({ folder: FOLDER, files: [PHOTO, BILL], count: 130 })

		const wrapper = await screen()

		expect(wrapper.text()).toContain('The newest 2 of 130 files in /Belege R&D')
	})

	it('says where files come from while none waits', async () => {
		vi.mocked(readInbox).mockResolvedValue({ folder: FOLDER, files: [], count: 0 })

		const wrapper = await screen()

		/** @type {any} */
		const empty = wrapper.findComponent(NcEmptyContent)
		expect(empty.props('name')).toBe('Nothing waiting')
		expect(empty.props('description')).toContain('/Belege R&D')
	})

	/** A folder deleted in Files, or one never chosen: the settings page is where it is chosen. */
	it('sends the person to their settings when there is no inbox folder', async () => {
		vi.mocked(readInbox).mockResolvedValue({ folder: null, files: [], count: 0 })

		const wrapper = await screen()

		/** @type {any} */
		const empty = wrapper.findComponent(NcEmptyContent)
		expect(empty.props('name')).toBe('No inbox folder')
		// Nextcloud's own button as a link, so it looks and focuses as every other button does.
		/** @type {any} */
		const settings = wrapper.findAllComponents(NcButton).find((one) => one.text() === 'Open personal settings')
		expect(settings.props('href')).toContain('/settings/user/additional')
	})

	it('says what went wrong when the inbox cannot be read, and tries again', async () => {
		vi.mocked(readInbox).mockRejectedValueOnce(new Error('The server answered 500'))

		const wrapper = await screen()

		/** @type {any} */
		const empty = wrapper.findComponent(NcEmptyContent)
		expect(empty.props('description')).toBe('The server answered 500')

		await wrapper.findAllComponents(NcButton).find((one) => one.text() === 'Try again')?.vm.$emit('click')
		await flushPromises()

		expect(tiles(wrapper)).toEqual(['IMG_0815.jpg', 'Werkstatt.pdf'])
	})

	/** The first tap opens the sheet on that file, offering the fleet. */
	it('opens the sheet on the file tapped', async () => {
		const wrapper = await screen()

		await wrapper.findAll('.inbox__file')[1].trigger('click')

		/** @type {any} */
		const sheet = wrapper.findComponent(InboxSheet)
		expect(sheet.props('file')).toEqual(BILL)
		expect(sheet.props('vehicles')).toEqual([VEHICLE, OTHER])
		expect(sheet.props('preferred')).toBe(null)
	})

	/** An attached file is waiting no more; the next one goes to the same car unless changed. */
	it('takes an attached file off the grid and the count, and offers its vehicle next', async () => {
		const wrapper = await screen()
		await wrapper.findAll('.inbox__file')[0].trigger('click')

		await wrapper.findComponent(InboxSheet).vm.$emit('attached', 'v-2')

		expect(wrapper.findComponent(InboxSheet).exists()).toBe(false)
		expect(tiles(wrapper)).toEqual(['Werkstatt.pdf'])
		expect(useInboxStore().count).toBe(1)

		await wrapper.findAll('.inbox__file')[0].trigger('click')
		/** @type {any} */
		const sheet = wrapper.findComponent(InboxSheet)
		expect(sheet.props('preferred')).toBe('v-2')
	})

	/** The server sends the newest hundred; once those are attached, the older ones come next. */
	it('reads the inbox again when the shown files are gone and more wait', async () => {
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: FOLDER, files: [PHOTO], count: 2 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: FOLDER, files: [BILL], count: 1 })
		const wrapper = await screen()
		await wrapper.find('.inbox__file').trigger('click')

		await wrapper.findComponent(InboxSheet).vm.$emit('attached', 'v-1')
		await flushPromises()

		expect(readInbox).toHaveBeenCalledTimes(2)
		expect(tiles(wrapper)).toEqual(['Werkstatt.pdf'])
	})

	describe('logging a new cost from a file', () => {
		/**
		 * @return {Promise<import('@vue/test-utils').VueWrapper>} the screen, the entry sheet open on
		 * a fill-up of OTHER from PHOTO
		 */
		async function logging() {
			const wrapper = await screen()
			await wrapper.find('.inbox__file').trigger('click')
			await wrapper.findComponent(InboxSheet).vm.$emit('log', { vehicle: 'v-2', type: 'energy', kind: 'receipt' })
			return wrapper
		}

		it('opens the entry sheet on that cost, dated when the file was saved', async () => {
			const wrapper = await logging()

			/** @type {any} */
			const entry = wrapper.findComponent(EntrySheet)
			expect(wrapper.findComponent(InboxSheet).exists()).toBe(false)
			expect(entry.props('vehicle')).toEqual(OTHER)
			expect(entry.props('receipt')).toEqual({ kind: 'energy', at: PHOTO.mtime })
		})

		it('files the file on the entry once it is saved', async () => {
			const wrapper = await logging()

			await wrapper.findComponent(EntrySheet).vm.$emit('saved', { uuid: 'e-9' })
			await wrapper.findComponent(EntrySheet).vm.$emit('close')
			await flushPromises()

			expect(attachDocument).toHaveBeenCalledWith('v-2', { file_id: 42, kind: 'receipt', linked_type: 'energy', linked_uuid: 'e-9' })
			expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
			expect(tiles(wrapper)).toEqual(['Werkstatt.pdf'])
			expect(useInboxStore().lastVehicle).toBe('v-2')
		})

		/** The entry is written and stays; the file waits, to be attached to it by hand. */
		it('keeps the entry and says so when the file cannot be filed on it', async () => {
			vi.mocked(attachDocument).mockRejectedValue(new Error('The file\'s storage is full'))
			const wrapper = await logging()

			await wrapper.findComponent(EntrySheet).vm.$emit('saved', { uuid: 'e-9' })
			await wrapper.findComponent(EntrySheet).vm.$emit('close')
			await flushPromises()

			/** @type {any} */
			const note = wrapper.findComponent(NcNoteCard)
			expect(note.props('type')).toBe('error')
			expect(note.props('text')).toContain('is saved, but IMG_0815.jpg could not')
			expect(note.props('text')).toContain('The file\'s storage is full')
			expect(tiles(wrapper)).toEqual(['IMG_0815.jpg', 'Werkstatt.pdf'])

			// Attached by hand, it is no longer a failure to speak of.
			await wrapper.find('.inbox__file').trigger('click')
			await wrapper.findComponent(InboxSheet).vm.$emit('attached', 'v-2')
			await flushPromises()

			expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		})

		it('files nothing when the entry sheet is closed unsaved', async () => {
			const wrapper = await logging()

			await wrapper.findComponent(EntrySheet).vm.$emit('close')
			await flushPromises()

			expect(attachDocument).not.toHaveBeenCalled()
			expect(tiles(wrapper)).toEqual(['IMG_0815.jpg', 'Werkstatt.pdf'])
		})
	})
})
