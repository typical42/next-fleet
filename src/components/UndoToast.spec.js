/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { ChangedError, deleteEntry, deleteVehicle, detachDocument, getVehicle, restoreDocument, restoreEntry, restoreVehicle, runImport, undoImport } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import UndoToast from './UndoToast.vue'

// The network is the api client's own seam (api.spec.js), and the store is left real: the way back
// is the token the store holds, so the wiring through it is part of what is under test.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	deleteEntry: vi.fn(),
	deleteVehicle: vi.fn(),
	detachDocument: vi.fn(),
	getVehicle: vi.fn(),
	restoreDocument: vi.fn(),
	restoreEntry: vi.fn(),
	restoreVehicle: vi.fn(),
	runImport: vi.fn(),
	undoImport: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }
/** What an import answered it created. */
const CREATED = [{ type: 'energy', uuid: 'e-1' }, { type: 'expense', uuid: 'x-1' }]
/** The same vehicle as the delete left it: one token further on, and out of the fleet. */
const DELETED = { ...VEHICLE, updated_at: 1700000800 }
/** @type {import('../services/api.js').Document} */
const PAPER = { uuid: 'd-1', kind: 'manual', file_id: 11, name: 'handbuch.pdf', mime: 'application/pdf', linked_type: null, linked_uuid: null, may: ['detach'] }

/**
 * The toast, after a vehicle was deleted through the store the way the sheet deletes one.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the mounted toast
 */
async function toast() {
	const store = useVehiclesStore()
	store.upsert(VEHICLE)
	await store.remove(VEHICLE)

	// A button says what it does in its own slot, and that is how the two are told apart.
	return shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted toast
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when the toast has none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(deleteVehicle).mockResolvedValue(DELETED)
	vi.mocked(restoreVehicle).mockResolvedValue(VEHICLE)
	vi.mocked(getVehicle).mockResolvedValue(VEHICLE)
	vi.mocked(deleteEntry).mockImplementation(async (uuid, type, entry) => ({ ...entry, updated_at: 1700000200 }))
	vi.mocked(restoreEntry).mockImplementation(async (uuid, type, entry) => entry)
})

describe('the undo toast', () => {
	/** Nothing was deleted, so there is nothing to say and nothing to take back. */
	it('says nothing until a vehicle is deleted', () => {
		const wrapper = shallowMount(UndoToast)

		expect(wrapper.find('.toast').exists()).toBe(false)
	})

	/**
	 * Nothing asks "are you sure?" (docs/ui.md), so what is deleted has to be named afterwards -
	 * by the name the rest of the app calls it (src/utils/format.js), not by its uuid.
	 */
	it('names the vehicle it can take back', async () => {
		const wrapper = await toast()

		expect(wrapper.text()).toContain('B-XY 123 was deleted.')
		expect(button(wrapper, 'Undo')).toBeDefined()
	})

	/**
	 * Undo is checked against the token the delete answered with and no other one
	 * (docs/architecture.md#concurrency), so the vehicle the toast hands back is the one the delete
	 * left behind rather than the one the screen had.
	 */
	it('brings the vehicle back under the token the delete answered with', async () => {
		const wrapper = await toast()

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(restoreVehicle).toHaveBeenCalledWith(expect.objectContaining({ updated_at: 1700000800 }))
		expect(useVehiclesStore().list.map((one) => one.uuid)).toEqual(['v-1'])
		expect(wrapper.find('.toast').exists()).toBe(false)
	})

	/**
	 * Somebody else moved the row on, so the token matches nothing and the vehicle stays deleted.
	 * Closing on that would claim an undo that did not happen, and offering the click again would
	 * be offering the same refusal.
	 */
	it('says the way back is gone when the undo is refused', async () => {
		vi.mocked(restoreVehicle).mockRejectedValue(new Error('Changed since you read it'))
		const wrapper = await toast()

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(wrapper.text()).toContain('B-XY 123 could not be brought back: Changed since you read it')
		expect(button(wrapper, 'Undo')).toBeUndefined()
		expect(useVehiclesStore().list).toEqual([])
	})

	/**
	 * The refusal was about the row the last delete left behind, and this is a different row: an
	 * offer that arrives already refused would strand a vehicle that is perfectly restorable, and
	 * M1 has no trash view to reach it by.
	 */
	it('makes a fresh offer for the next vehicle after a refused undo', async () => {
		vi.mocked(restoreVehicle).mockRejectedValueOnce(new Error('Changed since you read it'))
		const wrapper = await toast()
		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		const store = useVehiclesStore()
		const second = { ...VEHICLE, uuid: 'v-2', plate: 'M-EV 7' }
		store.upsert(second)
		vi.mocked(deleteVehicle).mockResolvedValue({ ...second, updated_at: 1700000900 })
		await store.remove(second)
		await flushPromises()

		expect(wrapper.text()).toContain('M-EV 7 was deleted.')
		expect(button(wrapper, 'Undo')).toBeDefined()
	})

	/**
	 * The second click would be checked against a row that is no longer deleted, so the server
	 * refuses it (lib/Db/BaseMapper.php) - and an undo that worked would end up saying it did not.
	 */
	it('asks once while the first undo is still in flight', async () => {
		vi.mocked(restoreVehicle).mockReturnValue(new Promise(() => {}))
		const wrapper = await toast()

		await button(wrapper, 'Undo').vm.$emit('click')
		await button(wrapper, 'Undo').vm.$emit('click')

		expect(restoreVehicle).toHaveBeenCalledTimes(1)
	})

	/**
	 * A live region has to be in the page before its content changes, or the change is not
	 * announced - and with nothing asking "are you sure?", this toast is the only word a screen
	 * reader gets that the vehicle is gone.
	 */
	it('keeps the region it announces itself in', () => {
		const wrapper = shallowMount(UndoToast)

		expect(wrapper.find('[role="status"]').exists()).toBe(true)
	})

	/**
	 * An Entry deleted from its row gets the same way back (docs/ui.md). Its row is gone with it, so
	 * the toast says what kind of thing went rather than naming it.
	 */
	it('offers an Entry back under the token its delete answered with', async () => {
		const store = useVehiclesStore()
		store.upsert(VEHICLE)
		await store.strike('v-1', 'energy', { uuid: 'e-1', updated_at: 1700000100 })
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(deleteEntry).toHaveBeenCalledWith('v-1', 'energy', { uuid: 'e-1', updated_at: 1700000100 })
		expect(wrapper.text()).toContain('The entry was deleted.')

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(restoreEntry).toHaveBeenCalledWith('v-1', 'energy', expect.objectContaining({ updated_at: 1700000200 }))
		// The timeline reads itself again off this: the row it lost is back.
		expect(store.restored).toBe(1)
		expect(wrapper.find('.toast').exists()).toBe(false)
	})

	/**
	 * A reminder is deleted from the banner and taken back the same way. It moves no counter, so
	 * the vehicle is not read again, and the toast does not call it an entry.
	 */
	it('offers a reminder back, and says it is one', async () => {
		const store = useVehiclesStore()
		store.upsert(VEHICLE)
		await store.strike('v-1', 'reminder', { uuid: 'r-1', updated_at: 1700000100 })
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(getVehicle).not.toHaveBeenCalled()
		expect(wrapper.text()).toContain('The reminder was deleted.')

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(restoreEntry).toHaveBeenCalledWith('v-1', 'reminder', expect.objectContaining({ updated_at: 1700000200 }))
		expect(getVehicle).not.toHaveBeenCalled()
		expect(store.restored).toBe(1)
	})

	/**
	 * _Remove_ on a paper gets the way back every other delete has. The papers section is not the
	 * toast's, so the list the restore answered is held for it.
	 */
	it('offers a removed paper back, and hands on the list it answered', async () => {
		vi.mocked(detachDocument).mockResolvedValue([])
		vi.mocked(restoreDocument).mockResolvedValue([PAPER])
		const store = useVehiclesStore()
		await store.detach('v-1', PAPER)
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(detachDocument).toHaveBeenCalledWith('v-1', 'd-1')
		expect(wrapper.text()).toContain('The document was removed.')

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(restoreDocument).toHaveBeenCalledWith('v-1', 'd-1')
		expect(store.refiled).toEqual({ vehicle: 'v-1', list: [PAPER] })
		expect(wrapper.find('.toast').exists()).toBe(false)
	})

	it('says when a removed paper could not be brought back', async () => {
		vi.mocked(detachDocument).mockResolvedValue([])
		vi.mocked(restoreDocument).mockRejectedValue(new Error('Not yours'))
		const store = useVehiclesStore()
		await store.detach('v-1', PAPER)
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(wrapper.text()).toContain('The document could not be brought back: Not yours')
		expect(store.refiled).toBeNull()
	})

	/** Under Logbook Mode a trip is voided rather than deleted, and the toast says which. */
	it('says a trip under Logbook Mode was voided', async () => {
		vi.mocked(getVehicle).mockResolvedValue({ ...VEHICLE, logbook_mode: true })
		const store = useVehiclesStore()
		await store.strike('v-1', 'trip', { uuid: 't-1', updated_at: 1700000100 })
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(wrapper.text()).toContain('The trip was voided.')
	})

	/** One offer at a time: the next delete, of either kind, takes the place of the last. */
	it('keeps only the latest offer', async () => {
		const store = useVehiclesStore()
		await store.strike('v-1', 'energy', { uuid: 'e-1', updated_at: 1700000100 })
		store.upsert(VEHICLE)
		await store.remove(VEHICLE)

		expect(store.struck).toBeNull()
		expect(store.deleted).not.toBeNull()
	})

	/**
	 * The import's result is this toast (docs/ui.md, "Importing"): what was created and what was
	 * left out, and the one way to take all of it back.
	 */
	it('says what an import created and takes all of it back', async () => {
		const store = useVehiclesStore()
		vi.mocked(runImport).mockResolvedValue({ counts: { new: 12, duplicate: 3, unreadable: 1, creates: 12 }, created: CREATED })
		vi.mocked(undoImport).mockResolvedValue({ undone: 2 })
		await store.bring('v-1', /** @type {any} */ ({ etag: 'e-1' }))
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(wrapper.text()).toContain('Entries imported: 12. Rows already there, skipped: 3. Rows not readable: 1.')

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(undoImport).toHaveBeenCalledWith('v-1', CREATED)
		expect(wrapper.find('.toast').exists()).toBe(false)
	})

	/** After that, its entries are deleted one by one like any other (docs/architecture.md#import). */
	it('says an import changed since is no longer undone as a whole', async () => {
		const store = useVehiclesStore()
		vi.mocked(runImport).mockResolvedValue({ counts: { new: 2, duplicate: 0, unreadable: 0, creates: 2 }, created: CREATED })
		vi.mocked(undoImport).mockRejectedValue(new ChangedError('energy e-1 is not a live entry'))
		await store.bring('v-1', /** @type {any} */ ({ etag: 'e-1' }))
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		await button(wrapper, 'Undo').vm.$emit('click')
		await flushPromises()

		expect(wrapper.text()).toContain('This import can no longer be undone as a whole, because some of its entries were deleted since. Delete the rest one by one instead.')
		expect(button(wrapper, 'Undo')).toBeUndefined()
	})

	/** Rows entered since the preview are duplicates under the hold, so an import may create none. */
	it('offers no undo of an import that created nothing', async () => {
		const store = useVehiclesStore()
		vi.mocked(runImport).mockResolvedValue({ counts: { new: 0, duplicate: 2, unreadable: 0, creates: 0 }, created: [] })
		await store.bring('v-1', /** @type {any} */ ({ etag: 'e-1' }))
		const wrapper = shallowMount(UndoToast, { global: { renderStubDefaultSlot: true } })

		expect(wrapper.text()).toContain('Entries imported: 0. Rows already there, skipped: 2.')
		expect(button(wrapper, 'Undo')).toBeUndefined()
		expect(button(wrapper, 'Dismiss')).toBeDefined()
	})

	/** The offer is made once, and dismissing it is the answer that takes nothing back. */
	it('goes when it is dismissed, and takes nothing back', async () => {
		const wrapper = await toast()

		await button(wrapper, 'Dismiss').vm.$emit('click')

		expect(wrapper.find('.toast').exists()).toBe(false)
		expect(restoreVehicle).not.toHaveBeenCalled()
	})
})
