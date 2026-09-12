/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { AxeBuilder } from '@axe-core/playwright'
import { expect } from '@playwright/test'

/** Where the app answers. Every project in playwright.config.js reaches it on its own port. */
export const appPage = '/index.php/apps/nextfleet/'

/**
 * Nextcloud's own personal settings page. The app has no seventh screen: it registers a section on
 * this one and mounts a second bundle into it (lib/Settings/Personal.php, docs/ui.md).
 */
export const settingsPage = '/index.php/settings/user/additional'

/**
 * Signs in as the stack's admin, straight from .docker/compose.yml. Basic auth is no shortcut
 * here: Nextcloud answers a browser with a redirect to this form whatever the Authorization
 * header says, and the login page then answers 200.
 *
 * @param {import('@playwright/test').Page} page - the page to sign in
 */
export async function login(page) {
	await page.goto('/login')
	await page.locator('#user').fill('admin')
	await page.locator('#password').fill('admin')
	await page.locator('form button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.startsWith('/login'))
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
