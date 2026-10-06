/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { readFileSync } from 'node:fs'

import { expect, test } from '@playwright/test'

import { api, appPage, dav, importing, login, ocs, tile } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M9-E2E-'
// The accounts each run invents. Deleting them takes their vehicles and Files out of reach, so it
// is the cleanup; the rows stay, pseudonymised (docs/development.md).
const people = 'm9e2e-'

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
 * file picker shows its Files and nobody else's.
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
 * A day some months back, past the 12th so `M/D/YYYY` cannot be read the other way round and no
 * date order is asked, and inside the header's default period of the last twelve months.
 *
 * @param {number} months - how many months back
 * @param {number} day - the day of that month, 13 to 28
 * @return {Date} noon UTC of that day
 */
function dayBack(months, day) {
	const now = new Date()
	return new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - months, day, 12))
}

/**
 * @param {Date} day - a day, as `dayBack()` makes one
 * @return {string} it as LubeLogger's en-US export writes it
 */
function lubeloggerDay(day) {
	return `${day.getUTCMonth() + 1}/${day.getUTCDate()}/${day.getUTCFullYear()}`
}

/**
 * @param {import('@playwright/test').Page} page - the owner's page on the vehicle
 * @param {string} name - the file, in the root of their Files
 * @param {string} format - the format step's choice, as the picker names it
 * @return {Promise<import('@playwright/test').Locator>} the import sheet, on its preview
 */
async function preview(page, name, format) {
	const sheet = await importing(page, name, format)
	await sheet.getByRole('button', { name: 'Preview' }).click()
	return sheet
}

/**
 * The owner brings a LubeLogger history in, sees in the preview which row is already there and
 * which cannot be read, imports, takes it back, and then brings a Spritmonitor history in. A
 * driver of the same car is offered no import.
 */
test('the owner previews, imports and undoes an export, then imports another', async ({ page, browser }, testInfo) => {
	// Two new accounts, whose first sign-in took 30 s each on an idle stack.
	test.setTimeout(300_000)
	const ownerId = `${people}${testInfo.project.name}-owner-${Date.now()}`
	const driverId = `${people}${testInfo.project.name}-driver-${Date.now()}`
	const owner = await account(page, browser, ownerId)
	const driver = await account(page, browser, driverId)
	const plate = `${plates}${testInfo.project.name}`
	const vehicle = await api(owner, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: ['diesel'] } })
	await api(owner, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: driverId, grantee_type: 'user', role: 'driver' } })

	// The fill-up the file's first row repeats, entered by hand before the switch.
	const repeated = dayBack(2, 20)
	await api(owner, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/energy`,
		body: { energy: 'diesel', filled_at: repeated.getTime() / 1000, filled_at_off: 0, odo: 52000, amount: 40000, total: 6000, vat_rate: 1900, full_tank: true },
	})

	// The fixture's column names (tests/Fixture/import/README.md), with rows dated inside the
	// default period: one already there, one new, one without its amount.
	const [columns] = readFileSync(new URL('../Fixture/import/lubelogger-fuel.csv', import.meta.url), 'utf8').split('\n')
	const lubelogger = [
		columns,
		`${lubeloggerDay(repeated)},52000,40.00,60.00,,True,False,,,,,,`,
		`${lubeloggerDay(dayBack(1, 25))},52600,36.00,54.00,,True,False,,,Highway,roadtrip,,`,
		`${lubeloggerDay(dayBack(1, 27))},52900,,40.00,,True,False,,,,,,`,
		'',
	].join('\n')
	expect((await dav(owner, ownerId, 'PUT', 'fuel.csv', { body: lubelogger })).status).toBe(201)
	const spritmonitor = readFileSync(new URL('../Fixture/import/spritmonitor-fuel.csv', import.meta.url), 'utf8')
	expect((await dav(owner, ownerId, 'PUT', 'Spritmonitor.csv', { body: spritmonitor })).status).toBe(201)

	await owner.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	const rows = owner.locator('.timeline__rows > li')
	await expect(rows).toHaveCount(1)
	await expect(tile(owner, 'Diesel consumption')).toHaveCount(0)

	// The preview names the row already there and the row it cannot read, and why.
	const fromLubelogger = await preview(owner, 'fuel.csv', 'CSV (LubeLogger format) — fuel')
	await expect(fromLubelogger).toContainText('New: 1. Already there: 1. Not readable: 1.')
	await expect(fromLubelogger).toContainText('Row 2: Already there.')
	await expect(fromLubelogger).toContainText('Row 4: FuelConsumed is empty.')
	await expect(fromLubelogger).toContainText('Not read: FuelEconomy')
	await fromLubelogger.getByRole('button', { name: 'Import', exact: true }).click()
	await expect(fromLubelogger).toBeHidden()

	// The new fill-up closes the hand-entered one's segment: 36 l over 600 km, on its row and in
	// the header.
	const toast = owner.locator('.toast')
	await expect(toast).toContainText('Entries imported: 1. Rows already there, skipped: 1. Rows not readable: 1.')
	await expect(rows).toHaveCount(2)
	await expect(rows.first()).toContainText('6.0 l/100 km')
	await expect(tile(owner, 'Diesel consumption')).toContainText('6.0 l/100 km')

	// Undone, the import leaves what was there before it.
	await toast.getByRole('button', { name: 'Undo' }).click()
	await expect(toast).toBeHidden()
	await expect(rows).toHaveCount(1)
	await expect(tile(owner, 'Diesel consumption')).toHaveCount(0)

	// The second file: a fill type it does not know and a fill-up in francs are left out.
	const fromSpritmonitor = await preview(owner, 'Spritmonitor.csv', 'CSV (Spritmonitor format) — fuel')
	await expect(fromSpritmonitor).toContainText('New: 3. Already there: 0. Not readable: 2.')
	await expect(fromSpritmonitor).toContainText('Row 5: Tankart holds a code this import does not know.')
	await expect(fromSpritmonitor).toContainText('Row 6: Währung names a currency the vehicle is not kept in.')
	await fromSpritmonitor.getByRole('button', { name: 'Import', exact: true }).click()
	await expect(fromSpritmonitor).toBeHidden()
	await expect(toast).toContainText('Entries imported: 3. Rows not readable: 2.')

	// Importing is the owner's and a manager's: a driver is not even shown the way in.
	await driver.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(driver.getByRole('heading', { name: plate })).toBeVisible()
	await expect(driver.getByRole('button', { name: 'Edit vehicle' })).toHaveCount(0)
	await expect(driver.getByRole('button', { name: 'Import from a file…' })).toHaveCount(0)
})
