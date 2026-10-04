/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment node
// It reads files, which node:fs cannot do from happy-dom's http `import.meta.url`.

import { readdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

import { t } from './l10n.js'

const SRC = fileURLToPath(new URL('..', import.meta.url))

/**
 * @param {string} folder - where to start
 * @return {string[]} every app source below it, specs left out
 */
function sources(folder) {
	return readdirSync(folder, { withFileTypes: true }).flatMap((entry) => {
		const path = join(folder, entry.name)
		if (entry.isDirectory()) {
			return sources(path)
		}
		return /\.(js|vue)$/.test(entry.name) && !entry.name.endsWith('.spec.js') ? [path] : []
	})
}

describe('t', () => {
	it('shows a name as it was typed', () => {
		expect(t('nextfleet', 'Owned by {name}', { name: 'Anna O\'Brien <R&D>' })).toBe('Owned by Anna O\'Brien <R&D>')
	})

	/**
	 * The wrapper is only safe while every string reaches the page through Vue's escaping; a raw HTML
	 * sink would take a name as markup. And a screen that imports the library's `t` escapes again.
	 */
	it('is the only translator, and nothing writes raw HTML', () => {
		const offenders = sources(SRC)
			.filter((path) => !path.endsWith(join('utils', 'l10n.js')))
			.filter((path) => {
				const code = readFileSync(path, 'utf8')
				return /import\s*\{[^}]*\b(t|n|translate|translatePlural)\b[^}]*\}\s*from\s*'@nextcloud\/l10n'/.test(code)
					|| /v-html|innerHTML|isHTML/.test(code)
			})
			.map((path) => relative(SRC, path))
		expect(offenders).toEqual([])
	})
})
