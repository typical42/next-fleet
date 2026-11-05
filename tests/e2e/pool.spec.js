/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { appPage, audit, login, ocs, open, opened, toggle } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'POOL-'
// The accounts and the group each run invents.
const people = 'poole2e-'

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
	const { groups } = await ocs(page, 'GET', `/cloud/groups?search=${people}`)
	for (const gid of groups) {
		await ocs(page, 'DELETE', `/cloud/groups/${gid}`)
	}
})

/**
 * A new account, signed in with cookies of its own, as the administrator makes it for a member of
 * the firm. Its vehicles go with it, so a run that failed leaves nothing in the next one's way.
 *
 * @param {import('@playwright/test').Page} page - the run account's page, which may make accounts
 * @param {import('@playwright/test').Browser} browser - for the account's own context
 * @param {string} uid - the account to make
 * @param {string} name - its display name, which every list shows
 * @return {Promise<import('@playwright/test').Page>} its page, on the app
 */
async function member(page, browser, uid, name) {
	const password = `${uid}-Secret-2026!`
	await ocs(page, 'POST', '/cloud/users', { userid: uid, password, displayName: name, language: 'en' })
	const context = await browser.newContext()
	signedIn.push(context)
	const screen = await context.newPage()
	await login(screen, uid, password)
	await screen.goto(appPage)

	return screen
}

/**
 * Opens the vehicle afresh, with what the server holds now. The address does not name a vehicle
 * opened from the overview, so a reload alone lands on the overview.
 *
 * @param {import('@playwright/test').Page} screen - an account's page
 * @param {string} plate - the vehicle
 */
async function reopen(screen, plate) {
	await screen.goto(appPage)
	await open(screen, plate)
}

/**
 * Picks somebody in one of the sheet's people pickers by the name a person types.
 *
 * @param {import('@playwright/test').Locator} sheet - the open sheet
 * @param {string} label - the picker's label
 * @param {string} name - what to type, and what the option reads
 */
async function pickPerson(sheet, label, name) {
	await sheet.getByRole('combobox', { name: label }).fill(name)
	// A long name is cut in two spans to ellipsise its middle (NcEllipsisedOption), so it is matched as text.
	await sheet.page().getByRole('option').filter({ hasText: name }).click()
}

/**
 * @param {import('@playwright/test').Page} screen - a page on a vehicle's screen
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
 * @param {import('@playwright/test').Page} screen - any page
 * @return {Promise<number>} the minutes left until midnight on the browser's clock
 */
function minutesToMidnight(screen) {
	return screen.evaluate(() => {
		const now = new Date()
		const midnight = new Date(now)
		midnight.setHours(24, 0, 0, 0)
		return (midnight.getTime() - now.getTime()) / 60_000
	})
}

/**
 * The words of the notices in the account's bell that are about the vehicle.
 *
 * @param {import('@playwright/test').Page} screen - the account's page
 * @param {string} plate - the vehicle
 * @return {Promise<string[]>} their subjects
 */
async function told(screen, plate) {
	return (await ocs(screen, 'GET', '/apps/notifications/api/v2/notifications'))
		.map((/** @type {{subject: string}} */ one) => one.subject)
		.filter((/** @type {string} */ subject) => subject.includes(plate))
}

/**
 * A newcomer sets up one pool car with what the screens say, labels and hints only: two drivers,
 * approval for all their vehicles, notices per row, one driver kept from taking the car at once,
 * and a booking for the other. Each driver then meets what those rules say.
 */
test('a newcomer sets up a pool car for two drivers, and each driver meets its rules', async ({ page, browser }, testInfo) => {
	// Three new accounts, whose first sign-in took 30 s each on an idle stack.
	test.setTimeout(420_000)
	const run = `${people}${testInfo.project.name}-${Date.now()}`
	const plate = `${plates}${testInfo.project.name}`
	const crew = `${run}-drivers`
	await ocs(page, 'POST', '/cloud/groups', { groupid: crew, displayname: 'Pool drivers' })
	const owner = await member(page, browser, `${run}-olga`, 'Olga Poolcar')
	const anna = await member(page, browser, `${run}-anna`, 'Anna Poolcar')
	const ben = await member(page, browser, `${run}-ben`, 'Ben Poolcar')
	for (const uid of [`${run}-anna`, `${run}-ben`]) {
		await ocs(page, 'POST', `/cloud/users/${uid}/groups`, { groupid: crew })
	}

	// The vehicle.
	await owner.locator('.app-navigation').getByRole('button', { name: 'New vehicle' }).click()
	const creating = owner.getByRole('dialog', { name: 'New vehicle' })
	await creating.getByRole('textbox', { name: 'Registration plate' }).fill(plate)
	await creating.getByRole('combobox', { name: 'Engine' }).click()
	await owner.getByRole('option').filter({ hasText: 'Petrol' }).click()
	await creating.getByRole('textbox', { name: 'Counter reading' }).fill('12000')
	await creating.getByRole('button', { name: 'Add vehicle' }).click()
	await expect(owner.getByRole('heading', { name: plate })).toBeVisible()

	// Access: a freelancer's car shows no booking rules until somebody else has access.
	await owner.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = owner.getByRole('dialog', { name: 'Edit vehicle' })
	await opened(sheet)
	await expect(sheet.getByRole('heading', { name: 'Bookings' })).toHaveCount(0)
	for (const name of ['Anna Poolcar', 'Ben Poolcar']) {
		await pickPerson(sheet, 'Give access to', name)
		await sheet.getByRole('combobox', { name: 'Role', exact: true }).click()
		await owner.getByRole('option', { name: 'Driver', exact: true }).click()
		await sheet.getByRole('button', { name: 'Give access' }).click()
		await expect(sheet.getByRole('listitem').filter({ hasText: name })).toBeVisible()
	}
	await expect(sheet.getByRole('heading', { name: 'Bookings' })).toBeVisible()

	// Approval for all the owner's vehicles, where the vehicle's own choice points to.
	await expect(sheet.getByRole('combobox', { name: 'Bookings need approval' })).toBeVisible()
	await expect(sheet.getByText('As for all my vehicles (now: off)')).toBeVisible()
	const [tab] = await Promise.all([
		owner.waitForEvent('popup'),
		sheet.getByRole('link', { name: 'Set it for all your vehicles in the settings' }).click(),
	])
	// With or without index.php, as the server rewrites its URLs.
	await expect(tab).toHaveURL((url) => url.pathname.endsWith('/settings/user/additional'))
	const settings = tab.locator('#nextfleet-settings')
	await expect(settings.getByRole('heading', { name: 'Bookings' })).toBeVisible()
	// A checkbox draws no <label>: its words take the click (NcCheckboxRadioSwitch).
	await settings.getByText('Bookings need approval', { exact: true }).click()
	await expect(settings.getByRole('switch', { name: 'Bookings need approval' })).toBeChecked()
	await audit(tab, 'the personal settings, Bookings and all', ['#nextfleet-settings'])
	await tab.close()

	await expect(sheet).toBeVisible()
	await sheet.getByRole('button', { name: 'Cancel' }).click()
	// The app reads the settings once, at its start.
	await owner.reload()
	await open(owner, plate)
	await owner.getByRole('button', { name: 'Edit vehicle' }).click()
	await opened(sheet)
	await expect(sheet.getByText('As for all my vehicles (now: on)')).toBeVisible()

	// Anna asks for every use of the car. The switch saves at once.
	const annasRow = sheet.getByRole('listitem').filter({ hasText: 'Anna Poolcar' })
	await expect(annasRow.getByText('May take the car at once, without approval, if it is back by midnight.')).toBeVisible()
	await toggle(annasRow, 'Take it now without approval').click()
	await expect(annasRow.getByRole('switch', { name: 'Take it now without approval' })).not.toBeChecked()
	await expect(sheet.getByRole('listitem').filter({ hasText: 'Ben Poolcar' }).getByRole('switch', { name: 'Take it now without approval' })).toBeChecked()

	// The notices: the owner hears all five, the drivers only that somebody takes the car now.
	await expect(sheet.getByText('With nobody on the list, the notices go to you.')).toBeVisible()
	const mine = sheet.getByRole('group', { name: 'You (owner)' })
	for (const event of ['Returned, with the counter and the level', 'Cancelled', 'Moved to another start or end']) {
		await mine.getByText(event).click()
	}
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()

	await owner.getByRole('button', { name: 'Edit vehicle' }).click()
	await opened(sheet)
	await pickPerson(sheet, 'Booking notices go to', 'Olga Poolcar')
	const olgasRow = sheet.getByRole('group', { name: 'Olga Poolcar', exact: true })
	await expect(olgasRow.getByRole('checkbox', { checked: true })).toHaveCount(5)
	await pickPerson(sheet, 'Booking notices go to', 'Pool drivers')
	const crewsRow = sheet.getByRole('group', { name: 'Group Pool drivers' })
	await expect(crewsRow.getByRole('checkbox', { checked: true })).toHaveCount(5)
	for (const event of ['Booked ahead', 'Returned, with the counter and the level', 'Cancelled', 'Moved to another start or end']) {
		await crewsRow.getByText(event).click()
		await expect(crewsRow.getByRole('checkbox', { name: event })).not.toBeChecked()
	}
	await expect(crewsRow.getByRole('checkbox', { name: 'Taken now' })).toBeChecked()
	await audit(owner, 'the edit sheet, every section shown', ['#nextfleet', '[role="dialog"]'])
	await sheet.getByRole('button', { name: 'Cancel' }).click()
	await expect(sheet).toBeHidden()

	// Ben's trip tomorrow, booked by the owner for him.
	await owner.locator('#nextfleet').getByRole('main').getByRole('button', { name: 'Book', exact: true }).click()
	const booking = owner.getByRole('dialog', { name: 'Book vehicle' })
	await opened(booking)
	await booking.getByRole('combobox', { name: 'For' }).click()
	await owner.getByRole('option').filter({ hasText: 'Ben Poolcar' }).click()
	await booking.getByLabel('Start', { exact: true }).fill(await localMoment(owner, 1, '09:00'))
	await booking.getByLabel('End', { exact: true }).fill(await localMoment(owner, 1, '11:00'))
	await booking.getByRole('textbox', { name: 'Purpose' }).fill('Customer visit')
	await booking.getByRole('button', { name: 'Book', exact: true }).click()
	await expect(booking).toBeHidden()
	const ownersList = owner.locator('.bookings__booking')
	await expect(ownersList.getByText('Not seen yet by Ben Poolcar')).toBeVisible()

	// Ben finds it, says he saw it; Anna, who hears only of a car taken now, hears nothing of it.
	await reopen(ben, plate)
	await expect(ben.getByText('Booked for you by Olga Poolcar')).toBeVisible()
	await ben.getByRole('button', { name: 'Notifications' }).click()
	await ben.getByRole('button', { name: 'Seen', exact: true }).click()
	await expect(ben.getByRole('button', { name: 'Seen', exact: true })).toHaveCount(0)
	await ben.keyboard.press('Escape')
	await reopen(owner, plate)
	await expect(ownersList.filter({ hasText: 'Customer visit' }).getByText('Booked', { exact: true })).toBeVisible()
	await expect(ownersList.getByText(/Not seen yet/)).toHaveCount(0)
	expect((await told(anna, plate)).filter((subject) => subject.includes(' booked '))).toEqual([])

	// Anna's Take it now asks, even back by midnight; the owner says no.
	test.skip(await minutesToMidnight(anna) < 10, 'Take it now is back by 23:59 today, and the browser clock is within 10 minutes of midnight')
	await reopen(anna, plate)
	await anna.getByRole('button', { name: 'Take it now', exact: true }).click()
	const annaTakes = anna.getByRole('dialog', { name: 'Take the car' })
	await opened(annaTakes)
	// Back by midnight, so the block alone makes it a request.
	await annaTakes.getByLabel('Back by').fill(await localMoment(anna, 0, '23:59'))
	await expect(annaTakes.getByText('This is a request that waits for approval.')).toBeVisible()
	await annaTakes.getByRole('button', { name: 'Request' }).click()
	await expect(annaTakes.getByRole('alert').getByText('The booking waits for approval.')).toBeVisible()
	// The dialog's corner button is named Close too, but by its label, not its text.
	await annaTakes.getByRole('button').filter({ hasText: 'Close' }).click()
	await expect(anna.locator('.bookings__booking').getByText('Waiting for approval')).toBeVisible()

	await reopen(owner, plate)
	const asked = ownersList.filter({ hasText: 'Requested' })
	await asked.getByRole('button', { name: 'Answer' }).click()
	await asked.getByRole('button', { name: 'Decline' }).click()
	await expect(ownersList.getByText('Declined')).toBeVisible()

	// Ben may take it at once: the owner and Anna hear of it.
	await reopen(ben, plate)
	await ben.getByRole('button', { name: 'Take it now', exact: true }).click()
	const benTakes = ben.getByRole('dialog', { name: 'Take the car' })
	await opened(benTakes)
	await benTakes.getByLabel('Back by').fill(await localMoment(ben, 0, '23:59'))
	await benTakes.getByRole('textbox', { name: /Counter reading/ }).fill('12000')
	await benTakes.getByRole('textbox', { name: 'Tank or battery (%)' }).fill('80')
	await benTakes.getByRole('button', { name: 'Take the car' }).click()
	await expect(benTakes).toBeHidden()
	await expect(ben.locator('.bookings__booking').getByText('Out')).toBeVisible()

	// Ben's take, and none for Anna's request, which never took the car.
	for (const screen of [anna, owner]) {
		const taken = (await told(screen, plate)).filter((subject) => subject.includes(` took ${plate} until `))
		expect(taken).toHaveLength(1)
		expect(taken[0]).toMatch(/^Ben Poolcar took /)
	}
})
