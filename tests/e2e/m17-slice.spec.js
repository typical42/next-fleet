/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, login, ocs, open, opened, tile } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M17-E2E-'
// The accounts each run invents. Deleting them takes their vehicles out of reach, so it is the
// cleanup (docs/development.md).
const people = 'm17e2e-'

const DAY = 86400

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
 * A new account, signed in with cookies of its own on the overview. Its own, so its preferences
 * are its own too: the header's period is one, and tests/e2e/m3-slice.spec.js moves the run
 * account's while the files run at once.
 *
 * @param {import('@playwright/test').Page} page - the run account's page
 * @param {import('@playwright/test').Browser} browser - for the account's own context
 * @param {import('@playwright/test').TestInfo} testInfo - names the server, which has accounts of its own
 * @param {string} language - the account's
 * @return {Promise<import('@playwright/test').Page>} its page
 */
async function account(page, browser, testInfo, language) {
	// A new account's first sign-in alone took 30 s (tests/e2e/m6-slice.spec.js).
	test.slow()
	const uid = `${people}${testInfo.project.name}-${Date.now()}`
	const password = `${uid}-Secret-2026!`
	await ocs(page, 'POST', '/cloud/users', { userid: uid, password, language })
	const context = await browser.newContext()
	accounts.push(context)
	const screen = await context.newPage()
	await login(screen, uid, password)
	await screen.goto(appPage)

	return screen
}

/**
 * What an NcSelect of the sheet shows as chosen. The combobox is its search field, which is empty
 * once a choice is made; the choice sits beside it.
 *
 * @param {import('@playwright/test').Page} page - the page the sheet is on
 * @param {import('@playwright/test').Locator} sheet - the sheet
 * @param {string} label - the select's label
 * @return {import('@playwright/test').Locator} the chosen entry, none when nothing is chosen
 */
function chosen(page, sheet, label) {
	return sheet.locator('.v-select').filter({ has: page.getByRole('combobox', { name: label }) }).locator('.vs__selected')
}

/**
 * Searches an NcSelect of the sheet and picks what it finds. The options list is rendered outside
 * the dialog.
 *
 * @param {import('@playwright/test').Page} page - the page the sheet is on
 * @param {import('@playwright/test').Locator} sheet - the sheet
 * @param {string} label - the select's label
 * @param {string} search - what is typed
 * @param {string} code - the option's code, which closes each label in parentheses
 */
async function pickCode(page, sheet, label, search, code) {
	await sheet.getByRole('combobox', { name: label }).fill(search)
	await page.getByRole('option').filter({ hasText: `(${code})` }).click()
}

// In German, because nobody searches the list for a code: they type the word they know, and
// "Kombi" is the German word the AC label holds (docs/user/vehicles.md#registration-type).
test('the edit sheet finds a body type by its German word and keeps category, type and body in step', async ({ page, browser }, testInfo) => {
	const german = await account(page, browser, testInfo, 'de')
	// A van, so the pre-fill to a car is a change the screen has to show.
	const plate = `${plates}sheet-${Date.now()}`
	const vehicle = await api(german, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', vehicle_type: 'van' } })
	await german.goto(appPage)
	await open(german, plate)

	await german.getByRole('button', { name: 'Fahrzeug bearbeiten' }).click()
	const sheet = german.getByRole('dialog', { name: 'Fahrzeug bearbeiten' })
	await opened(sheet)
	await expect(chosen(german, sheet, 'Fahrzeugtyp')).toHaveText('Transporter')
	// Closed until there is a category to filter it by.
	await expect(sheet.getByRole('combobox', { name: 'EU-Aufbauart' })).toBeDisabled()

	await pickCode(german, sheet, 'EU-Fahrzeugklasse', 'M1', 'M1')
	await expect(chosen(german, sheet, 'Fahrzeugtyp')).toHaveText('Pkw')

	await pickCode(german, sheet, 'EU-Aufbauart', 'kombi', 'AC')
	await expect(chosen(german, sheet, 'EU-Aufbauart')).toContainText('Kombilimousine')

	// A lorry takes no station wagon, so the body goes with the change.
	await pickCode(german, sheet, 'EU-Fahrzeugklasse', 'N3', 'N3')
	await expect(chosen(german, sheet, 'Fahrzeugtyp')).toHaveText('Lkw')
	await expect(chosen(german, sheet, 'EU-Aufbauart')).toHaveCount(0)

	await expect(sheet.getByRole('combobox', { name: 'Aufbau-Ergänzung' })).toHaveCount(0)
	await pickCode(german, sheet, 'EU-Aufbauart', 'BA', 'BA')
	await pickCode(german, sheet, 'Aufbau-Ergänzung', 'Geschlossen', '03')

	await sheet.getByRole('button', { name: 'Speichern', exact: true }).click()
	await expect(sheet).toBeHidden()
	expect(await api(german, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })).toMatchObject({
		vehicle_type: 'truck',
		eu_category: 'N3',
		body_type: 'BA03',
	})
})

// The header states a figure per engine hour beside each per 100 km on a vehicle that counts both
// (docs/user/costs.md). Two full fill-ups 500 km and 10 h apart, the second of 50 l for 90 €.
test('a vehicle that counts kilometres and engine hours states its figures per engine hour', async ({ page, browser }, testInfo) => {
	const owner = await account(page, browser, testInfo, 'en')
	const plate = `${plates}hours-${Date.now()}`
	const vehicle = await api(owner, {
		method: 'POST',
		path: '/api/vehicles',
		body: { plate, jurisdiction: 'de', vehicle_type: 'truck', engine: 'diesel', energy_types: ['diesel'], currency: 'EUR', odo_unit: 'km', second_unit: 'h' },
	})
	const now = Math.floor(Date.now() / 1000)
	const fill = (/** @type {number} */ daysAgo, /** @type {object} */ counted) => api(owner, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/energy`,
		body: { filled_at: now - daysAgo * DAY, filled_at_off: 0, energy: 'diesel', full_tank: true, ...counted },
	})
	await fill(60, { odo: 10000, second_odo: 500, amount: 40000, total: 7000 })
	await fill(30, { odo: 10500, second_odo: 510, amount: 50000, total: 9000 })

	await owner.goto(appPage)
	await open(owner, plate)
	await expect(tile(owner, 'Diesel consumption')).toContainText('10.0 l/100 km')
	await expect(tile(owner, 'Diesel consumption per engine hour')).toContainText('5.0 l/h')
	// Both fill-ups' costs over the period's 10 h.
	await expect(tile(owner, 'Cost per engine hour')).toContainText('€16.00/h')
	await expect(tile(owner, 'Energy cost per engine hour')).toContainText('€16.00/h')
	// The fill-up that closes both segments says both; a timeline row states no cost per hour.
	await expect(owner.locator('.timeline .row__tail').first()).toContainText('5.0 l/h')
})
