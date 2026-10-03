/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * The run's own account on each server, so no spec writes on `admin`, whose fleet is the demo
 * (docs/development.md#local-dev-environment). It is in the `admin` group because the specs make
 * the other accounts they need through Nextcloud's provisioning API.
 *
 * The stack's admin from .docker/compose.yml signs these calls with Basic auth and no cookie,
 * which leaves no session or app password behind on it.
 */

const admin = `Basic ${Buffer.from('admin:admin').toString('base64')}`

/**
 * Every account and group a run makes: the run's own and those the spec files invent under their
 * prefixes (`m4e2e-` and the like). What deleting one takes with it is in docs/development.md#testing.
 */
const made = /^(nextfleet-e2e-|m\d+e2e-|rolese2e-)/

/**
 * @param {string} baseURL - the server
 * @return {Promise<boolean>} whether it answers at all: a run filtered to one project may leave the
 *   other server down, and its specs do not run then
 */
async function reachable(baseURL) {
	try {
		return (await fetch(`${baseURL}/status.php`)).ok
	} catch {
		return false
	}
}

/**
 * @param {string} baseURL - the server
 * @param {string} method - the HTTP verb
 * @param {string} path - below /ocs/v2.php
 * @param {object} [body] - sent as JSON
 * @return {Promise<any>} `ocs.data`
 */
async function ocs(baseURL, method, path, body) {
	const response = await fetch(`${baseURL}/ocs/v2.php${path}`, {
		method,
		headers: { Authorization: admin, Accept: 'application/json', 'Content-Type': 'application/json', 'OCS-APIRequest': 'true' },
		body: body === undefined ? undefined : JSON.stringify(body),
	})
	if (!response.ok) {
		throw new Error(`${method} ${path} on ${baseURL} answered ${response.status}: ${await response.text()}`)
	}

	return (await response.json()).ocs.data
}

/**
 * @param {string} baseURL - the server
 */
async function removeMade(baseURL) {
	// `search` matches anywhere in a name, so the prefix is checked here.
	/** @type {{users: string[]}} */
	const { users } = await ocs(baseURL, 'GET', '/cloud/users?search=e2e-')
	for (const uid of users.filter((uid) => made.test(uid))) {
		await ocs(baseURL, 'DELETE', `/cloud/users/${encodeURIComponent(uid)}`)
	}
	/** @type {{groups: string[]}} */
	const { groups } = await ocs(baseURL, 'GET', '/cloud/groups?search=e2e-')
	for (const gid of groups.filter((gid) => made.test(gid))) {
		await ocs(baseURL, 'DELETE', `/cloud/groups/${encodeURIComponent(gid)}`)
	}
}

/**
 * The run's account, made fresh, after what a crashed run left. A new uid each run, since Nextcloud
 * refuses a uid again while a home folder of the old one is still on disk.
 *
 * @param {import('@playwright/test').FullConfig} config - the projects, one per server
 * @return {Promise<() => Promise<void>>} the teardown
 */
export default async function setup(config) {
	/** @type {string[]} */
	const servers = []
	for (const baseURL of new Set(config.projects.map((project) => project.use.baseURL))) {
		if (await reachable(baseURL)) {
			servers.push(baseURL)
		}
	}
	const { uid, password } = runAccount()
	for (const baseURL of servers) {
		await removeMade(baseURL)
		await ocs(baseURL, 'POST', '/cloud/users', { userid: uid, password, groups: ['admin'], language: 'en' })
		// The first sign-in copies the skeleton into the home, which is slow enough to time out a
		// spec that met it; WebDAV signs in without leaving a session either.
		const home = await fetch(`${baseURL}/remote.php/dav/files/${uid}/`, {
			method: 'PROPFIND',
			headers: { Authorization: `Basic ${Buffer.from(`${uid}:${password}`).toString('base64')}`, Depth: '0' },
		})
		if (home.status !== 207) {
			throw new Error(`${uid}'s Files on ${baseURL} answered ${home.status}`)
		}
	}

	return async () => {
		for (const baseURL of servers) {
			await removeMade(baseURL)
		}
	}
}

/**
 * The account every spec signs in as unless it says otherwise, as playwright.config.js named it.
 *
 * @return {{uid: string, password: string}} its login
 */
export function runAccount() {
	const uid = process.env.NEXTFLEET_E2E_USER
	if (uid === undefined) {
		throw new Error('NEXTFLEET_E2E_USER is unset - run the specs through playwright.config.js')
	}

	return { uid, password: `${uid}-Secret-2026!` }
}
