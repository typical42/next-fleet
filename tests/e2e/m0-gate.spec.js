/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

// The gate runs against every project in playwright.config.js, i.e. against
// every supported Nextcloud major, and one bundle has to satisfy all of them.
const appPage = '/index.php/apps/nextfleet/'

// templates/main.php renders this empty; anything inside it was put there by Vue.
const vueRoot = '#nextfleet'

// The stack's admin, straight from .docker/compose.yml. Basic auth is no
// shortcut here: Nextcloud answers a browser with a redirect to this form
// whatever the Authorization header says.
test.beforeEach(async ({ page }) => {
	await page.goto('/login')
	await page.locator('#user').fill('admin')
	await page.locator('#password').fill('admin')
	await page.locator('form button[type="submit"]').click()
	await page.waitForURL((url) => !url.pathname.startsWith('/login'))
})

test('the app page loads and mounts the Vue root', async ({ page }) => {
	const response = await page.goto(appPage)

	expect(response?.status()).toBe(200)
	// Not just the status: an unauthenticated request answers 200 with the
	// login page, so the gate has to see that it stayed on the app's own URL.
	expect(new URL(page.url()).pathname).toBe(appPage)
	await expect(page.locator(`${vueRoot} > *`)).toHaveCount(1)
})

test('the app page reports nothing to the console', async ({ page }) => {
	/** @type {string[]} */
	const problems = []
	// Attached after the login in beforeEach, so only the app's own page counts.
	page.on('console', (message) => {
		if (message.type() === 'error') {
			problems.push(`console: ${message.text()}`)
		}
	})
	page.on('pageerror', (error) => problems.push(`uncaught: ${error.message}`))

	await page.goto(appPage)
	await expect(page.locator(`${vueRoot} > *`)).toHaveCount(1)

	expect(problems).toEqual([])
})
