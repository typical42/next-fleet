/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'

import { fetchDocument, NotFoundError } from '../services/api.js'
import { savePaper } from './papers.js'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	fetchDocument: vi.fn(),
}))

const PAPER = /** @type {any} */ ({ uuid: 'd-1', name: 'Rechnung.html' })

afterEach(() => {
	vi.unstubAllGlobals()
	vi.restoreAllMocks()
})

describe('savePaper', () => {
	it('hands the browser bytes of no type it would render', async () => {
		vi.mocked(fetchDocument).mockResolvedValue(new Blob(['<script>alert(1)</script>'], { type: 'text/html' }))
		/** @type {Blob[]} */
		const created = []
		vi.stubGlobal('URL', {
			createObjectURL: (/** @type {Blob} */ blob) => {
				created.push(blob)
				return 'blob:paper'
			},
			revokeObjectURL: vi.fn(),
		})
		vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})

		expect(await savePaper('v-1', PAPER)).toBe('')

		expect(created).toHaveLength(1)
		expect(created[0].type).toBe('application/octet-stream')
		expect(await created[0].text()).toBe('<script>alert(1)</script>')
	})

	it('says a file that is no longer its attacher\'s own is gone', async () => {
		vi.mocked(fetchDocument).mockRejectedValue(new NotFoundError('No such document'))

		expect(await savePaper('v-1', PAPER)).toBe('This document is gone: it was removed, or its file is no longer in the Files of whoever attached it.')
	})
})
