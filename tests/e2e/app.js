/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { AxeBuilder } from '@axe-core/playwright'
import { expect } from '@playwright/test'

import { runAccount } from './accounts.js'

/** Where the app answers. Every project in playwright.config.js reaches it on its own port. */
export const appPage = '/index.php/apps/nextfleet/'

/**
 * Nextcloud's own personal settings page. The app has no seventh screen: it registers a section on
 * this one and mounts a second bundle into it (lib/Settings/Personal.php, docs/ui.md).
 */
export const settingsPage = '/index.php/settings/user/additional'

/**
 * Signs in, as the run's account unless told otherwise. Basic auth is no shortcut here: Nextcloud
 * answers a browser with a redirect to this form whatever the Authorization header says, and the
 * login page then answers 200.
 *
 * @param {import('@playwright/test').Page} page - the page to sign in
 * @param {string} [user] - the account
 * @param {string} [password] - its password
 */
export async function login(page, user = runAccount().uid, password = runAccount().password) {
	await page.goto('/login')
	await page.locator('#user').fill(user)
	await page.locator('#password').fill(password)
	await page.locator('form button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.startsWith('/login'))
}

/**
 * Signs out, which deletes the session's token: a sign-in that never signs out leaves it among the
 * account's devices until it expires.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 */
export async function logout(page) {
	// From a page that carries the CSRF token, which a failed test may not have left open.
	await page.goto(appPage)
	const token = await page.evaluate(() => document.head.dataset.requesttoken ?? '')
	// Left open, the app notices its session end and sends itself to the login form, aborting the
	// logout's own navigation midway.
	await page.goto('about:blank')
	await page.goto(`/index.php/logout?requesttoken=${encodeURIComponent(token)}`)
	await page.waitForURL((url) => url.pathname.startsWith('/login'))
}

/**
 * The app's API from inside the signed-in page, which is the only place that holds both the
 * session cookie and the CSRF token.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {object} call - what to ask for
 * @param {string} call.method - the HTTP verb
 * @param {string} call.path - below the app's own route prefix
 * @param {object} [call.body] - sent as JSON
 * @return {Promise<any>} the parsed answer
 */
export function api(page, call) {
	return page.evaluate(async ({ method, path, body }) => {
		const response = await fetch(`/index.php/apps/nextfleet${path}`, {
			method,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: document.head.dataset.requesttoken ?? '',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		// The status, because a refused call otherwise arrives as whatever the caller does to
		// an error page it took for a fleet.
		if (!response.ok) {
			throw new Error(`${method} ${path} answered ${response.status}`)
		}

		return response.json()
	}, call)
}

/**
 * Nextcloud's OCS API from inside the signed-in page, as whoever signed it in.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} method - the HTTP verb
 * @param {string} path - below /ocs/v2.php
 * @param {object} [body] - sent as JSON
 * @return {Promise<any>} `ocs.data`
 */
export function ocs(page, method, path, body) {
	return page.evaluate(async ({ method, path, body }) => {
		const response = await fetch(`/ocs/v2.php${path}`, {
			method,
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: document.head.dataset.requesttoken ?? '',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			throw new Error(`${method} ${path} answered ${response.status}`)
		}

		return (await response.json()).ocs.data
	}, { method, path, body })
}

/**
 * A vehicle's row on the overview. The navigation lists the same vehicles and Nextcloud's chrome
 * has landmarks of its own, so the row is the one in the app's own content area - and in the
 * fleet's own list, because the hint above it lists vehicles as well.
 *
 * @param {import('@playwright/test').Page} page - a page showing the overview
 * @param {string} plate - the label to find it by
 * @return {import('@playwright/test').Locator} the row
 */
export function row(page, plate) {
	return page.locator('#nextfleet').getByRole('main').locator('.overview__list')
		.getByRole('listitem').filter({ hasText: plate })
}

/**
 * @param {import('@playwright/test').Page} page - a page showing a vehicle
 * @param {string} label - the tile's term
 * @return {import('@playwright/test').Locator} the header tile of that name
 */
export function tile(page, label) {
	return page.locator('.kpis .tile').filter({ has: page.getByRole('term').getByText(label, { exact: true }) })
}

/**
 * Opens a vehicle from the overview rather than from the navigation, which is behind a toggle at
 * 320 px - the row is the way in at every width.
 *
 * @param {import('@playwright/test').Page} page - a page showing the overview
 * @param {string} plate - the label to find it by
 */
export async function open(page, plate) {
	await row(page, plate).locator('.list-item__anchor').click()
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()
}

/**
 * One choice of a chooser, as a finger reaches it: the radio itself is visually hidden and the
 * click is taken by the element around it, so a click on the input would land outside the
 * viewport. The name is still the radio's, which is what a screen reader announces.
 *
 * @param {import('@playwright/test').Locator} within - the sheet or screen the chooser is on
 * @param {string} label - the word on screen (docs/ui.md#languages)
 * @return {import('@playwright/test').Locator} what to click
 */
export function choice(within, label) {
	return within.getByRole('radio', { name: label }).locator('xpath=..')
}

/**
 * A switch, as a finger reaches it: the checkbox behind it sits under the toggle graphic at
 * `z-index: -1`, so a click aimed at the input itself is taken by what is drawn over it. The
 * label is what a finger and a screen reader both use, and `for` carries the click to the input.
 *
 * @param {import('@playwright/test').Locator} within - the sheet or screen the switch is on
 * @param {string} label - the word beside it (docs/ui.md#languages)
 * @return {import('@playwright/test').Locator} what to click
 */
export function toggle(within, label) {
	return within.locator('label').filter({ hasText: label })
}

/**
 * Audits what the app itself renders. Nextcloud's own header and settings menu are outside this
 * app's reach, so including them would fail the run on somebody else's markup.
 *
 * @param {import('@playwright/test').Page} page - the page as it stands
 * @param {string} screen - what it is showing, so a failure names it
 * @param {string[]} [within] - the subtrees the app owns on that screen
 */
export async function audit(page, screen, within = ['#nextfleet']) {
	const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
	for (const selector of within) {
		builder.include(selector)
	}

	const audited = await builder.analyze()

	// The offending element is in the message, because the rule alone rarely says which one it is.
	expect(audited.violations.map((violation) =>
		`${screen}: ${violation.id} — ${violation.help} — ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`))
		.toEqual([])
}

/**
 * Fails when something the app draws is wider than the box it sits in. Axe does not look at layout,
 * and a sheet clips what spills past its edge rather than scrolling the page - so a checkbox cut
 * off at 320 px passes every audit. A truncation the stylesheet asks for is not a spill, and
 * neither is a region that scrolls sideways on purpose.
 *
 * @param {import('@playwright/test').Page} page - the page as it stands
 * @param {string} screen - what it is showing, so a failure names it
 * @param {string[]} [scrolls] - selectors of the regions that are meant to scroll sideways
 */
export async function fits(page, screen, scrolls = []) {
	const spills = await page.evaluate((scrolls) => {
		const boxes = [document.documentElement, ...document.querySelectorAll('#nextfleet *, #nextfleet-settings *, [role="dialog"] *')]
		return boxes.filter((box) => {
			const style = getComputedStyle(box)
			return box.scrollWidth > box.clientWidth + 1
				&& (box === document.documentElement || style.overflowX !== 'visible')
				&& style.textOverflow !== 'ellipsis'
				&& !scrolls.some((selector) => box.matches(selector))
		}).map((box) => `${box.tagName.toLowerCase()}.${[...box.classList].join('.')} is ${box.scrollWidth} wide in ${box.clientWidth}`)
	}, scrolls)

	expect(spills, screen).toEqual([])
}

/**
 * Waits for a sheet to be all the way open. A dialog fades in, and it counts as visible from the
 * first frame of that: an audit taken there measures the text against a background it is still
 * blended with and reports a contrast violation that was over in 200 ms.
 *
 * @param {import('@playwright/test').Locator} sheet - the dialog that was just asked for
 */
export async function opened(sheet) {
	await expect(sheet).toBeVisible()
	await expect(sheet).toHaveCSS('opacity', '1')
}

/**
 * A vehicle with a counter, written the short way. The screens are what these files test; getting
 * one on screen to test them is not.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} plate - the label to find it by
 * @param {number} value - its counter, as a first Reading
 * @return {Promise<any>} the vehicle as the server made it
 */
export async function add(page, plate, value) {
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 },
	})

	return vehicle
}

/**
 * WebDAV on an account's Files from inside its signed-in page, as the Files app writes.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} uid - whose Files: the account the page is signed in as
 * @param {string} method - the HTTP verb
 * @param {string} path - below their Files
 * @param {object} [options] - what else to send
 * @param {string|number[]} [options.body] - the request body: text, or bytes
 * @param {Record<string, string>} [options.headers] - beside the CSRF token
 * @return {Promise<{status: number, text: string}>} the answer, whole
 */
export function dav(page, uid, method, path, { body, headers = {} } = {}) {
	return page.evaluate(async ({ uid, method, path, body, headers }) => {
		const response = await fetch(`/remote.php/dav/files/${uid}/${path}`, {
			method,
			headers: { ...headers, requesttoken: document.head.dataset.requesttoken ?? '' },
			// Bytes cross into the page as numbers: a string would arrive re-encoded as UTF-8.
			body: Array.isArray(body) ? new Uint8Array(body) : body,
		})
		return { status: response.status, text: await response.text() }
	}, { uid, method, path, body, headers })
}

/**
 * Asks Files for a file's id, which is what the file picker hands over.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} uid - whose Files
 * @param {string} path - below their Files, encoded
 * @return {Promise<number>} its `file_id`
 */
export async function fileId(page, uid, path) {
	const found = await dav(page, uid, 'PROPFIND', path, {
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		body: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const id = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(found.text)?.[1]
	expect(id, found.text).toBeDefined()
	return Number(id)
}

/**
 * Makes a folder in the account's Files and chooses it as their inbox, the way the settings block
 * stores it: by its file id.
 *
 * @param {import('@playwright/test').Page} page - the account's page
 * @param {string} uid - the account
 * @param {string} folder - the folder's name
 */
export async function inboxAt(page, uid, folder) {
	expect((await dav(page, uid, 'MKCOL', folder)).status).toBe(201)
	await api(page, { method: 'PUT', path: '/api/preferences', body: { inbox_folder: await fileId(page, uid, folder) } })
}

/**
 * Picks a file in Nextcloud's picker and chooses it.
 *
 * @param {import('@playwright/test').Locator} picker - the picker's dialog
 * @param {string} name - the file's name
 * @param {string} [folder] - the folder it is in, below the root of the account's Files
 */
export async function pick(picker, name, folder) {
	const file = picker.getByRole('row').filter({ hasText: name })
	if (folder !== undefined) {
		// The picker opens where it was last left, which after an earlier run is the folder itself.
		const into = picker.locator(`[data-filename="${folder}"]`)
		await expect(into.or(file)).toBeVisible()
		if (await file.count() === 0) {
			await into.click()
		}
	}
	await file.click()
	await picker.getByRole('button', { name: 'Choose' }).click()
}

/**
 * From the vehicle screen through the picker to the import sheet's format step, a format chosen.
 *
 * @param {import('@playwright/test').Page} page - a page showing the vehicle screen
 * @param {string} name - the file
 * @param {string} format - the format step's choice, as the picker names it
 * @param {string} [folder] - the folder the file is in, below the root of the account's Files
 * @return {Promise<import('@playwright/test').Locator>} the import sheet, all the way open
 */
export async function importing(page, name, format, folder) {
	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const editing = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(editing)
	await editing.getByRole('button', { name: 'Import from a file…' }).click()
	await pick(page.getByRole('dialog', { name: 'Choose an export file' }), name, folder)

	const sheet = page.getByRole('dialog', { name: 'Import from a file' })
	await opened(sheet)
	await sheet.getByRole('combobox').first().click()
	// NcSelect splits a long option into two spans, so its accessible name gains a space.
	await page.getByRole('option').filter({ hasText: format }).click()
	return sheet
}

/** A 1 × 1 PNG, as small as an image the preview service still takes for one. */
export const photo = [...Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', 'base64')]

/**
 * Every vehicle a spec file made on an earlier run. Cleaning up front rather than afterwards
 * leaves a failed run's rows where a human can look at them, and still makes the next run find
 * one vehicle rather than two.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} prefix - the plate prefix that file owns
 */
export async function removeVehicles(page, prefix) {
	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })
	for (const vehicle of fleet) {
		if (String(vehicle.plate ?? '').startsWith(prefix)) {
			await api(page, {
				method: 'DELETE',
				// A DELETE carries no body, so the token it is checked against travels in the
				// query string (docs/architecture.md#concurrency).
				path: `/api/vehicles/${vehicle.uuid}?updated_at=${vehicle.updated_at}`,
			})
		}
	}
}
