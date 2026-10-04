/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, dav, inboxAt, login, ocs, opened, photo, row } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M7-E2E-'
// The accounts each run invents. Deleting them takes their vehicles and Files out of reach, so it
// is the cleanup; the rows stay, pseudonymised (docs/development.md).
const people = 'm7e2e-'

/** @type {import('@playwright/test').BrowserContext[]} */
const accounts = []

test.afterEach(async () => {
	await Promise.all(accounts.splice(0).map((context) => context.close()))
})

test.beforeEach(async ({ page }) => {
	await login(page)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
})

/**
 * A new account, signed in with cookies of its own on the overview. An account of its own, so the
 * inbox folder it chooses is nobody else's preference.
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
	accounts.push(context)
	const screen = await context.newPage()
	await login(screen, uid, password)
	await screen.goto(appPage)

	return screen
}

/**
 * PRD M7's story on one car two accounts share: the driver books it and nobody books over them, takes
 * it while the owner sees who has it, gives it back and logs the trip the handover prefilled, and
 * files a photographed receipt on a fill-up of theirs in two taps.
 */
test('a driver books the car, takes it, returns it, logs the trip and files a receipt', async ({ page, browser }, testInfo) => {
	// Two new accounts, whose first sign-in took 30 s each on an idle stack.
	test.setTimeout(300_000)
	const ownerId = `${people}${testInfo.project.name}-owner-${Date.now()}`
	const driverId = `${people}${testInfo.project.name}-driver-${Date.now()}`
	const owner = await account(page, browser, ownerId)
	const driver = await account(page, browser, driverId)
	const plate = `${plates}${testInfo.project.name}-pool`
	const vehicle = await api(owner, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: ['diesel'] } })
	await api(owner, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/readings`, body: { value: 1000, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 } })
	// The Access section has its own case in m6-slice.spec.js.
	await api(owner, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: driverId, grantee_type: 'user', role: 'driver' } })

	// The driver books it from the next full hour, the sheet's default.
	await driver.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await driver.getByRole('button', { name: 'Book', exact: true }).click()
	const booking = driver.getByRole('dialog', { name: 'Book vehicle' })
	await opened(booking)
	await booking.getByRole('textbox', { name: 'Purpose' }).fill('Kundentermin')
	await booking.getByRole('button', { name: 'Book', exact: true }).click()
	await expect(booking).toBeHidden()
	const booked = driver.locator('.bookings__booking').filter({ hasText: 'Kundentermin' })
	await expect(booked).toContainText('Booked')

	// The owner's booking over the same hours is refused, naming the driver; the form stays.
	await owner.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await owner.getByRole('button', { name: 'Book', exact: true }).click()
	const over = owner.getByRole('dialog', { name: 'Book vehicle' })
	await opened(over)
	await over.getByRole('button', { name: 'Book', exact: true }).click()
	await expect(over).toContainText(`Booked by ${driverId}`)
	await over.getByRole('button', { name: 'Cancel' }).click()
	await expect(over).toBeHidden()

	// The driver takes it at the vehicle's counter, which the sheet prefills.
	await booked.getByRole('button', { name: 'Take the car' }).click()
	const taking = driver.getByRole('dialog', { name: 'Take the car' })
	await opened(taking)
	await expect(taking.getByRole('textbox', { name: 'Counter reading (km)' })).toHaveValue('1000')
	await taking.getByRole('textbox', { name: 'Tank or battery (%)' }).fill('80')
	await taking.getByRole('button', { name: 'Take the car' }).click()
	await expect(taking).toBeHidden()
	await expect(booked).toContainText('Out')

	// While it is out, the owner's overview says who has it.
	await owner.goto(appPage)
	await expect(row(owner, plate).locator('.overview__holder')).toContainText(`With ${driverId}`)

	// Given back, the trip opens prefilled from the handover; the category is the driver's to say.
	await booked.getByRole('button', { name: 'Return the car' }).click()
	const returning = driver.getByRole('dialog', { name: 'Return the car' })
	await opened(returning)
	await returning.getByRole('textbox', { name: 'Counter reading (km)' }).fill('1042')
	await returning.getByRole('button', { name: 'Return the car' }).click()
	await expect(returning).toBeHidden()
	const trip = driver.getByRole('dialog', { name: 'New entry' })
	await opened(trip)
	await expect(trip.getByRole('textbox', { name: 'Odometer at departure' })).toHaveValue(/^1[,.]?000$/)
	await expect(trip.getByRole('textbox', { name: 'Odometer at arrival' })).toHaveValue(/^1[,.]?042$/)
	await expect(trip.getByRole('combobox', { name: 'Purpose' })).toHaveValue('Kundentermin')
	await trip.getByRole('combobox', { name: 'Category' }).click()
	await driver.getByRole('option', { name: 'Private', exact: true }).click()
	await trip.getByRole('combobox', { name: 'Destination' }).fill('Esslingen')
	await trip.getByRole('button', { name: 'Save' }).click()
	await expect(trip).toBeHidden()

	// The booking links the trip it became.
	await booked.getByRole('button', { name: 'Show the trip' }).click()
	const logged = driver.getByRole('dialog', { name: 'Edit entry' })
	await opened(logged)
	await expect(logged.getByRole('textbox', { name: 'Odometer at arrival' })).toHaveValue(/^1[,.]?042$/)
	await logged.getByRole('button', { name: 'Cancel' }).click()
	await expect(logged).toBeHidden()

	// A fill-up of theirs, and its receipt photographed into the inbox.
	await api(driver, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/energy`,
		body: { energy: 'diesel', filled_at: Math.floor(Date.now() / 1000), filled_at_off: 0, amount: 35000, total: 6100, vat_rate: 1900, full_tank: true },
	})
	await inboxAt(driver, driverId, 'Belege')
	expect((await dav(driver, driverId, 'PUT', 'Belege/Tankbeleg.png', { body: photo })).status).toBe(201)

	// Two taps: the file, then Attach - the vehicle and the newest own entry are preselected.
	await driver.reload()
	await driver.getByRole('link', { name: /Inbox/ }).click()
	await driver.locator('.inbox__file').filter({ hasText: 'Tankbeleg.png' }).click()
	const sheet = driver.getByRole('dialog', { name: 'Attach document' })
	await opened(sheet)
	await sheet.getByRole('button', { name: 'Attach', exact: true }).click()
	await expect(sheet).toBeHidden()
	await expect(driver.getByText('Nothing waiting')).toBeVisible()

	await driver.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(driver.locator('.documents__paper').filter({ hasText: 'Tankbeleg.png' })).toContainText('Belongs to a fill-up')
})

/**
 * PRD M7, "Log it from the receipt": a receipt for a fill-up nobody entered yet becomes that
 * fill-up, dated when the phone saved it, and is filed on it.
 */
test('a receipt in the inbox is logged as a new fill-up and filed on it', async ({ page, browser }, testInfo) => {
	// A new account, whose first sign-in took 30 s on an idle stack.
	test.slow()
	const uid = `${people}${testInfo.project.name}-receipt-${Date.now()}`
	const driver = await account(page, browser, uid)
	const plate = `${plates}${testInfo.project.name}`
	const vehicle = await api(driver, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: ['diesel'] } })
	await inboxAt(driver, uid, 'Belege')
	// Saved at noon UTC, so the day is the same wherever the browser is.
	const saved = Date.UTC(2026, 8, 30, 12, 0) / 1000
	const receipt = await dav(driver, uid, 'PUT', 'Belege/Tankbeleg.pdf', { body: '%PDF-1.4\n%%EOF\n', headers: { 'X-OC-Mtime': String(saved) } })
	expect(receipt.status).toBe(201)

	await driver.reload()
	await driver.getByRole('link', { name: /Inbox/ }).click()
	await driver.locator('.inbox__file').filter({ hasText: 'Tankbeleg.pdf' }).click()
	const sheet = driver.getByRole('dialog', { name: 'Attach document' })
	await opened(sheet)
	await sheet.getByRole('button', { name: 'New fill-up' }).click()

	const entry = driver.getByRole('dialog', { name: 'New entry' })
	await opened(entry)
	await expect(entry.getByRole('radiogroup', { name: 'Entry type' })).toHaveCount(0)
	await expect(entry.getByLabel('Date', { exact: true })).toHaveValue(/^2026-09-30T/)
	await entry.getByRole('textbox', { name: 'Amount (l)' }).fill('41')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// Filed, it waits no more; on the vehicle it belongs to the fill-up just written.
	await expect(driver.getByText('Nothing waiting')).toBeVisible()
	await driver.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	const paper = driver.locator('.documents__paper').filter({ hasText: 'Tankbeleg.pdf' })
	await expect(paper).toContainText('Belongs to a fill-up')
})
