/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import EntrySheet from '../components/EntrySheet.vue'
import VehicleSheet from '../components/VehicleSheet.vue'
import VehicleView from './VehicleView.vue'

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', lifecycle: 'active' }

/**
 * @return {import('@vue/test-utils').VueWrapper} the screen, mounted on one vehicle
 */
function screen() {
	// A stub renders no slot of its own, and a button says what it is in its slot - so the two
	// buttons of this screen would be indistinguishable without this.
	return shallowMount(VehicleView, {
		props: { vehicle: VEHICLE },
		global: { renderStubDefaultSlot: true },
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when the screen has none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the vehicle sheet, whether the screen is showing one or not
 */
function sheet(wrapper) {
	return wrapper.findComponent(VehicleSheet)
}

describe('the vehicle screen', () => {
	/**
	 * One primary button per screen, and on this one it is the entry - editing a vehicle is
	 * something people do twice a year (docs/ui.md).
	 */
	it('offers the entry first and the edit beside it', () => {
		const wrapper = screen()

		// First in the markup as well as first in emphasis: reading, tab and wrapping order are
		// the same order.
		expect(wrapper.findAllComponents(NcButton).map((one) => one.text()))
			.toEqual(['New entry', 'Edit vehicle'])
		expect(button(wrapper, 'New entry').props('variant')).toBe('primary')
		expect(button(wrapper, 'Edit vehicle').props('variant')).not.toBe('primary')
	})

	it('edits the vehicle it is showing', async () => {
		const wrapper = screen()
		expect(sheet(wrapper).exists()).toBe(false)

		await button(wrapper, 'Edit vehicle').vm.$emit('click')

		// Equal rather than identical: a prop reaches the child through Vue's reactive proxy.
		expect(sheet(wrapper).props('vehicle')).toEqual(VEHICLE)
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})

	/** A save is done with, so the sheet goes; the screen already reads the store for the rest. */
	it('closes the sheet once the vehicle is saved', async () => {
		const wrapper = screen()

		await button(wrapper, 'Edit vehicle').vm.$emit('click')
		await sheet(wrapper).vm.$emit('saved', VEHICLE)

		expect(sheet(wrapper).exists()).toBe(false)
	})
})
