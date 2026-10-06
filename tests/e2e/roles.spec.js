/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, login, ocs, opened, removeVehicles } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'ROLES-'
// The accounts each run invents.
const people = 'rolese2e-'

/** An expense of the owner's, which only a manager and the owner may change. */
const OWNERS = { spent_at: 1750000000, spent_at_off: 120, amount: 400, category: 'parking' }

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
 * A vehicle of the run account's with one expense it entered, and a page on its screen signed in
 * as somebody holding `role` on it - the run account itself for the owner.
 *
 * @param {import('@playwright/test').Page} page - the run account's page
 * @param {import('@playwright/test').Browser} browser - for a second account's own cookies
 * @param {string} project - `testInfo.project.name`
 * @param {string} role - owner, manager, driver or viewer
 * @return {Promise<{screen: import('@playwright/test').Page, vehicle: any}>} the signed-in page,
 *   on the vehicle's screen with its timeline read, and the vehicle
 */
async function holding(page, browser, project, role) {
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: `${plates}${role}` } })
	await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/expenses`, body: OWNERS })

	let screen = page
	if (role !== 'owner') {
		// A new account and its first sign-in took 30 s on an idle stack.
		test.slow()
		const uid = `${people}${project}-${role}-${Date.now()}`
		const password = `${uid}-Secret-2026!`
		await ocs(page, 'POST', '/cloud/users', { userid: uid, password })
		await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: uid, grantee_type: 'user', role } })
		const context = await browser.newContext()
		grantees.push(context)
		screen = await context.newPage()
		await login(screen, uid, password)
	}
	await screen.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(screen.getByRole('heading', { name: vehicle.plate })).toBeVisible()
	await expect(screen.locator('.timeline .row')).toHaveCount(1)

	return { screen, vehicle }
}

/**
 * @param {import('@playwright/test').Page} screen - a page on the vehicle screen
 * @param {string} name - what the button says
 * @return {import('@playwright/test').Locator} the button in the app's own content area
 */
function button(screen, name) {
	return screen.locator('#nextfleet').getByRole('main').getByRole('button', { name, exact: true })
}

/**
 * Opens a timeline row's sheet and expects it to offer the delete.
 *
 * @param {import('@playwright/test').Page} screen - a page on the vehicle screen
 * @param {string} name - the row's name
 */
async function deletable(screen, name) {
	await screen.locator('.timeline').getByRole('button', { name }).click()
	const sheet = screen.getByRole('dialog', { name: 'Edit entry' })
	await opened(sheet)
	await expect(sheet.getByRole('button', { name: 'Delete' })).toBeVisible()
	await sheet.getByRole('button', { name: 'Cancel' }).click()
}

test('the owner is offered everything, the vehicle\'s delete and its access included', async ({ page, browser }, testInfo) => {
	const { screen } = await holding(page, browser, testInfo.project.name, 'owner')

	for (const name of ['New entry', 'Edit vehicle', 'QR sticker', 'Add document', '+ Reminder']) {
		await expect(button(screen, name)).toBeVisible()
	}
	await deletable(screen, 'Parking')

	await button(screen, 'Edit vehicle').click()
	const sheet = screen.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await expect(sheet.getByRole('button', { name: 'Delete vehicle' })).toBeVisible()
	await expect(sheet.getByRole('heading', { name: 'Access' })).toBeVisible()
})

test('a manager is offered everything but the vehicle\'s delete and its access', async ({ page, browser }, testInfo) => {
	const { screen } = await holding(page, browser, testInfo.project.name, 'manager')

	for (const name of ['New entry', 'Edit vehicle', 'QR sticker', 'Add document', '+ Reminder']) {
		await expect(button(screen, name)).toBeVisible()
	}
	await deletable(screen, 'Parking')

	await button(screen, 'Edit vehicle').click()
	const sheet = screen.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await expect(sheet.getByRole('button', { name: 'Save' })).toBeVisible()
	await expect(sheet.getByRole('button', { name: 'Delete vehicle' })).toHaveCount(0)
	await expect(sheet.getByRole('heading', { name: 'Access' })).toHaveCount(0)
})

test('a driver logs and changes their own entries, and nobody else\'s', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'driver')

	// A paper takes its entry's rule, so a driver files the receipt of their own fill-up (docs/ui.md).
	for (const name of ['New entry', 'QR sticker', 'Add document']) {
		await expect(button(screen, name)).toBeVisible()
	}
	for (const name of ['Edit vehicle', '+ Reminder']) {
		await expect(button(screen, name)).toHaveCount(0)
	}
	// The owner's row is said and opens nothing.
	await expect(screen.locator('.timeline .row').filter({ hasText: 'Parking' })).toBeVisible()
	await expect(screen.locator('.timeline').getByRole('button', { name: 'Parking' })).toHaveCount(0)

	await api(screen, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/expenses`, body: { ...OWNERS, category: 'toll' } })
	await screen.reload()
	await deletable(screen, 'Toll')
})

test('a viewer is offered nothing that writes', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'viewer')

	await expect(button(screen, 'Costs')).toBeVisible()
	for (const name of ['New entry', 'QR sticker', 'Edit vehicle', 'Add document', '+ Reminder']) {
		await expect(button(screen, name)).toHaveCount(0)
	}
	await expect(screen.locator('.timeline .row').filter({ hasText: 'Parking' })).toBeVisible()
	await expect(screen.locator('.timeline').getByRole('button', { name: 'Parking' })).toHaveCount(0)

	// The sticker's link lands a viewer on the screen and opens no sheet there.
	await screen.goto(`${appPage}?vehicle=${vehicle.uuid}&entry=new`)
	await expect(button(screen, 'Costs')).toBeVisible()
	await expect(screen.getByRole('dialog')).toHaveCount(0)
})
