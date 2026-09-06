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
