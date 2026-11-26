/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, audit, categorise, choice, fits, login, ocs, opened, settingsPage } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M33-E2E-'
// The accounts each run invents. Deleting them is the cleanup: their vehicles and lists go with them.
const people = 'm33e2e-'

/** @type {import('@playwright/test').BrowserContext[]} */
const signedIn = []

test.afterEach(async () => {
	await Promise.all(signedIn.splice(0).map((context) => context.close()))
})

test.beforeEach(async ({ page }) => {
	await login(page)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
})

/**
 * A new account, signed in with cookies of its own, on the app. An account of its own, so the
 * partner and cost-centre lists it meets are its own and nobody else's.
 *
 * @param {import('@playwright/test').Page} page - the run account's page, which may make accounts
 * @param {import('@playwright/test').Browser} browser - for the account's own context
 * @param {string} uid - the account to make
 * @return {Promise<import('@playwright/test').Page>} its page, on the app
 */
async function member(page, browser, uid) {
	const password = `${uid}-Secret-2026!`
	await ocs(page, 'POST', '/cloud/users', { userid: uid, password, language: 'en' })
	const context = await browser.newContext()
	signedIn.push(context)
	const screen = await context.newPage()
	await login(screen, uid, password)
	await screen.goto(appPage)

	return screen
}

/**
 * @param {import('@playwright/test').Page} screen - any page
 * @param {number} days - from today on the browser's clock
 * @param {string} time - `HH:MM`
 * @return {Promise<string>} that moment as a datetime-local field takes it
 */
function localMoment(screen, days, time) {
	return screen.evaluate(({ days, time }) => {
		const day = new Date()
		day.setDate(day.getDate() + days)
		const two = (/** @type {number} */ n) => String(n).padStart(2, '0')
		return `${day.getFullYear()}-${two(day.getMonth() + 1)}-${two(day.getDate())}T${time}`
	}, { days, time })
}

/**
 * One business trip as the driver types it, every field the logbook asks for filled in.
 *
 * @param {import('@playwright/test').Page} screen - a page showing the vehicle
 * @param {object} trip - what the driver types
 * @param {number} trip.days - its day, from today
 * @param {string} trip.hour - `HH`, the hour it sets off; it arrives an hour later
 * @param {string} trip.start - the counter at departure
 * @param {string} trip.end - the counter at arrival
 * @param {string} trip.partner - the partner, as typed
 */
async function businessTrip(screen, { days, hour, start, end, partner }) {
	await screen.getByRole('button', { name: 'New entry' }).click()
	const entry = screen.getByRole('dialog', { name: 'New entry' })
	await opened(entry)
	await entry.getByLabel('Departure', { exact: true }).fill(await localMoment(screen, days, `${hour}:00`))
	await entry.getByLabel('Arrival', { exact: true }).fill(await localMoment(screen, days, `${String(Number(hour) + 1).padStart(2, '0')}:00`))
	await entry.getByRole('textbox', { name: 'Odometer at departure' }).fill(start)
	await entry.getByRole('textbox', { name: 'Odometer at arrival' }).fill(end)
	await categorise(screen, entry)
	await entry.getByRole('combobox', { name: 'Purpose' }).fill('Kundentermin')
	await entry.getByRole('combobox', { name: 'Starting point' }).fill('Stuttgart')
	await entry.getByRole('combobox', { name: 'Destination' }).fill('Pforzheim')
	await entry.getByRole('combobox', { name: 'Business partner' }).fill(partner)
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()
}

/**
 * The year's Fahrtenbuch, opened from Reports in a tab of its own, as the trips' lines read.
 *
 * @param {import('@playwright/test').Page} screen - the owner's page
 * @param {string} plate - the vehicle
 * @param {string} year - the year of the trips
 * @return {Promise<string>} the text of its table of trips
 */
async function logbook(screen, plate, year) {
	await screen.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
	const reports = screen.locator('#nextfleet').getByRole('main')
	await reports.getByRole('combobox', { name: 'Vehicle' }).click()
	await screen.getByRole('option').filter({ hasText: plate }).click()
	await reports.getByRole('textbox', { name: 'Year' }).fill(year)
	const [tab] = await Promise.all([screen.waitForEvent('popup'), reports.getByRole('link', { name: 'Open logbook' }).click()])
	await expect(tab.getByRole('heading', { name: `Fahrtenbuch ${year}` })).toBeVisible()
	const lines = await tab.getByRole('table').innerText()
	await tab.close()

	return lines
}

/**
 * A freelancer with one car types one partner in two spellings, finds both on the Partners page,
 * and merges them. The trips keep what was typed, and the logbook reads as before: a merge moves
 * links, which the Fahrtenbuch does not print, so it is no late change three weeks on.
 */
test('a freelancer merges two spellings of a partner, and the logbook reads as before', async ({ page, browser }, testInfo) => {
	// A new account, whose first sign-in took 30 s on an idle stack.
	test.setTimeout(240_000)
	const uid = `${people}${testInfo.project.name}-frida-${Date.now()}`
	const plate = `${plates}${testInfo.project.name}-partners`
	const owner = await member(page, browser, uid)

	// Three weeks back, past the logbook's lock delay of seven days, so a change the logbook printed
	// would show as late. The counter starts before the first trip.
	const vehicle = await api(owner, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: ['diesel'], logbook_mode: true } })
	await api(owner, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value: 10000, read_at: Math.floor(Date.now() / 1000) - 40 * 86400, read_at_off: 0 },
	})
	await owner.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(owner.getByRole('heading', { name: plate })).toBeVisible()
	// One day for both, so one year's logbook holds both lines.
	await businessTrip(owner, { days: -21, hour: '08', start: '10000', end: '10056', partner: 'Müller Maschinenbau GmbH' })
	await businessTrip(owner, { days: -21, hour: '14', start: '10056', end: '10112', partner: 'Müller Maschinenbau' })
	const year = (await localMoment(owner, -21, '08:00')).slice(0, 4)
	const before = await logbook(owner, plate, year)
	expect(before).toContain('Müller Maschinenbau GmbH')

	// The Partners page lists both spellings: every trip's partner is in the owner's list.
	// Exact: the vehicle's own entry in the navigation has the word in its name.
	await owner.locator('.app-navigation').getByRole('link', { name: 'Partners', exact: true }).click()
	const screen = owner.locator('#nextfleet').getByRole('main')
	await expect(screen.getByRole('heading', { name: 'Partners' })).toBeVisible()
	const listed = screen.getByRole('listitem')
	await expect(listed).toHaveCount(2)
	await audit(owner, 'the partners list')

	await screen.getByRole('link', { name: 'Müller Maschinenbau', exact: true }).click()
	await expect(screen.getByRole('heading', { name: 'Müller Maschinenbau', exact: true })).toBeVisible()
	await screen.getByRole('combobox', { name: 'Period' }).click()
	await owner.getByRole('option').filter({ hasText: 'All time' }).click()
	await expect(screen.getByRole('table')).toContainText('Pforzheim')
	await audit(owner, 'one partner, with its trips')

	// Merge it into the other spelling. It asks first, because nothing undoes it.
	await screen.getByRole('combobox', { name: 'Merge into' }).click()
	await owner.getByRole('option').filter({ hasText: 'Müller Maschinenbau GmbH' }).click()
	await screen.getByRole('button', { name: 'Merge…' }).click()
	const asking = owner.getByRole('dialog', { name: 'Merge partners' })
	await opened(asking)
	await expect(asking).toContainText('What the trips say stays as it was typed.')
	await audit(owner, 'the merge question', ['[role="dialog"]'])
	await asking.getByRole('button', { name: 'Merge', exact: true }).click()
	await expect(asking).toBeHidden()

	// The record it went into holds both trips, each as it was typed.
	await expect(screen.getByRole('heading', { name: 'Müller Maschinenbau GmbH' })).toBeVisible()
	await screen.getByRole('combobox', { name: 'Period' }).click()
	await owner.getByRole('option').filter({ hasText: 'All time' }).click()
	const trips = screen.getByRole('table')
	// The head and two trips.
	await expect(trips.getByRole('row')).toHaveCount(3)
	for (const typed of ['Müller Maschinenbau', 'Müller Maschinenbau GmbH']) {
		await expect(trips.getByRole('cell', { name: typed, exact: true })).toHaveCount(1)
	}
	await screen.getByRole('button', { name: 'Back to the partners' }).click()
	await expect(screen.getByRole('listitem')).toHaveCount(1)

	// The logbook prints the same lines: no late change, no change without a record.
	const after = await logbook(owner, plate, year)
	expect(after).toBe(before)
	expect(after).not.toContain('Nachträglich')
	expect(after).not.toContain('Geändert ohne Protokoll')
})

/**
 * A pool owner sets up cost centres, a vehicle's default and a budget with what the screens say.
 * Their driver logs a trip on another cost centre and a fill-up on the default, and the owner sees
 * both in Statistics, with the budget past its first warning.
 */
test('a pool owner plans cost centres and a budget, and sees what the driver logged', async ({ page, browser }, testInfo) => {
	// Two new accounts, whose first sign-in took 30 s each on an idle stack.
	test.setTimeout(300_000)
	const ownerId = `${people}${testInfo.project.name}-olaf-${Date.now()}`
	const driverId = `${people}${testInfo.project.name}-dana-${Date.now()}`
	const owner = await member(page, browser, ownerId)
	const driver = await member(page, browser, driverId)
	const plate = `${plates}${testInfo.project.name}-pool`
	const vehicle = await api(owner, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: ['diesel'], currency: 'EUR' } })
	await api(owner, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value: 20000, read_at: Math.floor(Date.now() / 1000) - 86400, read_at_off: 0 },
	})
	// The Access section has its own case in pool.spec.js. A shared vehicle turns cost centres on.
	await api(owner, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: driverId, grantee_type: 'user', role: 'driver' } })

	// The list, in the personal settings. An owner who shares a vehicle has it without a switch.
	await owner.goto(settingsPage)
	const settings = owner.locator('#nextfleet-settings')
	await expect(settings.getByRole('heading', { name: 'Cost centres and budgets' })).toBeVisible()
	await expect(settings.getByRole('switch', { name: 'Use cost centres and budgets' })).toHaveCount(0)
	await expect(settings.getByText('No cost centres yet.')).toBeVisible()
	for (const [code, name] of [['4711', 'Vertrieb'], ['4712', 'Service']]) {
		await settings.getByRole('textbox', { name: 'Code', exact: true }).fill(code)
		await settings.getByRole('textbox', { name: 'Name', exact: true }).fill(name)
		await settings.getByRole('button', { name: 'Add cost centre' }).click()
		await expect(settings.getByRole('listitem').filter({ hasText: `${code} ${name}` })).toBeVisible()
	}
	await audit(owner, 'the cost centres in the settings', ['#nextfleet-settings'])
	// Here, not in the sweep of every screen in m5-slice.spec.js: the run account it signs in as
	// would put the field on the sheets of every spec that runs beside it.
	await owner.setViewportSize({ width: 320, height: 640 })
	await fits(owner, 'the cost centres in the settings, on a phone')
	await owner.setViewportSize({ width: 1280, height: 800 })

	// The vehicle's default, then its budget for this year, in Edit vehicle > Costs.
	await owner.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(owner.getByRole('heading', { name: plate })).toBeVisible()
	await owner.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = owner.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await sheet.getByRole('combobox', { name: 'Default cost centre' }).click()
	await owner.getByRole('option').filter({ hasText: '4711 Vertrieb' }).click()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()

	await owner.getByRole('button', { name: 'Edit vehicle' }).click()
	await opened(sheet)
	await expect(sheet.getByText('4711 Vertrieb')).toBeVisible()
	// The browser's year, which the field starts on.
	const year = (await localMoment(owner, 0, '12:00')).slice(0, 4)
	await expect(sheet.getByRole('textbox', { name: 'Year' })).toHaveValue(year)
	await sheet.getByRole('textbox', { name: 'Amount', exact: true }).fill('100')
	await sheet.getByRole('button', { name: 'Add budget' }).click()
	const budget = sheet.getByRole('listitem').filter({ hasText: year }).filter({ has: owner.getByRole('button', { name: 'Change' }) })
	await expect(budget).toContainText('€100.00')
	await audit(owner, 'the edit sheet, with a budget', ['[role="dialog"]'])
	await sheet.getByRole('button', { name: 'Cancel' }).click()
	await expect(sheet).toBeHidden()

	// The driver's trip goes to Service; the field starts on the vehicle's default.
	await driver.goto(`${appPage}?vehicle=${vehicle.uuid}`)
	await expect(driver.getByRole('heading', { name: plate })).toBeVisible()
	await driver.getByRole('button', { name: 'New entry' }).click()
	const entry = driver.getByRole('dialog', { name: 'New entry' })
	await opened(entry)
	await choice(entry, 'Distance').click()
	await entry.getByRole('textbox', { name: 'Distance' }).fill('42')
	await categorise(driver, entry)
	await expect(entry.getByText('4711 Vertrieb')).toBeVisible()
	await entry.getByRole('combobox', { name: 'Cost centre' }).click()
	await driver.getByRole('option').filter({ hasText: '4712 Service' }).click()
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// The fill-up keeps the default.
	await driver.getByRole('button', { name: 'New entry' }).click()
	await opened(entry)
	await choice(entry, 'Energy').click()
	await entry.getByRole('textbox', { name: 'Amount (l)' }).fill('50')
	await entry.getByRole('textbox', { name: 'Total price' }).fill('90,00')
	await expect(entry.getByText('4711 Vertrieb')).toBeVisible()
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// The owner's Statistics for the vehicle and this year: the budget 90 % used, and each cost centre.
	await owner.goto(appPage)
	await owner.locator('.app-navigation').getByRole('link', { name: 'Statistics' }).click()
	const statistics = owner.locator('#nextfleet').getByRole('main')
	await statistics.getByRole('combobox', { name: 'Vehicle' }).click()
	await owner.getByRole('option').filter({ hasText: plate }).click()
	await statistics.getByRole('combobox', { name: 'Range' }).click()
	// By its text: the dropdown splits a long label into spans, and the accessible name with it.
	await owner.getByRole('option').filter({ hasText: 'Calendar year' }).click()
	await expect(statistics.getByRole('heading', { name: `Budget for ${year}` })).toBeVisible()
	await expect(statistics.getByRole('meter', { name: `Budget for ${year}` })).toBeVisible()
	await expect(statistics.getByText('€90.00 of €100.00 spent, 90%')).toBeVisible()

	await expect(statistics.getByRole('heading', { name: 'By cost centre' })).toBeVisible()
	// The section and the table in it are both named for their heading; the section holds the table.
	const centres = statistics.getByRole('region', { name: 'By cost centre' }).first().getByRole('row')
	await expect(centres.filter({ hasText: '4711 Vertrieb' })).toContainText('€90.00')
	await expect(centres.filter({ hasText: '4712 Service' }).getByRole('cell', { name: '42 km', exact: true })).toBeVisible()
	await audit(owner, 'the statistics, by cost centre and with a budget')
})
