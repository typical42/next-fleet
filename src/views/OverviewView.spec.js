/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import CompleteHint from '../components/CompleteHint.vue'
import { listFleetReminders } from '../services/api.js'
import OverviewView from './OverviewView.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	listFleetReminders: vi.fn(),
}))
vi.mock('@nextcloud/auth', async (original) => ({
	...await original(),
	getCurrentUser: () => ({ uid: 'me' }),
}))

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }
/** 2026-10-02 16:00 UTC: Friday 18:00 at +02:00. */
const FRIDAY_SIX_PM = 1790956800

/**
 * Mounts with a stand-in list item that shows its name in bold and its slots in place.
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
	// Only the clock: timers stay real, so flushPromises() and the hot key keep working.
	vi.useFakeTimers({ toFake: ['Date'] })
})

afterEach(() => {
	vi.useRealTimers()
})

describe('the overview', () => {
	/** The hint belongs on the overview alone, the screen that lists every vehicle (docs/ui.md). */
	it('carries the hint over the fleet it lists', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		expect(hint(wrapper).props('vehicles')).toEqual([VEHICLE])
	})

	it('opens a vehicle the hint points at', async () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		await hint(wrapper).vm.$emit('select', VEHICLE.uuid)

		expect(wrapper.emitted('select')?.[0]).toEqual([VEHICLE.uuid])
	})

	it('lists the disposed vehicles apart and opens them', async () => {
		vi.mocked(listFleetReminders).mockResolvedValue([])
		const SOLD = { ...VEHICLE, uuid: 'v-2', plate: 'B-OL 1', lifecycle: 'disposed' }
		const wrapper = shallowMount(OverviewView, {
			props: { vehicles: [VEHICLE], disposed: [SOLD] },
			global: { stubs: { NcListItem: { props: ['name'], template: '<li>{{ name }}</li>' } } },
		})
		await flushPromises()

		const sold = wrapper.find('.overview__disposed')
		expect(sold.text()).toBe('B-OL 1')
		expect(wrapper.find('.overview__list').text()).not.toContain('B-OL 1')
		await sold.find('li').trigger('click')
		expect(wrapper.emitted('select')).toEqual([['v-2']])
	})

	/** The overview is the only way back to the sold ones. */
	it('shows the empty state and the disposed vehicles when no other is left', async () => {
		vi.mocked(listFleetReminders).mockResolvedValue([])
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [], disposed: [{ ...VEHICLE, lifecycle: 'disposed' }] } })
		await flushPromises()

		expect(wrapper.find('.overview__disposed').exists()).toBe(true)
		expect(wrapper.find('.overview__list').exists()).toBe(false)
		expect(wrapper.findComponent({ name: 'NcEmptyContent' }).exists()).toBe(true)
	})

	/** An empty fleet is taught, not hinted at: there is no vehicle to complete yet. */
	it('asks nothing of a fleet that has no vehicles', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [] } })

		expect(hint(wrapper).exists()).toBe(false)
	})

	/** The empty state teaches by its one button (docs/ui.md). */
	it('asks for the first vehicle from the empty state', async () => {
		const wrapper = shallowMount(OverviewView, {
			props: { vehicles: [] },
			global: { renderStubDefaultSlot: true, stubs: { NcEmptyContent: { template: '<div><slot name="action" /></div>' } } },
		})

		await wrapper.findComponent({ name: 'NcButton' }).vm.$emit('click')

		expect(wrapper.emitted('new')).toHaveLength(1)
	})

	it('opens a vehicle of the fleet it lists', async () => {
		const wrapper = rows([VEHICLE])
		await flushPromises()

		await wrapper.find('.overview__list li').trigger('click')

		expect(wrapper.emitted('select')).toEqual([[VEHICLE.uuid]])
	})

	it('asks for a new vehicle when n is pressed', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [VEHICLE] } })

		press(document.body, 'n')

		expect(wrapper.emitted('new')?.length).toBe(1)
	})

	/** A to-do list (docs/ui.md): most urgent first, its state in a word beside the light. */
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

	/**
	 * Granted vehicles sort among the reader's own; the server sets `owned_by`, not the browser.
	 */
	it('names the owner of a vehicle somebody else owns, and nobody on the reader\'s own', async () => {
		vi.mocked(listFleetReminders).mockResolvedValue(/** @type {any} */ ([
			{ uuid: 'r-1', vehicle: 'v-2', template_key: null, title: 'Insurance renewal', mode: 'date', due_date: '2026-09-01', due_odo: null, state: 'overdue', estimate: null },
		]))
		const wrapper = rows([{ ...VEHICLE, owned_by: null }, { ...VEHICLE, uuid: 'v-2', plate: 'M-AB 1', owned_by: 'Anna O\'Brien' }])
		await flushPromises()

		const listed = wrapper.findAll('.overview__list li')
		expect(listed.map((row) => row.find('b').text())).toEqual(['M-AB 1', 'B-XY 123'])
		expect(listed[0].find('.overview__owner').text()).toBe('Owned by Anna O\'Brien')
		expect(listed[1].find('.overview__owner').exists()).toBe(false)
	})

	/** A car that is out says with whom and until when; the reader's own says "you". */
	it('says who has a car that is out, and until when', async () => {
		vi.setSystemTime(new Date('2026-10-02T12:00:00Z'))
		const wrapper = rows([
			{ ...VEHICLE, out_with: { user_id: 'anna', user_name: 'Anna O\'Brien', ends_at: FRIDAY_SIX_PM, ends_at_off: 120 } },
			{ ...VEHICLE, uuid: 'v-2', plate: 'M-AB 1', out_with: { user_id: 'me', user_name: 'Me', ends_at: FRIDAY_SIX_PM, ends_at_off: 120 } },
			{ ...VEHICLE, uuid: 'v-3', plate: 'M-AB 2', out_with: null },
		])
		await flushPromises()

		const holders = wrapper.findAll('.overview__list li').map((row) => row.find('.overview__holder'))
		// The test run's locale is American English.
		expect(holders[0].text()).toBe('With Anna O\'Brien until Fri, 10/02, 06:00 PM')
		expect(holders[1].text()).toBe('With you until Fri, 10/02, 06:00 PM')
		expect(holders[2].exists()).toBe(false)
	})

	/** Not given back by its end: the row says so in words, not by colour alone. */
	it('says a car still out past its end is overdue', async () => {
		vi.setSystemTime(new Date('2026-10-03T08:00:00Z'))
		const wrapper = rows([{ ...VEHICLE, out_with: { user_id: 'anna', user_name: 'Anna O\'Brien', ends_at: FRIDAY_SIX_PM, ends_at_off: 120 } }])
		await flushPromises()

		const holder = wrapper.find('.overview__holder')
		expect(holder.text()).toBe('With Anna O\'Brien, overdue since Fri, 10/02, 06:00 PM')
		expect(holder.classes()).toContain('overview__holder--overdue')
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
