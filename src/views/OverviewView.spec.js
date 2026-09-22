/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import CompleteHint from '../components/CompleteHint.vue'
import { listFleetReminders } from '../services/api.js'
import OverviewView from './OverviewView.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	listFleetReminders: vi.fn(),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }

/**
 * The rows rendered with what they hold: the list item's own markup is the library's, so a stand-in
 * shows its name in bold and its slots in place.
 *
 * @param {any[]} vehicles - the fleet
 * @return {import('@vue/test-utils').VueWrapper} the mounted overview
 */
function rows(vehicles) {
	return shallowMount(OverviewView, {
		props: { vehicles },
		global: {
			stubs: {
				NcListItem: {
					props: ['name', 'details'],
					template: '<li><b>{{ name }}</b> <slot name="subname" /> {{ details }}</li>',
				},
			},
		},
	})
}

/**
 * A component imported from a .vue file carries no prop types, so what it was handed is read
 * through a helper rather than typed (docs/development.md).
 *
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted overview
 * @return {any} the hint, whether or not it is there
 */
function hint(wrapper) {
	return wrapper.findComponent(CompleteHint)
}

/**
 * Types a key at the page rather than at the screen: the shortcut listens on the window, and
 * where the keystroke was aimed is what decides whether it counts.
 *
 * @param {EventTarget} at - what the keystroke is aimed at
 * @param {string} key - the key pressed
 */
function press(at, key) {
	at.dispatchEvent(new KeyboardEvent('keydown', { key, bubbles: true }))
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.mocked(listFleetReminders).mockResolvedValue([])
})

describe('the overview', () => {
	/**
	 * The hint about vehicles nobody has finished belongs on the overview and nowhere else
	 * (docs/ui.md): it is the screen that lists them all.
	 */
	it('carries the hint over the fleet it lists', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		expect(hint(wrapper).props('vehicles')).toEqual([VEHICLE])
	})

	/** Following the hint opens the vehicle, which is this screen's own primary action. */
	it('opens a vehicle the hint points at', async () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		await hint(wrapper).vm.$emit('select', VEHICLE.uuid)

		expect(wrapper.emitted('select')?.[0]).toEqual([VEHICLE.uuid])
	})

	/** An empty fleet is taught, not hinted at: there is no vehicle to complete yet. */
	it('asks nothing of a fleet that has no vehicles', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [] } })

		expect(hint(wrapper).exists()).toBe(false)
	})

	/**
	 * `n` is the screen's primary action, and on the overview that is the new vehicle
	 * (docs/ui.md). Only the screen in view is mounted, so the key needs no arbiter.
	 */
	it('asks for a new vehicle when n is pressed', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		press(document.body, 'n')

		expect(wrapper.emitted('new')?.length).toBe(1)
	})

	/**
	 * The overview is a to-do list (docs/ui.md): the vehicle whose reminder is overdue comes first,
	 * and its row says so in a word beside the light, with what is due and when.
	 */
	it('lists the fleet most urgent first, each with its light, its state and what comes due next', async () => {
		vi.mocked(listFleetReminders).mockResolvedValue(/** @type {any} */ ([
			{ uuid: 'r-1', vehicle: 'v-1', template_key: 'tyre_swap', title: null, mode: 'date', due_date: '2036-04-15', due_odo: null, state: 'planned', estimate: null },
			{ uuid: 'r-2', vehicle: 'v-2', template_key: null, title: 'Insurance renewal', mode: 'date', due_date: '2026-09-01', due_odo: null, state: 'overdue', estimate: null },
		]))
		const wrapper = rows([VEHICLE, { ...VEHICLE, uuid: 'v-2', plate: 'M-AB 1', odo_value: 148320, odo_unit: 'km' }])
		await flushPromises()

		const listed = wrapper.findAll('.overview__list li')
		expect(listed.map((row) => row.find('b').text())).toEqual(['M-AB 1', 'B-XY 123'])
		expect(listed[0].find('.overview__light--red').text()).toBe('Overdue')
		expect(listed[0].text()).toContain('Insurance renewal')
		expect(listed[0].text()).toContain('Due Sep 1, 2026')
		expect(listed[0].text()).toContain('148,320 km')
		expect(listed[1].find('.overview__light--green').text()).toBe('Planned')
		expect(listed[1].text()).toContain('Tyre swap')
	})

	/** Status is never colour alone (docs/ui.md), green included. */
	it('says a vehicle with nothing open has nothing due', async () => {
		const wrapper = rows([VEHICLE])
		await flushPromises()

		expect(wrapper.find('.overview__light--green').text()).toBe('Nothing due')
	})

	/** Reminders are what orders the list, not what makes it: a failed read still lists the fleet. */
	it('lists the fleet when its reminders cannot be read, and says why', async () => {
		vi.mocked(listFleetReminders).mockRejectedValue(new Error('Server error'))
		const wrapper = rows([VEHICLE])
		await flushPromises()

		expect(wrapper.findAll('.overview__list li')).toHaveLength(1)
		expect(wrapper.text()).toContain('The reminders could not be read: Server error')
	})

	/** An empty fleet is where the first vehicle is made, so the shortcut works there too. */
	it('asks for a new vehicle from the empty state as well', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [] } })

		press(document.body, 'n')

		expect(wrapper.emitted('new')?.length).toBe(1)
	})
})
