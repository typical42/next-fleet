/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, choice, login, ocs, opened, removeVehicles, toggle } from './app.js'

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

test('a driver the owner keeps the costs from sees none of them, and still logs their own', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'driver')
	await expect(button(screen, 'Costs')).toBeVisible()

	await page.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await button(page, 'Edit vehicle').click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await expect(sheet.getByRole('switch', { name: 'Drivers and viewers see costs' })).toBeChecked()
	await toggle(sheet, 'Drivers and viewers see costs').click()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()

	// The owner's expense is a cost and nothing else, so it is not on the driver's timeline at all.
	await api(screen, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/expenses`, body: { ...OWNERS, category: 'toll' } })
	await screen.reload()
	await expect(screen.locator('.timeline .row')).toHaveCount(1)
	await expect(screen.locator('.timeline .row').filter({ hasText: 'Toll' })).toBeVisible()
	await expect(button(screen, 'Costs')).toHaveCount(0)
	// The server narrows the expenses to the driver's own, so the chip finds those and no other.
	await choice(screen.locator('.timeline'), 'Expenses').click()
	await expect(screen.locator('.timeline .row')).toHaveCount(1)
	await expect(screen.locator('.timeline .row').filter({ hasText: 'Toll' })).toBeVisible()
})

test('the owner ticks what the booking notices tell, and hears of a driver\'s booking', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'driver')

	await page.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await button(page, 'Edit vehicle').click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await expect(sheet.getByText('With nobody on the list, the notices go to you.')).toBeVisible()
	await expect(sheet.getByRole('checkbox', { name: 'Booked ahead' })).toBeChecked()
	// A checkbox draws no <label>: its words take the click (NcCheckboxRadioSwitch).
	await sheet.getByText('Returned, with the counter and the level').click()
	await expect(sheet.getByRole('checkbox', { name: 'Returned, with the counter and the level' })).toBeChecked()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()
	expect((await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })).booking_notices).toEqual(['booked', 'taken', 'returned'])

	const start = Math.floor(Date.now() / 1000) + 86400
	await api(screen, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/bookings`, body: { starts_at: start, starts_at_off: 0, ends_at: start + 3600, ends_at_off: 0 } })
	const told = (await ocs(page, 'GET', '/apps/notifications/api/v2/notifications'))
		.map((/** @type {{subject: string}} */ one) => one.subject)
		.filter((/** @type {string} */ subject) => subject.includes(vehicle.plate))
	expect(told).toHaveLength(1)
	expect(told[0]).toContain(` booked ${vehicle.plate} from `)
})

test('a driver\'s booking waits for the owner\'s answer, only then is the car taken, and a move asks again', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'driver')

	await page.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await button(page, 'Edit vehicle').click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await toggle(sheet, 'Bookings need approval').click()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()

	const start = Math.floor(Date.now() / 1000) + 86400
	const span = { starts_at: start, starts_at_off: 0, ends_at: start + 3600, ends_at_off: 0 }
	const booking = await api(screen, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/bookings`, body: span })
	await screen.reload()
	const mine = screen.locator('.bookings__booking')
	await expect(mine.getByText('Waiting for approval')).toBeVisible()
	await expect(mine.getByRole('button', { name: 'Take the car' })).toHaveCount(0)

	await page.reload()
	const theirs = page.locator('.bookings__booking')
	await expect(theirs.getByText('Requested')).toBeVisible()
	await theirs.getByRole('button', { name: 'Answer' }).click()
	await theirs.getByRole('textbox').fill('Fill it up after')
	await theirs.getByRole('button', { name: 'Approve' }).click()
	await expect(theirs.getByText('Booked', { exact: true })).toBeVisible()
	await expect(theirs.getByRole('button', { name: 'Answer' })).toHaveCount(0)

	await screen.reload()
	await expect(mine.getByText('Comment: Fill it up after')).toBeVisible()
	await expect(mine.getByRole('button', { name: 'Take the car' })).toBeVisible()
	// The grant's notice is there too.
	const told = (await ocs(screen, 'GET', '/apps/notifications/api/v2/notifications'))
		.map((/** @type {{subject: string}} */ one) => one.subject)
		.filter((/** @type {string} */ subject) => subject.includes(` your booking of ${vehicle.plate} `))
	expect(told).toHaveLength(1)
	expect(told[0]).toContain(' approved ')

	// The yes was to an hour; a second one asks again, and the owner declines it.
	const read = await api(screen, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}/bookings` })
	const approved = read.find((/** @type {{uuid: string}} */ one) => one.uuid === booking.uuid)
	await api(screen, { method: 'PUT', path: `/api/vehicles/${vehicle.uuid}/bookings/${booking.uuid}`, body: { ...span, ends_at: start + 7200, updated_at: approved.updated_at } })
	await page.reload()
	await expect(theirs.getByText('Requested')).toBeVisible()
	await theirs.getByRole('button', { name: 'Answer' }).click()
	await theirs.getByRole('button', { name: 'Decline' }).click()
	await expect(theirs.getByText('Declined')).toBeVisible()
	await screen.reload()
	await expect(mine.getByText('Declined')).toBeVisible()
})

test('a driver\'s take-now kept past midnight says it becomes a request, and stays to say it waits', async ({ page, browser }, testInfo) => {
	const { screen, vehicle } = await holding(page, browser, testInfo.project.name, 'driver')

	await page.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await button(page, 'Edit vehicle').click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await toggle(sheet, 'Bookings need approval').click()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()

	await screen.reload()
	await screen.getByRole('button', { name: 'Take it now', exact: true }).click()
	const taking = screen.getByRole('dialog', { name: 'Take the car' })
	await opened(taking)
	// Tomorrow 09:00 on the browser's clock, which is the one the sheet and the server measure by.
	const tomorrow = await screen.evaluate(() => {
		const day = new Date()
		day.setDate(day.getDate() + 1)
		const two = (/** @type {number} */ n) => String(n).padStart(2, '0')
		return `${day.getFullYear()}-${two(day.getMonth() + 1)}-${two(day.getDate())}T09:00`
	})
	await taking.getByLabel('Back by').fill(tomorrow)
	await expect(taking.getByText('Kept past midnight, this is a request that waits for approval.')).toBeVisible()
	await expect(taking.getByLabel(/Counter reading/)).toHaveCount(0)

	await taking.getByRole('button', { name: 'Request' }).click()
	await expect(taking.getByRole('alert').getByText('The booking waits for approval.')).toBeVisible()
	// The dialog's corner button is named Close too, but by its label, not its text.
	await taking.getByRole('button').filter({ hasText: 'Close' }).click()
	await expect(taking).toBeHidden()
	await expect(screen.locator('.bookings__booking').getByText('Waiting for approval')).toBeVisible()
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
