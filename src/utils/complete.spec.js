/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { missingFrom } from './complete.js'

describe('what a vehicle is still missing', () => {
	/** The four fields the create sheet does not ask for (docs/ui.md) are all there. */
	it('asks for nothing from a vehicle that has it all', () => {
		expect(missingFrom({
			vin: 'WVWZZZ3CZKE000100',
			first_reg: '2019-03-14',
			energy_types: ['diesel'],
			tank_ml: 66000,
		})).toEqual([])
	})

	it('asks for the identity and the age nobody filled in', () => {
		expect(missingFrom({ energy_types: [] })).toEqual(['vin', 'first_reg'])
	})

	/**
	 * Which capacity is missing is a question about the energy the vehicle takes, not about its
	 * engine: a diesel has a tank and no battery, so asking for a battery would be asking for a
	 * number that does not exist (CONTEXT.md).
	 */
	it('asks a liquid-fuelled vehicle for its tank and never for a battery', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: ['diesel'] }))
			.toEqual(['tank_ml'])
	})

	it('asks an electric vehicle for its battery and never for a tank', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: ['electric'] }))
			.toEqual(['battery_wh'])
	})

	/** A plug-in hybrid is `hybrid` / `[petrol, electric]` (CONTEXT.md) and really has both. */
	it('asks a plug-in hybrid for both', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: ['petrol', 'electric'] }))
			.toEqual(['tank_ml', 'battery_wh'])
	})

	/**
	 * A trailer takes no energy at all (lib/Command/SeedCommand.php), so neither capacity is
	 * implied - and a hint asking a trailer for its tank size would never be answerable.
	 */
	it('implies no capacity where no energy is taken', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: null }))
			.toEqual([])
	})

	/**
	 * The server writes null for a field somebody emptied (lib/Service/VehicleService.php), and an
	 * empty string is the same fact one round trip earlier.
	 */
	it('reads an empty field as no answer', () => {
		expect(missingFrom({ vin: '', first_reg: '', energy_types: ['petrol'], tank_ml: 52000 }))
			.toEqual(['vin', 'first_reg'])
	})

	/** Zero is an answer somebody gave. It may be wrong, but the hint is not a validator. */
	it('takes a zero as an answer', () => {
		expect(missingFrom({ vin: 'X', first_reg: '2019-03-14', energy_types: ['petrol'], tank_ml: 0 }))
			.toEqual([])
	})
})
