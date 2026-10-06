/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { FilePickerClosed, getFilePickerBuilder } from '@nextcloud/dialogs'

import { t } from './l10n.js'

/**
 * One node from Nextcloud's own file picker.
 *
 * @param {string} title - the picker's heading
 * @param {{ folder?: boolean, mimeTypes?: string[] }} [options] - a folder and no file; else
 *     only files of these types
 * @return {Promise<{ fileid: number, basename: string }|null>} null when the picker was closed or
 *     nothing was picked; any other failure rejects
 */
export async function pickNode(title, { folder = false, mimeTypes = [] } = {}) {
	const builder = getFilePickerBuilder(title)
		.setMultiSelect(false)
		.allowDirectories(folder)
	const types = folder ? ['httpd/unix-directory'] : mimeTypes
	if (types.length > 0) {
		builder.setMimeTypeFilter(types)
	}
	let nodes
	try {
		nodes = await builder
			// The picker brings no button of its own; pickNodes() answers with what this one picked.
			.setButtonFactory((selected) => [{
				label: t('nextfleet', 'Choose'),
				variant: 'primary',
				disabled: selected.length === 0,
				callback: () => {},
			}])
			.build()
			.pickNodes()
	} catch (error) {
		if (error instanceof FilePickerClosed) {
			return null
		}
		throw error
	}
	const [node] = nodes

	return node?.fileid === undefined ? null : { fileid: node.fileid, basename: node.basename }
}
