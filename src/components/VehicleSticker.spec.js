/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import { shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { stickerUrl } from '../services/api.js'
import VehicleSticker from './VehicleSticker.vue'

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123' }

/**
 * @return {import('@vue/test-utils').VueWrapper} the sticker's button, with its dialog stubbed open
 */
function sticker() {
	return shallowMount(VehicleSticker, {
		props: { vehicle: VEHICLE },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { template: '<div class="dialog"><slot /><slot name="actions" /></div>' } },
		},
	})
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the sticker
 * @param {string} text - the button's words
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('the QR sticker', () => {
	it('shows nothing but its button until asked', () => {
		const wrapper = sticker()

		expect(button(wrapper, 'QR sticker')).toBeDefined()
		expect(wrapper.find('svg').exists()).toBe(false)
	})

	/** The name is on the sticker so the code in the glovebox is known to be this car's. */
	it('draws a code named after the vehicle', async () => {
		const wrapper = sticker()

		await button(wrapper, 'QR sticker').vm.$emit('click')

		const code = wrapper.find('svg[role="img"]')
		expect(code.attributes('aria-label')).toBe('QR code for B-XY 123')
		expect(code.find('path').attributes('d')).toMatch(/^M4 4h7/)
		expect(wrapper.text()).toContain('B-XY 123')
	})

	/** The same address as a link, for a phone that is already on the page or a tag to write. */
	it('says the address the code carries', async () => {
		const wrapper = sticker()

		await button(wrapper, 'QR sticker').vm.$emit('click')

		expect(wrapper.find('a').attributes('href')).toBe(stickerUrl('v-1'))
	})

	it('prints through the browser', async () => {
		// happy-dom has no print() to spy on.
		const print = vi.fn()
		vi.stubGlobal('print', print)
		const wrapper = sticker()

		await button(wrapper, 'QR sticker').vm.$emit('click')
		await button(wrapper, 'Print').vm.$emit('click')

		expect(print).toHaveBeenCalledOnce()
	})
})
