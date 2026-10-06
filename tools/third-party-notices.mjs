/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Prints the licence text of every package the bundle carries, for the tarball's
 * THIRD-PARTY-NOTICES.txt (tools/package.sh).
 *
 * The build's `js/*.license` files name each package with its SPDX identifier, but MIT, ISC and
 * BSD ask for the notice itself to travel with every copy, and Apache-2.0 and the GPL for the
 * licence text. The text comes from the package's own licence file. A package without one gets
 * the text of its licence from LICENSES/, else from another bundled package under the same one;
 * with neither, the script fails rather than ship a bundle without its notices.
 *
 *   node tools/third-party-notices.mjs <root>   (root holds js/, package-lock.json, node_modules/)
 */

import { readdirSync, readFileSync, existsSync } from 'node:fs'
import { join } from 'node:path'

try {
	process.stdout.write(notices(process.argv[2] ?? '.'))
} catch (error) {
	process.stderr.write(`third-party-notices: ${error.message}\n`)
	process.exitCode = 1
}

/**
 * @param {string} root the checkout, after a build
 * @return {string} the notices file
 */
function notices(root) {
	const sidecars = existsSync(join(root, 'js')) ? readdirSync(join(root, 'js')).filter((f) => f.endsWith('.license')) : []
	if (sidecars.length === 0) {
		throw new Error(`no js/*.license in ${root}; run npm run build first`)
	}
	const own = JSON.parse(readFileSync(join(root, 'package.json'), 'utf8')).name
	const lock = JSON.parse(readFileSync(join(root, 'package-lock.json'), 'utf8')).packages

	/** @type {Map<string, {name: string, version: string, licence: string}>} */
	const bundled = new Map()
	for (const sidecar of sidecars) {
		const text = readFileSync(join(root, 'js', sidecar), 'utf8')
		for (const [, name, version, licence] of text.matchAll(/^- (\S+)\n\t- version: (\S+)\n\t- license: (.+)$/gm)) {
			if (name !== own) {
				bundled.set(`${name} ${version}`, { name, version, licence })
			}
		}
	}

	const packages = [...bundled.values()].sort((a, b) => a.name.localeCompare(b.name) || a.version.localeCompare(b.version))
	const texts = new Map(packages.map((p) => [p, licenceFiles(root, lock, p)]))

	const missing = []
	/** @type {Map<string, string>} text, whitespace collapsed => the package it was printed under */
	const printed = new Map()
	const sections = packages.map((p) => {
		const label = `${p.name} ${p.version}`
		let text = texts.get(p)
		if (text.length === 0) {
			const shared = join(root, 'LICENSES', `${p.licence}.txt`)
			const lender = packages.find((other) => other.licence === p.licence && texts.get(other).length > 0)
			if (existsSync(shared)) {
				text = [readFileSync(shared, 'utf8').trim()]
			} else if (lender !== undefined) {
				text = texts.get(lender)
			} else {
				missing.push(label)
			}
		}
		const body = text.join('\n\n')
		// Copies of the GPL differ in how they are wrapped, not in what they say.
		const key = body.replace(/\s+/g, ' ')
		if (printed.has(key)) {
			return `${label} (${p.licence})\n\nThe same text as ${printed.get(key)} above.\n`
		}
		printed.set(key, label)
		return `${label} (${p.licence})\n\n${body}\n`
	})
	if (missing.length > 0) {
		throw new Error(`no licence text found for:\n${missing.join('\n')}`)
	}

	const rule = `\n${'='.repeat(80)}\n\n`
	return `The JavaScript bundle in js/ and the styles in css/ include the packages below.${rule}${sections.join(rule)}`
}

/**
 * @param {string} root the checkout
 * @param {object} lock the lock file's `packages`
 * @param {{name: string, version: string}} bundled a package the bundle names
 * @return {string[]} the texts of its licence files, from the install of exactly that version
 */
function licenceFiles(root, lock, { name, version }) {
	const path = Object.keys(lock).find((p) => (p === `node_modules/${name}` || p.endsWith(`/node_modules/${name}`)) && lock[p].version === version)
	if (path === undefined) {
		return []
	}
	return readdirSync(join(root, path), { withFileTypes: true })
		.filter((entry) => /^(licen[cs]e|copying)/i.test(entry.name))
		.sort((a, b) => a.name.localeCompare(b.name))
		// A folder is REUSE's LICENSES/, one text per licence.
		.flatMap((entry) => entry.isDirectory()
			? readdirSync(join(root, path, entry.name)).sort().map((f) => join(path, entry.name, f))
			: [join(path, entry.name)])
		.map((file) => readFileSync(join(root, file), 'utf8').trim())
}
