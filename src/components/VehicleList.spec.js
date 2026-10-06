/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import { shallowMount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import VehicleList from './VehicleList.vue'

const VEHICLES = [
	{ uuid: 'v-1', plate: 'B-XY 123' },
	{ uuid: 'v-2', plate: '', manufacturer: 'VW', model: 'Golf' },
]

/**
 * @param {string} selected - the uuid the content area shows
 * @return {import('@vue/test-utils').VueWrapper} the list
 */
function list(selected) {
	return shallowMount(VehicleList, { props: { vehicles: VEHICLES, selected } })
}

describe('VehicleList', () => {
	it('names each vehicle as the overview does and marks the one shown', () => {
		const items = list('v-2').findAllComponents(NcAppNavigationItem)

		expect(items.map((item) => item.props('name'))).toEqual(['B-XY 123', 'VW Golf'])
		expect(items.map((item) => item.props('active'))).toEqual([false, true])
	})

	it('says which vehicle was picked', async () => {
		const wrapper = list('')

		await wrapper.findAllComponents(NcAppNavigationItem)[1].vm.$emit('click')

		expect(wrapper.emitted('select')).toEqual([['v-2']])
	})
})
