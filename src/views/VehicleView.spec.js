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
	return shallowMount(VehicleView, { props: { vehicle: VEHICLE } })
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when the screen has none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

describe('the vehicle screen', () => {
	/**
	 * One primary button per screen, and on this one it is the entry - editing a vehicle is
	 * something people do twice a year (docs/ui.md).
	 */
	it('offers the entry first and the edit beside it', () => {
		const wrapper = screen()

		expect(button(wrapper, 'New entry').props('variant')).toBe('primary')
		expect(button(wrapper, 'Edit vehicle').props('variant')).not.toBe('primary')
	})

	it('edits the vehicle it is showing', async () => {
		const wrapper = screen()
		expect(wrapper.findComponent(VehicleSheet).exists()).toBe(false)

		await button(wrapper, 'Edit vehicle').vm.$emit('click')

		expect(wrapper.findComponent(VehicleSheet).props('vehicle')).toBe(VEHICLE)
		expect(wrapper.findComponent(EntrySheet).exists()).toBe(false)
	})

	/** A save is done with, so the sheet goes; the screen already reads the store for the rest. */
	it('closes the sheet once the vehicle is saved', async () => {
		const wrapper = screen()

		await button(wrapper, 'Edit vehicle').vm.$emit('click')
		await wrapper.findComponent(VehicleSheet).vm.$emit('saved', VEHICLE)

		expect(wrapper.findComponent(VehicleSheet).exists()).toBe(false)
	})
})
