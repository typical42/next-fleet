/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import { newUuid } from './uuid.js'

/** What the server takes as a `client_uuid`: version 4, lowercase (lib/Service/Once.php). */
const V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

describe('newUuid', () => {
	afterEach(() => {
		vi.unstubAllGlobals()
	})

	it('is a version 4 uuid in lowercase, a new one each time', () => {
		const one = newUuid()

		expect(one).toMatch(V4)
		expect(newUuid()).not.toBe(one)
	})

	it('is one without randomUUID() too', () => {
		vi.stubGlobal('crypto', { getRandomValues: (/** @type {Uint8Array} */ bytes) => bytes.fill(0xff) })

		expect(newUuid()).toBe('ffffffff-ffff-4fff-bfff-ffffffffffff')
	})
})
