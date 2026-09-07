/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import App from './App.vue'
import UndoToast from './components/UndoToast.vue'
import VehicleList from './components/VehicleList.vue'
import { deleteVehicle, listVehicles } from './services/api.js'
import { useVehiclesStore } from './store/index.js'
import OverviewView from './views/OverviewView.vue'
import VehicleView from './views/VehicleView.vue'

// The network is the api client's own seam (api.spec.js); the store is left real, because which
// vehicles the shell can show is the store's own rule. Everything the modules below this one
// import has to be on the mock - an export a mount reaches for and does not find is an error
// naming the module, not the missing name.
vi.mock('./services/api.js', async (original) => ({
	...await original(),
	createVehicle: vi.fn(),
	deleteVehicle: vi.fn(),
	getPreferences: vi.fn(),
	getVehicle: vi.fn(),
	listVehicles: vi.fn(),
	recordReading: vi.fn(),
	restoreVehicle: vi.fn(),
	updateVehicle: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }
/** The same vehicle as the delete left it: one token further on, and out of the fleet. */
const DELETED = { ...VEHICLE, updated_at: 1700000800 }

/**
 * The shell, mounted and past its first read, showing the vehicle it was told to.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper, on that vehicle's screen
 */
async function shell() {
	const wrapper = shallowMount(App, {
		// A stub renders no slot of its own, and the whole app sits in these two - so they are
		// rendered and everything below them stays a stub.
		global: {
			stubs: {
				NcContent: { template: '<div><slot /></div>' },
				NcAppNavigation: { template: '<div><slot /><slot name="list" /></div>' },
				NcAppContent: { template: '<div><slot /></div>' },
			},
		},
	})
	await flushPromises()
	await wrapper.findComponent(VehicleList).vm.$emit('select', VEHICLE.uuid)

	return wrapper
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(listVehicles).mockResolvedValue([VEHICLE])
	// The delete advances the token, and the one it answers with is the only one the undo is
	// checked against (docs/architecture.md#concurrency).
	vi.mocked(deleteVehicle).mockResolvedValue(DELETED)
})

describe('the app shell', () => {
	/**
	 * A sold vehicle leaves the overview (docs/ui.md), so the screen of the one just disposed of
	 * in the edit sheet has no entry in the list any more - and staying on it would strand the
	 * user on a vehicle nothing can navigate back to.
	 */
	it('leaves the screen of a vehicle that has left the fleet', async () => {
		const wrapper = await shell()
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)

		useVehiclesStore().upsert({ ...VEHICLE, lifecycle: 'disposed' })
		await flushPromises()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
	})

	/**
	 * A delete takes the vehicle's screen with it, for the same reason - and the undo toast is
	 * what is left offering the way back, so it hangs in the shell rather than under the screen
	 * that asked for the delete (src/components/UndoToast.vue). What that screen emits on its way
	 * out reaches nobody: Vue drops an event from a component it has already unmounted.
	 */
	it('keeps the undo toast when a delete takes the screen that asked for it', async () => {
		const wrapper = await shell()

		await useVehiclesStore().remove(VEHICLE)
		await flushPromises()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(UndoToast).exists()).toBe(true)
	})
})
