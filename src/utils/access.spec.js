/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { may } from './access.js'

describe('may', () => {
	it('answers by the list the server sent', () => {
		expect(may({ may: ['view', 'log'] }, 'log')).toBe(true)
		expect(may({ may: ['view', 'log'] }, 'edit')).toBe(false)
	})

	/** No list is no permission: the screen never offers more than the server said. */
	it('refuses what carries no list', () => {
		expect(may({}, 'view')).toBe(false)
		expect(may({ may: null }, 'view')).toBe(false)
	})
})
