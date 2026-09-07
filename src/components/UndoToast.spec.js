/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { deleteVehicle, restoreVehicle } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import UndoToast from './UndoToast.vue'

// The network is the api client's own seam (api.spec.js), and the store is left real: the way back
// is the token the store holds, so the wiring through it is part of what is under test.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	deleteVehicle: vi.fn(),
	restoreVehicle: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }
/** The same vehicle as the delete left it: one token further on, and out of the fleet. */
const DELETED = { ...VEHICLE, updated_at: 1700000800 }

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

	/** The offer is made once, and dismissing it is the answer that takes nothing back. */
	it('goes when it is dismissed, and takes nothing back', async () => {
		const wrapper = await toast()

		await button(wrapper, 'Dismiss').vm.$emit('click')

		expect(wrapper.find('.toast').exists()).toBe(false)
		expect(restoreVehicle).not.toHaveBeenCalled()
	})
})
