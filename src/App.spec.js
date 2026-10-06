/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import App from './App.vue'
import UndoToast from './components/UndoToast.vue'
import VehicleList from './components/VehicleList.vue'
import VehicleSheet from './components/VehicleSheet.vue'
import { deleteVehicle, getPreferences, listVehicles, readInbox } from './services/api.js'
import { useVehiclesStore } from './store/index.js'
import { usePreferencesStore } from './store/preferences.js'
import CostsView from './views/CostsView.vue'
import InboxView from './views/InboxView.vue'
import OverviewView from './views/OverviewView.vue'
import ReportsView from './views/ReportsView.vue'
import VehicleView from './views/VehicleView.vue'

// The store stays real: which vehicles the shell can show is its rule. Every export a mount reaches
// for must be on the mock, or the error names the module, not the missing export.
vi.mock('./services/api.js', async (original) => ({
	...await original(),
	createVehicle: vi.fn(),
	deleteVehicle: vi.fn(),
	getPreferences: vi.fn(),
	getVehicle: vi.fn(),
	listVehicles: vi.fn(),
	readInbox: vi.fn(),
	recordReading: vi.fn(),
	restoreVehicle: vi.fn(),
	updateVehicle: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }
const SETTINGS = {
	preferences: { jurisdiction: 'de', dismissed_hints: [], dismissed_logbook_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null, inbox_folder: null },
	jurisdictions: [],
}
/** Someone who chose an inbox folder on their settings page. */
const INBOXED = { ...SETTINGS, preferences: { ...SETTINGS.preferences, inbox_folder: 7 } }
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
				NcAppNavigation: { template: '<div><slot /><slot name="list" /><slot name="footer" /></div>' },
				NcAppNavigationList: { template: '<ul><slot /></ul>' },
				NcAppContent: { template: '<div><slot /></div>' },
			},
		},
	})
	await flushPromises()
	await wrapper.findComponent(VehicleList).vm.$emit('select', VEHICLE.uuid)

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted shell
 * @return {any} the navigation entry that opens the reports
 */
function reports(wrapper) {
	return entry(wrapper, 'Reports')
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted shell
 * @param {string} name - the entry's label
 * @return {any} the navigation entry by that label
 */
function entry(wrapper, name) {
	return wrapper.findAllComponents(NcAppNavigationItem).find((one) => one.props('name') === name)
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(listVehicles).mockResolvedValue([VEHICLE])
	// The undo checks the token the delete answers with (docs/architecture.md#concurrency).
	vi.mocked(deleteVehicle).mockResolvedValue(DELETED)
})

describe('the app shell', () => {
	it('reads the preferences along with the fleet', async () => {
		vi.mocked(getPreferences).mockResolvedValue({
			preferences: { jurisdiction: 'de', dismissed_hints: [VEHICLE.uuid], dismissed_logbook_hints: [], reclaim_vat: false, kpi_period: 'last-12', grid_factor: null, inbox_folder: null },
			jurisdictions: [],
		})

		await shell()

		expect(usePreferencesStore().isDismissed(VEHICLE.uuid)).toBe(true)
	})

	it('shows the fleet even when the preferences could not be read', async () => {
		vi.mocked(getPreferences).mockRejectedValue(new Error('nope'))

		const wrapper = await shell()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)
		expect(usePreferencesStore().loaded).toBe(false)
	})

	/** A reminder notification links to its vehicle by `?vehicle=` (lib/Notification/Notifier.php). */
	it('opens the vehicle the link names', async () => {
		window.history.replaceState(null, '', '/apps/nextfleet/?vehicle=' + VEHICLE.uuid)
		try {
			const wrapper = shallowMount(App, {
				global: {
					stubs: {
						NcContent: { template: '<div><slot /></div>' },
						NcAppContent: { template: '<div><slot /></div>' },
					},
				},
			})
			await flushPromises()

			/** @type {any} */
			const screen = wrapper.findComponent(VehicleView)
			expect(screen.props('vehicle')).toEqual(VEHICLE)
		} finally {
			window.history.replaceState(null, '', '/')
		}
	})

	/** Staying would strand the user on a vehicle nothing in the navigation leads back to. */
	it('leaves the screen of a vehicle that has left the fleet', async () => {
		const wrapper = await shell()
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)

		useVehiclesStore().upsert({ ...VEHICLE, lifecycle: 'disposed' })
		await flushPromises()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
	})

	/** Its screen stays: its edit sheet is where the sale is set back. */
	it('opens a disposed vehicle from the overview', async () => {
		const SOLD = { ...VEHICLE, uuid: 'v-2', lifecycle: 'disposed' }
		vi.mocked(listVehicles).mockResolvedValue([VEHICLE, SOLD])
		const wrapper = await shell()
		await entry(wrapper, 'Overview').vm.$emit('click')
		await flushPromises()

		/** @type {any} */
		const overview = wrapper.findComponent(OverviewView)
		expect(overview.props('disposed').map((/** @type {{ uuid: string }} */ one) => one.uuid)).toEqual([SOLD.uuid])
		await overview.vm.$emit('select', SOLD.uuid)

		/** @type {any} */
		const screen = wrapper.findComponent(VehicleView)
		expect(screen.props('vehicle').uuid).toBe(SOLD.uuid)
		/** @type {any} */
		const list = wrapper.findComponent(VehicleList)
		expect(list.props('vehicles').map((/** @type {{ uuid: string }} */ one) => one.uuid)).toEqual([VEHICLE.uuid])
	})

	it('leaves a vehicle set back from the disposed list when it is disposed of again', async () => {
		const SOLD = { ...VEHICLE, uuid: 'v-2', lifecycle: 'disposed' }
		vi.mocked(listVehicles).mockResolvedValue([VEHICLE, SOLD])
		const wrapper = await shell()
		await entry(wrapper, 'Overview').vm.$emit('click')
		await flushPromises()
		await wrapper.findComponent(OverviewView).vm.$emit('select', SOLD.uuid)

		useVehiclesStore().upsert({ ...SOLD, lifecycle: 'active' })
		await flushPromises()
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)
		useVehiclesStore().upsert({ ...SOLD, lifecycle: 'disposed' })
		await flushPromises()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
	})

	it('opens the reports on the whole fleet', async () => {
		const SOLD = { ...VEHICLE, uuid: 'v-2', lifecycle: 'disposed' }
		vi.mocked(listVehicles).mockResolvedValue([VEHICLE, SOLD])
		const wrapper = await shell()

		await reports(wrapper).vm.$emit('click')

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		/** @type {any} */
		const screen = wrapper.findComponent(ReportsView)
		expect(screen.props('vehicles').map((/** @type {{ uuid: string }} */ one) => one.uuid))
			.toEqual([VEHICLE.uuid, SOLD.uuid])
		expect(reports(wrapper).props('active')).toBe(true)
	})

	it('leaves the reports for the vehicle picked in the navigation', async () => {
		const wrapper = await shell()
		await reports(wrapper).vm.$emit('click')

		await wrapper.findComponent(VehicleList).vm.$emit('select', VEHICLE.uuid)

		expect(wrapper.findComponent(ReportsView).exists()).toBe(false)
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)
		expect(reports(wrapper).props('active')).toBe(false)
	})

	it('opens the costs of the vehicle shown, and goes back to it', async () => {
		const wrapper = await shell()

		await wrapper.findComponent(VehicleView).vm.$emit('costs')

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		/** @type {any} */
		const costs = wrapper.findComponent(CostsView)
		expect(costs.props('vehicle')).toEqual(VEHICLE)

		await costs.vm.$emit('back')

		expect(wrapper.findComponent(CostsView).exists()).toBe(false)
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)
	})

	it('leaves the costs for the vehicle picked in the navigation', async () => {
		const wrapper = await shell()
		await wrapper.findComponent(VehicleView).vm.$emit('costs')

		await wrapper.findComponent(VehicleList).vm.$emit('select', VEHICLE.uuid)

		expect(wrapper.findComponent(CostsView).exists()).toBe(false)
		expect(wrapper.findComponent(VehicleView).exists()).toBe(true)
	})

	/** Neither a vehicle nor the reports may be a screen only a reload leaves. */
	it('returns to the overview from a vehicle and from the reports', async () => {
		const wrapper = await shell()
		expect(entry(wrapper, 'Overview').props('active')).toBe(false)

		await entry(wrapper, 'Overview').vm.$emit('click')

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
		expect(entry(wrapper, 'Overview').props('active')).toBe(true)

		await reports(wrapper).vm.$emit('click')
		expect(entry(wrapper, 'Overview').props('active')).toBe(false)

		await entry(wrapper, 'Overview').vm.$emit('click')

		expect(wrapper.findComponent(ReportsView).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
		expect(reports(wrapper).props('active')).toBe(false)
		expect(entry(wrapper, 'Overview').props('active')).toBe(true)
	})

	/** From the navigation and from the overview's empty state alike; the sheet adds it to the store. */
	it('opens the create sheet, and the vehicle it made once it is made', async () => {
		const wrapper = await shell()
		await entry(wrapper, 'Overview').vm.$emit('click')

		await wrapper.findComponent(NcAppNavigationNew).vm.$emit('click')
		await wrapper.findComponent(VehicleSheet).vm.$emit('close')
		expect(wrapper.findComponent(VehicleSheet).exists()).toBe(false)

		await wrapper.findComponent(OverviewView).vm.$emit('new')
		await wrapper.findComponent(VehicleSheet).vm.$emit('created', VEHICLE)

		expect(wrapper.findComponent(VehicleSheet).exists()).toBe(false)
		/** @type {any} */
		const screen = wrapper.findComponent(VehicleView)
		expect(screen.props('vehicle')).toEqual(VEHICLE)
	})

	it('marks the overview when a vehicle leaving the fleet falls back to it', async () => {
		const wrapper = await shell()

		useVehiclesStore().upsert({ ...VEHICLE, lifecycle: 'disposed' })
		await flushPromises()

		expect(entry(wrapper, 'Overview').props('active')).toBe(true)
	})

	/** Without a folder the entry would open a screen that can only send them to their settings. */
	it('offers no inbox while no folder is chosen', async () => {
		vi.mocked(getPreferences).mockResolvedValue(SETTINGS)

		const wrapper = await shell()

		expect(entry(wrapper, 'Inbox')).toBeUndefined()
		expect(readInbox).not.toHaveBeenCalled()
	})

	/** The count is the reason to open it. */
	it('offers the inbox with the count of files waiting in it', async () => {
		vi.mocked(getPreferences).mockResolvedValue(INBOXED)
		vi.mocked(readInbox).mockResolvedValue({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 3 })

		const wrapper = shallowMount(App, {
			global: {
				stubs: {
					NcContent: { template: '<div><slot /></div>' },
					NcAppNavigation: { template: '<div><slot name="list" /><slot name="footer" /></div>' },
					NcAppNavigationList: { template: '<ul><slot /></ul>' },
					NcAppNavigationItem: { props: ['name'], template: '<li class="item">{{ name }}<slot name="counter" /></li>' },
					NcCounterBubble: { props: ['count'], template: '<span class="bubble">{{ count }}</span>' },
				},
			},
		})
		await flushPromises()

		expect(wrapper.findAll('.item').map((one) => one.text())).toEqual(['Overview', 'Inbox3', 'Reports', 'Settings'])
	})

	it('shows that the fleet is loading, not that it is empty', async () => {
		/** @type {(fleet: any[]) => void} */
		let arrive = () => {}
		vi.mocked(listVehicles).mockReturnValue(new Promise((resolve) => { arrive = resolve }))
		const wrapper = shallowMount(App, {
			global: { stubs: { NcContent: { template: '<div><slot /></div>' }, NcAppContent: { template: '<div><slot /></div>' } } },
		})
		await flushPromises()

		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(true)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(false)

		arrive([])
		await flushPromises()

		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(false)
		expect(wrapper.findComponent(OverviewView).exists()).toBe(true)
	})

	it('links to the personal settings from the navigation footer', async () => {
		const wrapper = await shell()

		expect(entry(wrapper, 'Settings').props('href')).toMatch(/\/settings\/user\/additional$/)
	})

	it('opens the inbox screen on the fleet, and leaves it for a vehicle', async () => {
		vi.mocked(getPreferences).mockResolvedValue(INBOXED)
		vi.mocked(readInbox).mockResolvedValue({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 })
		const wrapper = await shell()

		await entry(wrapper, 'Inbox').vm.$emit('click')

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		/** @type {any} */
		const screen = wrapper.findComponent(InboxView)
		expect(screen.props('vehicles')).toEqual([VEHICLE])
		expect(entry(wrapper, 'Inbox').props('active')).toBe(true)
		expect(entry(wrapper, 'Overview').props('active')).toBe(false)

		await wrapper.findComponent(VehicleList).vm.$emit('select', VEHICLE.uuid)

		expect(wrapper.findComponent(InboxView).exists()).toBe(false)
		expect(entry(wrapper, 'Inbox').props('active')).toBe(false)
	})

	/**
	 * The screen that asked cannot offer the undo: Vue drops events from an unmounted component.
	 */
	it('keeps the undo toast when a delete takes the screen that asked for it', async () => {
		const wrapper = await shell()

		await useVehiclesStore().remove(VEHICLE)
		await flushPromises()

		expect(wrapper.findComponent(VehicleView).exists()).toBe(false)
		expect(wrapper.findComponent(UndoToast).exists()).toBe(true)
	})
})
