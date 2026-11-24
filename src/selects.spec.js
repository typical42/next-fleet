/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment node
// It reads files, which node:fs cannot do from happy-dom's http `import.meta.url`.

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const SRC = fileURLToPath(new URL('.', import.meta.url))

/**
 * Every opening tag of a component in the app's own templates.
 *
 * @param {string} name - the component
 * @return {string[]} each tag, prefixed with its file
 */
function tags(name) {
	return readdirSync(SRC, { recursive: true, encoding: 'utf8' })
		.filter((path) => path.endsWith('.vue'))
		.flatMap((path) => (readFileSync(join(SRC, path), 'utf8').match(new RegExp(`<${name}\\b(?:"[^"]*"|[^">])*>`, 'g')) ?? [])
			.map((tag) => `${path}: ${tag}`))
}

describe('NcSelect and NcSelectUsers', () => {
	/** Without it the list can open under the sheet, or off the screen of a phone (utils/dropdown.js). */
	it('place their list with placeDropdown', () => {
		const unplaced = [...tags('NcSelect'), ...tags('NcSelectUsers')]
			.filter((tag) => !tag.includes(':calculate-position="placeDropdown"'))
		expect(unplaced).toEqual([])
	})

	/**
	 * A search field opens the phone's keyboard on a tap, which hides half the sheet and invites
	 * text the select does not take. Only a list that grows with the fleet or its people, or a long
	 * code list people search by word, is worth that (docs/ui.md).
	 */
	it('say whether their list is searched', () => {
		const unsaid = tags('NcSelect').filter((tag) => !/\s:?searchable[\s=/>]/.test(tag))
		expect(unsaid).toEqual([])
	})
})
