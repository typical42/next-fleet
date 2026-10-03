/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

import { fetchDocument, LockedError, NotFoundError } from '../services/api.js'

/**
 * Saves one paper's file, or says why not. Fetched, not followed: a followed link that is refused
 * replaces the app with the server's JSON, and the reader is left on a page that says nothing.
 *
 * @param {string} uuid - the vehicle
 * @param {import('../services/api.js').Document} paper - one whose file is listed
 * @return {Promise<string>} why it was not saved, in words, or the empty string once it is
 */
export async function savePaper(uuid, paper) {
	let file
	try {
		file = await fetchDocument(uuid, paper.uuid)
	} catch (error) {
		if (error instanceof NotFoundError) {
			return t('nextfleet', 'This document is gone: it was removed, or its file was deleted from Files.')
		}
		if (error instanceof LockedError) {
			return t('nextfleet', 'Somebody is saving this file right now. Try again in a moment.')
		}
		return t('nextfleet', 'The document could not be opened: {reason}', { reason: /** @type {Error} */ (error).message })
	}

	const link = document.createElement('a')
	link.href = URL.createObjectURL(file)
	link.download = paper.name ?? ''
	link.click()
	// Revoked at once, some browsers save nothing, and no event says when the save has the bytes; a
	// minute is long past it, and the memory is given back on leaving the page in any case.
	setTimeout(() => URL.revokeObjectURL(link.href), 60_000)

	return ''
}
