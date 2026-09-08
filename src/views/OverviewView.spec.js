/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import CompleteHint from '../components/CompleteHint.vue'
import OverviewView from './OverviewView.vue'

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }

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

	/** An empty fleet is where the first vehicle is made, so the shortcut works there too. */
	it('asks for a new vehicle from the empty state as well', () => {
		const wrapper = shallowMount(OverviewView, { props: { vehicles: [] } })

		press(document.body, 'n')

		expect(wrapper.emitted('new')?.length).toBe(1)
	})
})
