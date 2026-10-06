/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'

import { latestSearch } from './search.js'

describe('latestSearch', () => {
	it('keeps the answer to what was typed last, whichever answer comes last', async () => {
		/** @type {Record<string, (found: string[]) => void>} */
		const answer = {}
		const { found, search } = latestSearch((term) => new Promise((resolve) => { answer[term] = resolve }))

		const older = search('a')
		const newer = search('an')
		answer.an(['ann'])
		await newer
		answer.a(['ada', 'ann'])
		await older

		expect(found.value).toEqual(['ann'])
	})

	it('asks nothing for an empty field, and finds nothing', async () => {
		const lookup = vi.fn(async () => ['ann'])
		const { found, search } = latestSearch(lookup)
		await search('an')

		await search('  ')

		expect(lookup).toHaveBeenCalledTimes(1)
		expect(found.value).toEqual([])
	})

	it('finds nothing when the search fails', async () => {
		const { found, search } = latestSearch(async () => { throw new Error('No connection') })

		await search('an')

		expect(found.value).toEqual([])
	})
})
