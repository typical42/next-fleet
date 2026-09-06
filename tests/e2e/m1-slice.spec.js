/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { AxeBuilder } from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

import { appPage, login } from './app.js'

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
	await page.getByRole('option', { name: 'Diesel' }).click()
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
	// The navigation lists vehicles too, so the row is the one in the app's own content area -
	// scoped to the Vue root, because Nextcloud's chrome has landmarks of its own.
	const row = page.locator('#nextfleet').getByRole('main').getByRole('listitem').filter({ hasText: plate })
	await expect(row).toContainText('Toyota Hilux')
	await expect(row).toContainText(counter)

	// No screen shows the drivetrain, so the vehicle itself is the only proof that the word the
	// sheet offered was sent back as the code the column holds.
	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })
	expect(fleet.find((/** @type {{plate: string}} */ vehicle) => vehicle.plate === plate)?.engine)
		.toBe('diesel')
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
	await expect(page.getByRole('dialog', { name: 'Odometer' })).toBeVisible()
	await audit(page, 'the entry sheet', ['#nextfleet', '[role="dialog"]'])
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
 * A vehicle with a counter, written the short way. The screens are what this file tests; getting
 * one on screen to test them is not.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} plate - the label to find it by
 * @param {number} value - its counter, as a first Reading
 */
async function add(page, plate, value) {
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 },
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

/**
 * The app's API from inside the signed-in page, which is the only place that holds both the
 * session cookie and the CSRF token.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {object} call - what to ask for
 * @param {string} call.method - the HTTP verb
 * @param {string} call.path - below the app's own route prefix
 * @param {object} [call.body] - sent as JSON
 * @return {Promise<any>} the parsed answer
 */
function api(page, call) {
	return page.evaluate(async ({ method, path, body }) => {
		const response = await fetch(`/index.php/apps/nextfleet${path}`, {
			method,
			headers: {
				'Content-Type': 'application/json',
				requesttoken: document.head.dataset.requesttoken ?? '',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			throw new Error(`${method} ${path} answered ${response.status}`)
		}

		return response.json()
	}, call)
}
