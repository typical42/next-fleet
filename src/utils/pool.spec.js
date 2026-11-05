/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

import { nextBookingWords, refusalWords, waiting } from './pool.js'

vi.mock('@nextcloud/l10n', async (original) => ({
	...await original(),
	getCanonicalLocale: () => 'en-GB',
}))

describe('refusalWords', () => {
	it('words the approval refusals and the comment limit', () => {
		expect(refusalWords('the booking is over, so it is answered no more'))
			.toBe('The booking is over, so it is answered no more.')
		expect(refusalWords('only a booking waiting for an answer is answered'))
			.toBe('This booking was answered or changed meanwhile.')
		expect(refusalWords('the booking waits for an answer, so the car is not taken under it yet'))
			.toBe('The booking is not approved yet, so the car is not taken under it.')
		expect(refusalWords('comment is longer than 10000 characters'))
			.toBe('The comment is longer than 10,000 characters.')
	})

	/** A manager's For list is as old as the sheet; the owner may have taken the person's access since. */
	it('words the refusal of a person who may no longer book', () => {
		expect(refusalWords('user_id names nobody who may book this vehicle'))
			.toBe('This person may no longer book this vehicle.')
	})
})

describe('nextBookingWords', () => {
	/**
	 * @param {string} approval - where the reader's next booking stands
	 * @return {import('../services/api.js').Vehicle} a vehicle as listed, with that booking
	 */
	const vehicle = (approval) => ({
		uuid: 'v-1',
		updated_at: 1790000000,
		my_next_booking: { uuid: 'b-1', starts_at: 1790940000, starts_at_off: 120, ends_at: 1790947200, ends_at_off: 120, approval },
	})

	it('tells an asked booking from one that waits for its first answer', () => {
		expect(nextBookingWords(vehicle('asked'))).toMatch(/^Your booking, asked back: /)
		expect(nextBookingWords(vehicle('requested'))).toMatch(/^Your booking, waiting for approval: /)
		expect(nextBookingWords(vehicle('approved'))).toMatch(/^Your booking: /)
	})
})

describe('waiting', () => {
	it('is a booking that waits for an answer', () => {
		expect(waiting({ state: 'booked', approval: 'requested' })).toBe(true)
		expect(waiting({ state: 'booked', approval: 'asked' })).toBe(true)
		expect(waiting({ state: 'booked', approval: 'approved' })).toBe(false)
		expect(waiting({ state: 'booked', approval: null })).toBe(false)
	})

	/** A cancel keeps the approval it stood at (docs/api.md#booking-approval). */
	it('is no cancelled booking, whatever its approval says', () => {
		expect(waiting({ state: 'cancelled', approval: 'requested' })).toBe(false)
		expect(waiting({ state: 'cancelled', approval: 'asked' })).toBe(false)
	})

	/** The vehicle's `my_next_booking` is booked by what it is, and carries no state. */
	it('reads a booking without a state as booked', () => {
		expect(waiting({ approval: 'requested' })).toBe(true)
	})
})
