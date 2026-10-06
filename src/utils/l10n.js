/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t as translate } from '@nextcloud/l10n'

/**
 * Translates for Vue, which escapes what it interpolates. The library escapes and sanitizes for an
 * HTML sink, turning "O'Brien" into "O&#39;Brien" and cutting a name at a `<`. No string here
 * reaches raw HTML (l10n.spec.js holds that), so both are off.
 *
 * @param {string} app - the app whose catalogue holds the text
 * @param {string} text - the English text
 * @param {Record<string, string|number|{ value: string|number, escape: boolean }>} [vars] - the placeholders' values
 * @return {string} the text in the user's language, the values as given
 */
export function t(app, text, vars) {
	return translate(app, text, vars ?? {}, undefined, { escape: false, sanitize: false })
}
