/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A uuid for a row this client creates, sent as `client_uuid` so a create sent again names the row
 * the first one wrote (docs/api.md#retried-creates). Built from getRandomValues() where the browser
 * offers no randomUUID(): it does only in a secure context, and a Nextcloud on plain http is none.
 *
 * @return {string} a version 4 uuid, lowercase
 */
export function newUuid() {
	if (typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}
	const bytes = crypto.getRandomValues(new Uint8Array(16))
	// RFC 9562: the version nibble is 4, the variant bits 10.
	bytes[6] = (bytes[6] & 0x0f) | 0x40
	bytes[8] = (bytes[8] & 0x3f) | 0x80
	const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')

	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
