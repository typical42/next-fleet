/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { pickNode } from './picker.js'

vi.mock('@nextcloud/dialogs', () => ({
	FilePickerClosed: class extends Error {},
	getFilePickerBuilder: vi.fn(),
}))

/** @type {Promise<any[]>} */
let picked
/** @type {Record<string, any>} */
let asked
/** @type {any} */
let factory

beforeEach(() => {
	vi.resetAllMocks()
	picked = Promise.resolve([{ fileid: 42, basename: 'fuel.csv' }])
	asked = {}
	const builder = {
		setMultiSelect: (/** @type {boolean} */ multi) => { asked.multi = multi; return builder },
		allowDirectories: (/** @type {boolean} */ folders) => { asked.folders = folders; return builder },
		setMimeTypeFilter: (/** @type {string[]} */ types) => { asked.types = types; return builder },
		setButtonFactory: (/** @type {any} */ given) => { factory = given; return builder },
		build: () => ({ pickNodes: () => picked }),
	}
	vi.mocked(getFilePickerBuilder).mockReturnValue(/** @type {any} */ (builder))
})

describe('pickNode', () => {
	it('answers with the one file picked', async () => {
		await expect(pickNode('Choose')).resolves.toEqual({ fileid: 42, basename: 'fuel.csv' })
		expect(getFilePickerBuilder).toHaveBeenCalledWith('Choose')
		expect(asked).toEqual({ multi: false, folders: false })
	})

	it('asks for a folder and nothing else', async () => {
		await pickNode('Choose', { folder: true })

		expect(asked).toEqual({ multi: false, folders: true, types: ['httpd/unix-directory'] })
	})

	it('asks for the types given', async () => {
		await pickNode('Choose', { mimeTypes: ['text/csv'] })

		expect(asked).toEqual({ multi: false, folders: false, types: ['text/csv'] })
	})

	/** The picker brings no button of its own; pickNodes() answers with what this one picked. */
	it('offers one button, which waits for a pick', async () => {
		await pickNode('Choose')

		const [button] = factory([])
		expect(button).toMatchObject({ label: 'Choose', variant: 'primary', disabled: true })
		expect(factory([{}])[0].disabled).toBe(false)
	})

	it('answers null when the picker is closed', async () => {
		picked = Promise.reject(new FilePickerClosed())

		await expect(pickNode('Choose')).resolves.toBeNull()
	})

	it('answers null when nothing with a file id was picked', async () => {
		picked = Promise.resolve([])

		await expect(pickNode('Choose')).resolves.toBeNull()
	})

	it('passes on any other failure', async () => {
		picked = Promise.reject(new Error('No connection'))

		await expect(pickNode('Choose')).rejects.toThrow('No connection')
	})
})
