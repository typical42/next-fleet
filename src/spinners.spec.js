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

describe('NcLoadingIcon', () => {
	/**
	 * It draws `role="img"` with an empty `aria-label` unless given a name, which axe reports as
	 * an image without alternative text, and a screen reader announces as nothing. An audit sees it
	 * only while a request is still out, so it fails on a slow server and passes on a fast one.
	 */
	it('is never drawn without a name', () => {
		const unnamed = readdirSync(SRC, { recursive: true, encoding: 'utf8' })
			.filter((path) => path.endsWith('.vue'))
			.flatMap((path) => (readFileSync(join(SRC, path), 'utf8').match(/<NcLoadingIcon\b(?:"[^"]*"|[^">])*>/g) ?? [])
				.filter((tag) => !/\s:?name=/.test(tag))
				.map((tag) => `${path}: ${tag}`))
		expect(unnamed).toEqual([])
	})
})
