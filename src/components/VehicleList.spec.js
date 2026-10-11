/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcListItem from '@nextcloud/vue/components/NcListItem'
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import { nextTick } from 'vue'

import VehicleList from './VehicleList.vue'

const VEHICLES = [
	{ uuid: 'v-1', plate: 'B-XY 123', display_name: 'Blue van', manufacturer: 'VW', model: 'Crafter' },
	{ uuid: 'v-2', plate: '', manufacturer: 'VW', model: 'Golf' },
]

/**
 * Mounted whole: whether an entry is an <li> of its own is what the navigation's list needs.
 *
 * @param {string} selected - the uuid the content area shows
 * @param {object[]} vehicles - the vehicles listed
 * @return {import('@vue/test-utils').VueWrapper} the list
 */
function list(selected, vehicles = VEHICLES) {
	return mount(VehicleList, { props: { vehicles, selected } })
}

describe('VehicleList', () => {
	it('names each vehicle in bold as the overview does and marks the one shown', () => {
		const wrapper = list('v-2')

		expect(wrapper.findAll('.list-item-content__name strong').map((name) => name.text()))
			.toEqual(['B-XY 123', 'VW Golf'])
		expect(wrapper.findAllComponents(NcListItem).map((item) => item.props('active'))).toEqual([false, true])
	})

	/** The screen reader hears which vehicle is open on the link it focuses, as for Overview beside them. */
	it('marks the link of the vehicle shown as the current page, and only that one', async () => {
		const wrapper = list('v-2')
		const current = () => wrapper.findAll('a.list-item__anchor').map((link) => link.attributes('aria-current'))

		expect(current()).toEqual([undefined, 'page'])
		expect(wrapper.findAll('li[aria-current]')).toHaveLength(0)

		await wrapper.setProps({ selected: 'v-1' })
		expect(current()).toEqual(['page', undefined])

		await wrapper.setProps({ selected: '' })
		expect(current()).toEqual([undefined, undefined])
	})

	it('marks a vehicle that joins the list while it is shown', async () => {
		const wrapper = list('v-2', VEHICLES.slice(0, 1))

		await wrapper.setProps({ vehicles: VEHICLES })

		expect(wrapper.findAll('a.list-item__anchor').map((link) => link.attributes('aria-current')))
			.toEqual([undefined, 'page'])
	})

	it('says what the vehicle is under its name, both in full on hover', async () => {
		const wrapper = list('')
		// NcListItem looks for its subname slot once mounted and shows it a render later.
		await nextTick()
		const second = wrapper.findAll('.vehicle-list__second')

		expect(wrapper.findAll('li.list-item__wrapper').map((entry) => entry.attributes('title')))
			.toEqual(['B-XY 123', 'VW Golf'])
		expect(second.map((line) => line.text())).toEqual(['Blue van'])
		expect(second[0].attributes('title')).toBe('Blue van')
	})

	it('shows a second line that a vehicle gains while listed', async () => {
		const wrapper = list('', [{ uuid: 'v-3', plate: 'K-LM 9' }])
		await nextTick()

		await wrapper.setProps({ vehicles: [{ uuid: 'v-3', plate: 'K-LM 9', display_name: 'Red trailer' }] })
		await nextTick()

		expect(wrapper.findAll('.vehicle-list__second').map((line) => line.text())).toEqual(['Red trailer'])
	})

	/** NcAppNavigation wraps its `list` slot in a <ul>; a list of its own would nest one in it. */
	it('gives each vehicle an entry of its own and no list around them', () => {
		const wrapper = list('')

		expect(wrapper.findAll('li.list-item__wrapper')).toHaveLength(2)
		expect(wrapper.find('ul').exists()).toBe(false)
	})

	it('says which vehicle was picked', async () => {
		const wrapper = list('')

		await wrapper.findAllComponents(NcListItem)[1].vm.$emit('click', new MouseEvent('click'))

		expect(wrapper.emitted('select')).toEqual([['v-2']])
	})
})
