/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment node
// This one reads files rather than a component, and happy-dom serves `import.meta.url`
// over http, where node:fs cannot follow it.

import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

/** @param {string} name - a file in the repository root */
function root(name) {
	return JSON.parse(readFileSync(new URL('../../' + name, import.meta.url), 'utf8'))
}

/** @type {{ devDependencies: Record<string, string>, scripts: Record<string, string> }} */
const packageJson = root('package.json')

/** @type {{ packages: Record<string, { version: string }> }} */
const packageLock = root('package-lock.json')

/**
 * The lockfile is the authority: a range is what the repository asks for, this is what
 * `npm ci` puts on disk and what the container runs.
 *
 * @return {string} the version of `@playwright/test` that is actually installed
 */
function installedVersion() {
	const entry = packageLock.packages['node_modules/@playwright/test']
	expect(entry, 'the lockfile holds no @playwright/test').toBeDefined()

	return entry.version
}

/**
 * @return {Record<string, string>} every place the version is written down, by what it says
 */
function pinnedVersions() {
	const range = packageJson.devDependencies['@playwright/test']
	// At most a leading operator, so the version can be compared as one. A range written any
	// other way spans several versions, and this file could not say which one is meant.
	expect(range).toMatch(/^[\^~]?\d+\.\d+\.\d+$/)

	/** @type {Record<string, string>} */
	const pinned = { '@playwright/test': range.replace(/^[\^~]/, '') }
	for (const [name, script] of Object.entries(packageJson.scripts)) {
		const match = /mcr\.microsoft\.com\/playwright:v(\d+\.\d+\.\d+)-/.exec(script)
		if (match !== null) {
			pinned[name] = match[1]
		}
	}

	return pinned
}

describe('the Playwright pin', () => {
	/**
	 * Playwright refuses browsers it did not build (docs/development.md#testing), so the image
	 * and the `@playwright/test` that drives it are one version in three places. Bumping one of
	 * them alone breaks inside a container, with a message about the browser rather than about
	 * the tag - and `npm update` moves the lockfile without touching any of the three, which is
	 * why they are all measured against what is installed rather than against each other.
	 *
	 * Naming the two scripts rather than counting them means a renamed one fails here too,
	 * instead of leaving a check that finds nothing and passes.
	 */
	it('names the installed version in the dependency and in both docker scripts', () => {
		const installed = installedVersion()

		expect(pinnedVersions()).toEqual({
			'@playwright/test': installed,
			'screenshots:docker': installed,
			'test:e2e:docker': installed,
		})
	})
})
