/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Whether the server said the session may do this. The screen hides by `may` and nothing else
 * (docs/ui.md, "Screens follow the role"); the server still refuses on its own.
 *
 * @param {{may?: string[]|null}} holder - a vehicle, or a timeline row
 * @param {string} operation - `view`, `log`, `edit`, `delete`, `own`, or `book` on a vehicle
 * @return {boolean} true only when the list names it
 */
export function may(holder, operation) {
	return holder.may?.includes(operation) === true
}
