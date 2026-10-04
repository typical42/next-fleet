/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { readInbox } from '../services/api.js'
import { useInboxStore } from './inbox.js'

vi.mock('../services/api.js', () => ({ readInbox: vi.fn() }))

const FOLDER = { file_id: 7, path: '/Belege' }
const PHOTO = { file_id: 42, name: 'IMG_0815.jpg', mime: 'image/jpeg', mtime: 1788300000, size: 2048 }

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
})

describe('inbox store', () => {
	/** A paper attached, detached or restored elsewhere moves the count the navigation shows. */
	it('reads the count again while an inbox folder is set', async () => {
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: FOLDER, files: [PHOTO], count: 1 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: FOLDER, files: [], count: 0 })
		const inbox = useInboxStore()
		await inbox.load()

		await inbox.refresh()

		expect(inbox.count).toBe(0)
	})

	it('asks nothing when no inbox folder is set', async () => {
		vi.mocked(readInbox).mockResolvedValue({ folder: null, files: [], count: 0 })
		const inbox = useInboxStore()
		await inbox.load()

		await inbox.refresh()

		expect(readInbox).toHaveBeenCalledOnce()
	})

	/** The count is a hint beside the menu; the write it follows stands, so a failed read is no failure of it. */
	it('keeps the count it had when the read fails', async () => {
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: FOLDER, files: [PHOTO], count: 1 })
		vi.mocked(readInbox).mockRejectedValueOnce(new Error('The server answered 503'))
		const inbox = useInboxStore()
		await inbox.load()

		await inbox.refresh()

		expect(inbox.count).toBe(1)
	})
})
