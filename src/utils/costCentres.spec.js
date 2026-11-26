/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { centreOptions, centreWord } from './costCentres.js'

/**
 * @param {string} uuid - the record's identity
 * @param {object} [fields] - instead of the defaults
 * @return {import('../services/api.js').CostCentre} a record of the owner's list
 */
const centre = (uuid, fields = {}) => ({ uuid, user_id: 'owner', code: null, name: uuid, archived: false, created_at: 1, updated_at: 2, ...fields })

describe('centreWord', () => {
	it('puts the code before the name, as a ledger lists it', () => {
		expect(centreWord(centre('c-1', { code: '4711', name: 'Sales' }))).toBe('4711 Sales')
		expect(centreWord(centre('c-1', { name: 'Sales' }))).toBe('Sales')
	})

	it('says when one is archived', () => {
		expect(centreWord(centre('c-1', { name: 'Sales', archived: true }))).toBe('Sales (archived)')
	})
})

describe('centreOptions', () => {
	const list = [
		centre('mine', { name: 'Sales' }),
		centre('old', { name: 'Old', archived: true }),
		centre('theirs', { user_id: 'someone', name: 'Other' }),
	]

	it('offers the live records of the vehicle owner only', () => {
		expect(centreOptions(list, 'owner', null)).toEqual([{ id: 'mine', label: 'Sales' }])
	})

	it('keeps an archived one that the entry already names', () => {
		expect(centreOptions(list, 'owner', 'old').map((one) => one.id)).toEqual(['mine', 'old'])
	})
})
