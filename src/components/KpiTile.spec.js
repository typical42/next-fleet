/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'

import KpiTile from './KpiTile.vue'

describe('KpiTile', () => {
	it('puts the label over the figure, then the change and each note', () => {
		const wrapper = mount(KpiTile, {
			props: { tile: { label: 'Cost', figure: '€12.30 / 100 km', change: '+4 % on last year', notes: ['Net of VAT', 'No price on 2 fill-ups'] } },
		})

		expect(wrapper.find('dt').text()).toBe('Cost')
		expect(wrapper.findAll('dd').map((dd) => dd.text())).toEqual(['€12.30 / 100 km', '+4 % on last year', 'Net of VAT', 'No price on 2 fill-ups'])
	})

	it('leaves out a change it was not given', () => {
		const wrapper = mount(KpiTile, { props: { tile: { label: 'Cost', figure: '–', change: null, notes: [] } } })

		expect(wrapper.findAll('dd').map((dd) => dd.text())).toEqual(['–'])
	})
})
