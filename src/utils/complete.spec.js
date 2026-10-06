/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { missingFrom } from './complete.js'

describe('what a vehicle is still missing', () => {
	it('asks for nothing from a vehicle that has it all', () => {
		expect(missingFrom({
			vin: 'WVWZZZ3CZKE000100',
			first_reg: '2019-03-14',
			energy_types: ['diesel'],
			tank_ml: 66000,
			currency: 'EUR',
		})).toEqual([])
	})

	it('asks for the identity and the age nobody filled in', () => {
		expect(missingFrom({ energy_types: [], currency: 'EUR' })).toEqual(['vin', 'first_reg'])
	})

	it('asks a liquid-fuelled vehicle for its tank and never for a battery', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', currency: 'EUR', energy_types: ['diesel'] }))
			.toEqual(['tank_ml'])
	})

	it('asks an electric vehicle for its battery and never for a tank', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', currency: 'EUR', energy_types: ['electric'] }))
			.toEqual(['battery_wh'])
	})

	it('asks a plug-in hybrid for both', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', currency: 'EUR', energy_types: ['petrol', 'electric'] }))
			.toEqual(['tank_ml', 'battery_wh'])
	})

	it('implies no capacity where no energy is taken', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', currency: 'EUR', energy_types: null }))
			.toEqual([])
	})

	/**
	 * The server stores an emptied field as null; an empty string is that before the round trip.
	 */
	it('reads an empty field as no answer', () => {
		expect(missingFrom({ vin: '', first_reg: '', energy_types: ['petrol'], tank_ml: 52000, currency: 'EUR' }))
			.toEqual(['vin', 'first_reg'])
	})

	it('asks for a currency when the vehicle has none', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: ['diesel'], currency: null }))
			.toEqual(['tank_ml', 'currency'])
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: null, currency: '' }))
			.toEqual(['currency'])
	})

	it('takes a zero as an answer', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', currency: 'EUR', energy_types: ['petrol'], tank_ml: 0 }))
			.toEqual([])
	})
})
