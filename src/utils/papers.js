/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { fetchDocument, LockedError, NotFoundError } from '../services/api.js'
import { t } from './l10n.js'

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
			return t('nextfleet', 'This document is gone: it was removed, or its file is no longer in the Files of whoever attached it.')
		}
		if (error instanceof LockedError) {
			return t('nextfleet', 'Somebody is saving this file right now. Try again in a moment.')
		}
		return t('nextfleet', 'The document could not be opened: {reason}', { reason: /** @type {Error} */ (error).message })
	}

	const link = document.createElement('a')
	// A blob URL is of the app's origin and keeps the blob's type: an HTML paper opened from it
	// would run there. Typeless bytes the browser only saves.
	link.href = URL.createObjectURL(new Blob([file], { type: 'application/octet-stream' }))
	link.download = paper.name ?? ''
	link.click()
	// Revoked at once, some browsers save nothing; no event marks the save done, so wait a minute.
	setTimeout(() => URL.revokeObjectURL(link.href), 60_000)

	return ''
}
