/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, login, ocs, open, opened, removeVehicles, row } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M6-E2E-'
// The accounts each run invents.
const people = 'm6e2e-'

/** @type {import('@playwright/test').BrowserContext[]} */
const grantees = []

test.afterEach(async () => {
	await Promise.all(grantees.splice(0).map((context) => context.close()))
})

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
})

/**
 * A new account, signed in with cookies of its own on the overview.
 *
 * @param {import('@playwright/test').Page} page - the run account's page
 * @param {import('@playwright/test').Browser} browser - for the account's own context
 * @param {string} uid - the account to make
 * @return {Promise<import('@playwright/test').Page>} its page
 */
async function account(page, browser, uid) {
	const password = `${uid}-Secret-2026!`
	await ocs(page, 'POST', '/cloud/users', { userid: uid, password })
	const context = await browser.newContext()
	grantees.push(context)
	const screen = await context.newPage()
	await login(screen, uid, password)
	await screen.goto(appPage)

	return screen
}

/**
 * Gives an account a role from the Access section, as the owner does: found by the search, the
 * role picked, and the row the server answers with on the list.
 *
 * @param {import('@playwright/test').Locator} sheet - the open Edit vehicle sheet
 * @param {string} uid - who
 * @param {string} role - the word on screen
 */
async function give(sheet, uid, role) {
	const page = sheet.page()
	await sheet.getByRole('combobox', { name: 'Give access to' }).fill(uid)
	await page.getByRole('option').filter({ hasText: uid }).click()
	await sheet.getByRole('combobox', { name: 'Role', exact: true }).click()
	await page.getByRole('option', { name: role, exact: true }).click()
	await sheet.getByRole('button', { name: 'Give access' }).click()
	await expect(sheet.locator('.grants__row').filter({ hasText: uid })).toBeVisible()
}

/**
 * PRD M6's story on one vehicle: a driver and a viewer get access through the Access section,
 * each sees what their role allows, the owner takes the driver's back, and a driver given it again
 * leaves on their own.
 */
test('the owner gives access, each role is offered what it may do, and access ends', async ({ page, browser }, testInfo) => {
	// Two new accounts, whose first sign-in took 30 s each on an idle stack.
	test.setTimeout(300_000)
	const plate = `${plates}${testInfo.project.name}`
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate } })
	const now = Math.floor(Date.now() / 1000)
	// The owner's trip, which the driver will see and may not change.
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/trips`,
		body: { started_at: now - 7200, started_at_off: 0, ended_at: now - 3600, ended_at_off: 0, start_odo: 1000, end_odo: 1040, to_label: 'Stuttgart', category: 'private' },
	})
	const driverId = `${people}${testInfo.project.name}-driver-${Date.now()}`
	const viewerId = `${people}${testInfo.project.name}-viewer-${Date.now()}`
	const driver = await account(page, browser, driverId)
	const viewer = await account(page, browser, viewerId)
	// Nothing until the owner gives it.
	await expect(driver.getByText('No vehicles yet')).toBeVisible()

	await page.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await give(sheet, driverId, 'Driver')
	await give(sheet, viewerId, 'Viewer')

	// The driver is told, and finds the vehicle among their own with its owner beneath.
	await driver.reload()
	await driver.getByRole('button', { name: 'Notifications' }).click()
	await expect(driver.getByText(`gave you access to ${plate} as a driver`)).toBeVisible()
	await driver.keyboard.press('Escape')
	await expect(row(driver, plate)).toContainText('Owned by')
	await open(driver, plate)

	// A trip of their own, through the sheet like anybody's.
	await driver.getByRole('button', { name: 'New entry' }).click()
	const entry = driver.getByRole('dialog', { name: 'New entry' })
	await opened(entry)
	await entry.getByRole('textbox', { name: 'Start counter' }).fill('1040')
	await entry.getByRole('textbox', { name: 'End counter' }).fill('1100')
	await entry.getByRole('combobox', { name: 'Destination' }).fill('Ludwigsburg')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// Theirs opens, the owner's is only said, and each says who entered it.
	const timeline = driver.locator('.timeline')
	await expect(timeline.getByRole('button', { name: 'Ludwigsburg' })).toBeVisible()
	await expect(timeline.getByRole('button', { name: 'Stuttgart' })).toHaveCount(0)
	await expect(timeline.locator('.row').filter({ hasText: 'Stuttgart' })).toContainText('Entered by')
	await expect(timeline.locator('.row').filter({ hasText: 'Ludwigsburg' })).toContainText('Entered by')

	// The viewer reads it and is offered no entry.
	await viewer.reload()
	await open(viewer, plate)
	await expect(viewer.locator('.timeline').locator('.row').filter({ hasText: 'Ludwigsburg' })).toBeVisible()
	await expect(viewer.getByRole('button', { name: 'New entry' })).toHaveCount(0)

	// The owner takes the driver's access back, and the vehicle leaves the driver's overview.
	await sheet.getByRole('button', { name: `Remove ${driverId}` }).click()
	await expect(sheet.locator('.grants__row').filter({ hasText: driverId })).toHaveCount(0)
	await driver.goto(appPage)
	await expect(driver.getByText('No vehicles yet')).toBeVisible()

	// Given it again, the driver leaves on their own.
	await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: driverId, grantee_type: 'user', role: 'driver' } })
	await driver.reload()
	await open(driver, plate)
	await driver.getByRole('button', { name: 'Leave vehicle' }).click()
	await driver.getByRole('button', { name: 'Leave', exact: true }).click()
	await expect(driver.getByText('No vehicles yet')).toBeVisible()
	const grants = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}/grants` })
	expect(grants.map((/** @type {{grantee: string}} */ one) => one.grantee)).toEqual([viewerId])
})
