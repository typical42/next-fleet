/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import EntrySheet from './EntrySheet.vue'

const VEHICLE = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', odo_value: 148320 }

/**
 * The sheet, mounted. shallowMount renders no stub's slots and the field sits inside the dialog,
 * so that one component is rendered and the rest stay stubs (docs/development.md).
 *
 * @return {import('@vue/test-utils').VueWrapper} the mounted sheet
 */
function sheet() {
	return shallowMount(EntrySheet, {
		props: { vehicle: VEHICLE },
		global: { stubs: { NcDialog: { template: '<div><slot /><slot name="actions" /></div>' } } },
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
})

describe('the entry sheet', () => {
	/**
	 * `Esc` closes the sheet and NcDialog already gives it (docs/ui.md), so nothing here listens
	 * for the key. What is pinned is the one wire it travels along: the dialog reports itself
	 * closed and the sheet leaves. A sheet that bound `:open` and no listener would swallow the
	 * key silently and never reopen.
	 */
	it('closes when the dialog reports itself closed', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcDialog).vm.$emit('update:open', false)

		expect(wrapper.emitted('close')?.length).toBe(1)
	})

	/**
	 * The other half of the key, and the half NcDialog does not give: its own Escape handler is a
	 * useHotKey, and useHotKey passes over every keystroke aimed at a text field. This sheet is
	 * one field with the caret already in it, so Escape would otherwise reach nobody.
	 */
	it('closes when Esc is pressed in the field', async () => {
		const wrapper = sheet()

		await wrapper.findComponent(NcTextField).trigger('keydown.esc')

		expect(wrapper.emitted('close')?.length).toBe(1)
	})
})
