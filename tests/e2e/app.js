/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** Where the app answers. Every project in playwright.config.js reaches it on its own port. */
export const appPage = '/index.php/apps/nextfleet/'

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
