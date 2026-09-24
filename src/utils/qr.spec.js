/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { qrOf } from './qr.js'

describe('qrOf', () => {
	/** Version 1 is 21 modules a side (ISO/IEC 18004), and the quiet zone is four on each side. */
	it('sizes a short text as the smallest code plus its quiet zone', () => {
		expect(qrOf('HELLO').size).toBe(29)
	})

	/** Every code opens on the top row of its top-left finder: seven dark modules in a row. */
	it('draws the dark modules as runs, starting with the finder pattern', () => {
		expect(qrOf('HELLO').path).toMatch(/^M4 4h7v1h-7z/)
	})
})
