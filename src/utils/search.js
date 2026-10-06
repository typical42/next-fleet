/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { ref } from 'vue'

/**
 * A select's search over the server, which keeps the answer to what was typed last: answers can
 * arrive out of order, and an older one must not replace a newer list.
 *
 * @template T
 * @param {(term: string) => Promise<T[]>} lookup - the server's search
 * @return {{ found: import('vue').Ref<T[]>, search: (term: string) => Promise<void> }} the matches,
 *     and the select's `search` handler
 */
export function latestSearch(lookup) {
	const found = /** @type {import('vue').Ref<T[]>} */ (ref([]))
	let typed = ''

	/** @param {string} term - what was typed */
	async function search(term) {
		typed = term
		/** @type {T[]} */
		let answer = []
		try {
			answer = term.trim() === '' ? [] : await lookup(term)
		} catch {
			// No matches: the field stays usable for the next try.
		}
		if (term === typed) {
			found.value = answer
		}
	}

	return { found, search }
}
