/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { AxeBuilder } from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

import { api, appPage, login, settingsPage } from './app.js'

// Every vehicle this file makes wears this prefix, and every run deletes what it finds under it
// before starting. Cleaning up front rather than afterwards leaves a failed run's rows where a
// human can look at them, and still makes the next run find one vehicle rather than two.
const plates = 'E2E-'

// What the create sheet fills in, and what the vehicle screen must then say back. The grouping is
// the admin session's locale rather than the app's choice (docs/ui.md#languages), and a run
// against a German session would read 148.500.
const starting = '120000'
const recorded = '148 500'
const counter = '148,500 km'

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	// The preferences are this user's own and three of these tests read them back, so they are put
	// where the run expects them for the same reason the vehicles are: up front, and by name.
	await api(page, {
		method: 'PUT',
		path: '/api/preferences',
		body: { jurisdiction: 'de', dismissed_hints: [] },
	})
	await page.goto(appPage)
})

test('a vehicle and a reading of its counter both reach the list', async ({ page }) => {
	const plate = `${plates}${Date.now()}`

	await page.locator('.app-navigation').getByRole('button', { name: 'New vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'New vehicle' })
	await sheet.getByRole('textbox', { name: 'Registration plate' }).fill(plate)
	await sheet.getByRole('textbox', { name: 'Manufacturer' }).fill('Toyota')
	await sheet.getByRole('textbox', { name: 'Model' }).fill('Hilux')
	// The one field that is a code on one side and a word on the other (docs/ui.md#languages).
	await sheet.getByRole('combobox', { name: 'Engine' }).click()
	await option(page, 'Diesel').click()
	await sheet.getByRole('textbox', { name: 'Counter reading' }).fill(starting)
	await sheet.getByRole('button', { name: 'Add vehicle' }).click()

	// Adding a vehicle opens it, so the sheet's own answer is the screen that replaces it.
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()

	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'Odometer' })

	// A counter is whole, so `7,2` is a question for the driver rather than a number to round
	// (docs/ui.md). The sheet stays open with the value it was given.
	await entry.getByRole('textbox', { name: 'Counter reading' }).fill('7,2')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry.getByText('That is not a counter reading.')).toBeVisible()

	await entry.getByRole('textbox', { name: 'Counter reading' }).fill(recorded)
	await entry.getByRole('button', { name: 'Try again' }).click()
	await expect(entry).toBeHidden()
	await expect(page.getByText(counter)).toBeVisible()

	// A reload is what proves the server kept both: the overview is read back from it, and it is
	// the only screen that shows a vehicle's name and its counter in one line.
	await page.goto(appPage)
	await expect(row(page, plate)).toContainText('Toyota Hilux')
	await expect(row(page, plate)).toContainText(counter)

	// No screen shows the drivetrain, so the vehicle itself is the only proof that the word the
	// sheet offered was sent back as the code the column holds.
	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })
	expect(fleet.find((/** @type {{plate: string}} */ vehicle) => vehicle.plate === plate)?.engine)
		.toBe('diesel')
})

test('a vehicle is edited from its own screen, and disposing of it takes it off the fleet', async ({ page }) => {
	const plate = `${plates}edit`
	const vehicle = await add(page, plate, Number(starting))
	await page.goto(appPage)
	await open(page, plate)

	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await sheet.getByRole('textbox', { name: 'VIN' }).fill('W0L000051T2123456')
	await sheet.getByLabel('First registration').fill('2019-03-07')

	// The country belongs to the vehicle rather than to whoever is editing it, and this is where it
	// is changed until the sidebar exists (docs/ui.md).
	await sheet.getByRole('combobox', { name: 'Jurisdiction' }).click()
	await option(page, 'Generic').click()

	// A disposal day is a fact about a disposed vehicle and about no other, so the field is not
	// there until the lifecycle says it is.
	await expect(sheet.getByLabel('Disposed on')).toHaveCount(0)
	await sheet.getByRole('combobox', { name: 'Lifecycle' }).click()
	await option(page, 'Disposed of').click()
	await sheet.getByLabel('Disposed on').fill('2026-08-31')

	await sheet.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(sheet).toBeHidden()

	// A disposed vehicle leaves the fleet, and its own screen has to go with it: the shell falls
	// back to the overview rather than leaving a screen with nothing to leave it by (src/App.vue).
	await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect(row(page, plate)).toHaveCount(0)

	// Four of the twenty writable columns, through the screen and back out of the database. The
	// whole payload is tests/Integration/VehicleTest.php's; this is the path to it.
	const saved = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })
	expect(saved).toMatchObject({
		vin: 'W0L000051T2123456',
		first_reg: '2019-03-07',
		jurisdiction: 'generic',
		lifecycle: 'disposed',
		disposed_at: '2026-08-31',
	})
})

test('a stale edit is refused, and the second attempt saves what was typed', async ({ page }) => {
	const plate = `${plates}conflict`
	const vehicle = await add(page, plate, Number(starting))
	await page.goto(appPage)
	await open(page, plate)

	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await sheet.getByRole('textbox', { name: 'Manufacturer' }).fill('Toyota')
	await sheet.getByRole('textbox', { name: 'Colour' }).fill('Blue')

	// Somebody else writes the row the sheet is holding, which moves the token it is checked
	// against (docs/architecture.md#concurrency).
	await bump(page, vehicle.uuid, 'Red')

	await sheet.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(sheet.getByText('This vehicle was changed somewhere else while you had it open.'))
		.toBeVisible()
	// Nothing the user typed is discarded, and the button says what the next click does.
	await expect(sheet.getByRole('textbox', { name: 'Manufacturer' })).toHaveValue('Toyota')
	await expect(sheet.getByRole('button', { name: 'Save anyway' })).toBeVisible()

	// One click reads the vehicle back and writes the typed values onto the fresh token.
	await sheet.getByRole('button', { name: 'Save anyway' }).click()
	await expect(sheet).toBeHidden()

	const saved = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })
	expect(saved).toMatchObject({ manufacturer: 'Toyota', color: 'Blue' })
})

test('a deleted vehicle comes back from the undo toast', async ({ page }) => {
	const plate = `${plates}undo`
	const vehicle = await add(page, plate, Number(starting))
	await page.goto(appPage)
	await open(page, plate)

	// Nothing asks "are you sure?": the way back is the toast (docs/ui.md).
	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	await page.getByRole('dialog', { name: 'Edit vehicle' })
		.getByRole('button', { name: 'Delete vehicle' }).click()

	// The delete takes the sheet and the screen under it away with the vehicle, so the offer has to
	// outlive both - it hangs beside the screens rather than under one (src/App.vue).
	const toast = page.locator('.toast')
	await expect(toast).toContainText(`${plate} was deleted.`)
	await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect(row(page, plate)).toHaveCount(0)

	// The undo is checked against the token the delete answered with, and no other one is accepted.
	await toast.getByRole('button', { name: 'Undo' }).click()
	// What comes back is still the selected vehicle, so its own screen returns with it.
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()
	await expect(toast).toBeHidden()

	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })
	expect(fleet.some((/** @type {{uuid: string}} */ one) => one.uuid === vehicle.uuid)).toBe(true)
})

test('the country a new vehicle is kept under is a personal setting', async ({ page }) => {
	// Created while the setting still says Germany, and it keeps that whatever the setting says
	// afterwards.
	const before = await api(page, {
		method: 'POST',
		path: '/api/vehicles',
		body: { plate: `${plates}before` },
	})
	expect(before).toMatchObject({ jurisdiction: 'de', currency: 'EUR' })

	await page.goto(settingsPage)
	const section = page.locator('#nextfleet-settings')
	await section.getByRole('combobox', { name: 'Jurisdiction' }).click()
	await option(page, 'Generic').click()

	// A personal setting saves as it is changed, so the reload is what says it was stored rather
	// than only shown.
	await page.reload()
	await expect(section).toContainText('Generic')

	// What the screen saved is what VehicleService reads, and the country's own profile is what
	// answers for the units and the currency - the generic one states none (docs/contributing.md).
	const after = await api(page, {
		method: 'POST',
		path: '/api/vehicles',
		body: { plate: `${plates}after` },
	})
	expect(after).toMatchObject({ jurisdiction: 'generic', currency: null })

	const kept = await api(page, { method: 'GET', path: `/api/vehicles/${before.uuid}` })
	expect(kept).toMatchObject({ jurisdiction: 'de', currency: 'EUR' })
})

test('an unfinished vehicle is asked about until the hint is answered', async ({ page, browser }) => {
	const asked = `${plates}hint-one`
	const other = `${plates}hint-two`
	await add(page, asked, Number(starting))
	await add(page, other, Number(starting))
	await page.goto(appPage)

	// Creating a vehicle asks for four fields, so the ones the sheet never asked for are what the
	// overview asks about, by the labels the edit sheet answers them under (docs/ui.md).
	await expect(hint(page, asked)).toContainText('Still missing: VIN, First registration')

	// Every row says "Dismiss", so the one that answers this vehicle is named by its own label.
	await page.getByRole('button', { name: `Dismiss the hint for ${asked}` }).click()
	await expect(hint(page, asked)).toHaveCount(0)

	// The other vehicle is still asked about, which is what makes the reload and the second browser
	// below say "this one was answered" rather than "nothing has rendered yet".
	await page.goto(appPage)
	await expect(hint(page, other)).toBeVisible()
	await expect(hint(page, asked)).toHaveCount(0)

	// A dismissal is kept for the user and not for the browser (docs/ui.md), so a session that has
	// never seen this fleet is the proof it did not go into this page's own storage.
	const elsewhere = await browser.newContext({ baseURL: new URL(page.url()).origin })
	const second = await elsewhere.newPage()
	await login(second)
	await second.goto(appPage)
	await expect(hint(second, other)).toBeVisible()
	await expect(hint(second, asked)).toHaveCount(0)
	await elsewhere.close()
})

test('the screens pass an axe audit', async ({ page }) => {
	const plate = `${plates}axe`
	await add(page, plate, Number(starting))
	await page.goto(appPage)

	await audit(page, 'the overview')

	await page.locator('.app-navigation').getByRole('link', { name: plate }).click()
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()
	await audit(page, 'the vehicle screen')

	// A dialog is where keyboard and screen-reader use breaks first: it has to name itself, take
	// the focus and give it back. NcDialog teleports itself to <body>, so auditing the app's own
	// root would scan the screen behind it and report the sheet as clean without looking at it.
	await page.getByRole('button', { name: 'New entry' }).click()
	await opened(page.getByRole('dialog', { name: 'Odometer' }))
	await audit(page, 'the entry sheet', ['#nextfleet', '[role="dialog"]'])
})

// One major, because the answer being audited is CSS and there is one copy of it: a second run of
// this would double the slowest suite in the repo to re-measure the same stylesheet. The projects
// that are not nc34 filter the tag out (playwright.config.js).
test.describe('at 320 x 640, in the dark', { tag: '@nc34' }, () => {
	test.use({ viewport: { width: 320, height: 640 }, colorScheme: 'dark' })

	test('every screen the app owns passes an axe audit', async ({ page }) => {
		const plate = `${plates}small`
		await add(page, plate, Number(starting))
		await page.goto(appPage)

		// The vehicle was made with the four fields the create sheet asks for, so the hint is on the
		// overview and is audited with it rather than needing a screen of its own.
		await expect(hint(page, plate)).toBeVisible()
		await audit(page, 'the overview')

		await open(page, plate)
		await audit(page, 'the vehicle screen')

		const entry = page.getByRole('dialog', { name: 'Odometer' })
		await page.getByRole('button', { name: 'New entry' }).click()
		await opened(entry)
		await audit(page, 'the entry sheet', ['#nextfleet', '[role="dialog"]'])
		await entry.getByRole('button', { name: 'Cancel' }).click()

		// The wide one: twenty fields in one column at this width, and the only sheet with a
		// destructive action in its row.
		const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
		await page.getByRole('button', { name: 'Edit vehicle' }).click()
		await opened(sheet)
		await audit(page, 'the vehicle sheet', ['#nextfleet', '[role="dialog"]'])
		await sheet.getByRole('button', { name: 'Cancel' }).click()

		// The app's own block on somebody else's page, so the audit is scoped to the block.
		await page.goto(settingsPage)
		const section = page.locator('#nextfleet-settings')
		await expect(section).toContainText('Germany')
		await audit(page, 'the personal settings', ['#nextfleet-settings'])
	})
})

/**
 * Audits what the app itself renders. Nextcloud's own header and settings menu are outside this
 * app's reach, so including them would fail the run on somebody else's markup.
 *
 * @param {import('@playwright/test').Page} page - the page as it stands
 * @param {string} screen - what it is showing, so a failure names it
 * @param {string[]} [within] - the subtrees the app owns on that screen
 */
async function audit(page, screen, within = ['#nextfleet']) {
	const builder = new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
	for (const selector of within) {
		builder.include(selector)
	}

	const audited = await builder.analyze()

	// The offending element is in the message, because the rule alone rarely says which one it is.
	expect(audited.violations.map((violation) =>
		`${screen}: ${violation.id} — ${violation.help} — ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`))
		.toEqual([])
}

/**
 * Waits for a sheet to be all the way open. A dialog fades in, and it counts as visible from the
 * first frame of that: an audit taken there measures the text against a background it is still
 * blended with and reports a contrast violation that was over in 200 ms.
 *
 * @param {import('@playwright/test').Locator} sheet - the dialog that was just asked for
 */
async function opened(sheet) {
	await expect(sheet).toBeVisible()
	await expect(sheet).toHaveCSS('opacity', '1')
}

/**
 * A vehicle's row on the overview. The navigation lists the same vehicles and Nextcloud's chrome
 * has landmarks of its own, so the row is the one in the app's own content area - and in the
 * fleet's own list, because the hint above it lists vehicles as well.
 *
 * @param {import('@playwright/test').Page} page - a page showing the overview
 * @param {string} plate - the label to find it by
 * @return {import('@playwright/test').Locator} the row
 */
function row(page, plate) {
	return page.locator('#nextfleet').getByRole('main').locator('.overview__list')
		.getByRole('listitem').filter({ hasText: plate })
}

/**
 * What the "complete this vehicle" hint asks about one vehicle.
 *
 * @param {import('@playwright/test').Page} page - a page showing the overview
 * @param {string} plate - the label to find it by
 * @return {import('@playwright/test').Locator} the hint's row for that vehicle
 */
function hint(page, plate) {
	return page.locator('.hint__item').filter({ hasText: plate })
}

/**
 * One entry of an open dropdown, found by what it reads rather than by its accessible name: NcSelect
 * splits a label across two elements to highlight what was typed, and the name is then computed with
 * a space in the seam - `Disposed of` is announced as "Dispos ed of".
 *
 * @param {import('@playwright/test').Page} page - a page with a dropdown open
 * @param {string} label - the word on screen (docs/ui.md#languages)
 * @return {import('@playwright/test').Locator} the option
 */
function option(page, label) {
	return page.getByRole('option').filter({ hasText: label })
}

/**
 * Opens a vehicle from the overview rather than from the navigation, which is behind a toggle at
 * 320 px - the row is the way in at every width.
 *
 * @param {import('@playwright/test').Page} page - a page showing the overview
 * @param {string} plate - the label to find it by
 */
async function open(page, plate) {
	await row(page, plate).locator('.list-item__anchor').click()
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()
}

/**
 * A vehicle with a counter, written the short way. The screens are what this file tests; getting
 * one on screen to test them is not.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} plate - the label to find it by
 * @param {number} value - its counter, as a first Reading
 * @return {Promise<any>} the vehicle as the server made it
 */
async function add(page, plate, value) {
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 },
	})

	return vehicle
}

/**
 * The other writer. It reads the vehicle for itself and writes it back, which moves the token every
 * open sheet is still holding the old one of (docs/architecture.md#concurrency).
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} uuid - the vehicle to write
 * @param {string} color - what to write, chosen because no test types into this field twice
 */
async function bump(page, uuid, color) {
	const vehicle = await api(page, { method: 'GET', path: `/api/vehicles/${uuid}` })
	await api(page, {
		method: 'PUT',
		path: `/api/vehicles/${uuid}`,
		body: { updated_at: vehicle.updated_at, color },
	})
}

/**
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} prefix - the plate prefix this file owns
 */
async function removeVehicles(page, prefix) {
	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })
	for (const vehicle of fleet) {
		if (String(vehicle.plate ?? '').startsWith(prefix)) {
			await api(page, {
				method: 'DELETE',
				// A DELETE carries no body, so the token it is checked against travels in the
				// query string (docs/architecture.md#concurrency).
				path: `/api/vehicles/${vehicle.uuid}?updated_at=${vehicle.updated_at}`,
			})
		}
	}
}
