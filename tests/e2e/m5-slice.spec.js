/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

import { api, appPage, audit, choice, fits, login, ocs, open, opened, removeVehicles, row, settingsPage } from './app.js'
import { grant } from './server.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M5-E2E-'
// The accounts each run invents, and the admin's folder the papers sit in.
const people = 'm5e2e-'
const folder = 'M5-E2E'

/**
 * WebDAV on the admin's Files from inside the signed-in page, as the Files app writes.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} method - the HTTP verb
 * @param {string} path - below the admin's Files
 * @param {object} [options] - what else to send
 * @param {string} [options.body] - the request body
 * @param {Record<string, string>} [options.headers] - beside the CSRF token
 * @return {Promise<{status: number, text: string}>} the answer, whole
 */
function dav(page, method, path, { body, headers = {} } = {}) {
	return page.evaluate(async ({ method, path, body, headers }) => {
		const response = await fetch(`/remote.php/dav/files/admin/${path}`, {
			method,
			headers: { ...headers, requesttoken: document.head.dataset.requesttoken ?? '' },
			body,
		})
		return { status: response.status, text: await response.text() }
	}, { method, path, body, headers })
}

/**
 * Puts a file in the admin's Files and answers its id, which is what the file picker hands over.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} name - the file's name in the folder
 * @param {string} content - what it holds
 * @return {Promise<number>} its `file_id`
 */
async function upload(page, name, content) {
	const path = `${folder}/${encodeURIComponent(name)}`
	expect((await dav(page, 'PUT', path, { body: content })).status).toBe(201)
	const found = await dav(page, 'PROPFIND', path, {
		headers: { Depth: '0', 'Content-Type': 'application/xml' },
		body: '<d:propfind xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:prop><oc:fileid/></d:prop></d:propfind>',
	})
	const id = /<oc:fileid>(\d+)<\/oc:fileid>/.exec(found.text)?.[1]
	expect(id, found.text).toBeDefined()
	return Number(id)
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
	await dav(page, 'DELETE', folder)
	expect((await dav(page, 'MKCOL', folder)).status).toBe(201)
})

// The one place in M5 where a bug leaks a file across an access boundary (PRD M5): the paper is in
// the admin's Files and shared with nobody, so only the vehicle's grant can be serving it.
test('a paper downloads for a driver of the vehicle, for nobody else, and not once it is deleted', async ({ page, playwright }, testInfo) => {
	// Two accounts and a grant through `docker exec`: 33 s on an idle stack, past 90 s beside the
	// rest of the suite.
	test.slow()
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: `${plates}doc-${Date.now()}`, jurisdiction: 'de' } })
	// An SVG, because it is the receipt that would run script if it were ever shown inline.
	const receipt = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'
	const fileId = await upload(page, 'Beleg Werkstatt.svg', receipt)
	const [paper] = await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/documents`, body: { file_id: fileId, kind: 'receipt' } })

	/**
	 * A signed-in browser of an account of its own.
	 *
	 * @param {string|null} role - what it may do on the vehicle, or null for nothing
	 * @return {Promise<import('@playwright/test').APIRequestContext>} its requests
	 */
	const account = async (role) => {
		const uid = `${people}${testInfo.project.name}-${role ?? 'stranger'}-${Date.now()}`
		const password = `${uid}-Secret-2026!`
		await ocs(page, 'POST', '/cloud/users', { userid: uid, password })
		if (role !== null) {
			await grant(testInfo.project.name, vehicle.uuid, uid, role)
		}
		return playwright.request.newContext({
			baseURL: testInfo.project.use.baseURL,
			httpCredentials: { username: uid, password, send: 'always' },
		})
	}
	const driver = await account('driver')
	const stranger = await account(null)
	const link = `/index.php/apps/nextfleet/vehicles/${vehicle.uuid}/documents/${paper.uuid}`

	// Over HTTP the server mounts only the signed-in account's Files, so the owner's file has to be
	// found for the driver on purpose (DocumentService::file). The list is where that shows first.
	const listed = await driver.get(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/documents`, { headers: { 'OCS-APIRequest': 'true' } })
	expect(listed.status(), await listed.text()).toBe(200)
	expect((await listed.json()).map((/** @type {{name: string}} */ one) => one.name)).toEqual(['Beleg Werkstatt.svg'])

	const served = await driver.get(link)
	expect(served.status(), await served.text()).toBe(200)
	expect(await served.text()).toBe(receipt)
	expect(served.headers()['content-disposition']).toBe('attachment; filename="Beleg Werkstatt.svg"; filename*=UTF-8\'\'Beleg%20Werkstatt.svg')
	expect(served.headers()['x-content-type-options']).toBe('nosniff')

	expect((await stranger.get(link)).status()).toBe(403)

	// A `file_id` survives a move but not a delete, and the trash bin is not somewhere we serve from.
	expect((await dav(page, 'DELETE', `${folder}/${encodeURIComponent('Beleg Werkstatt.svg')}`)).status).toBe(204)
	expect((await driver.get(link)).status()).toBe(404)

	await driver.dispose()
	await stranger.dispose()
})

// The only place a paper is added is the vehicle screen, through Nextcloud's own picker. On NC 31
// opening that picker once remounted the whole app (src/main.js says why), so this runs on both.
test('a paper picked from Files is listed on the vehicle and opens from its entry', async ({ page }) => {
	const plate = `${plates}screen-${Date.now()}`
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de' } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/maintenance`,
		body: { done_at: Math.floor(Date.now() / 1000) - 86400, done_at_off: 0, title: 'Oil change', type: 'service', odo: 21100 },
	})
	await upload(page, 'Rechnung Ölwechsel.txt', 'invoice')

	await page.goto(appPage)
	await open(page, plate)
	await page.getByRole('button', { name: 'Add document' }).click()
	const picker = page.getByRole('dialog', { name: 'Choose a document' })
	// The picker opens where it was last left, which after an earlier run is the folder itself.
	const inFolder = picker.getByRole('row').filter({ hasText: 'Rechnung Ölwechsel' })
	await expect(picker.locator(`[data-filename="${folder}"]`).or(inFolder)).toBeVisible()
	if (await inFolder.count() === 0) {
		await picker.locator(`[data-filename="${folder}"]`).click()
	}
	await inFolder.click()
	await picker.getByRole('button', { name: 'Choose' }).click()

	const ask = page.getByRole('dialog', { name: 'Attach document' })
	await opened(ask)
	await audit(page, 'attach dialog', ['[role="dialog"]'])
	await ask.getByRole('combobox', { name: 'What it is' }).click()
	await page.getByRole('option', { name: 'Receipt' }).click()
	await ask.getByRole('combobox', { name: 'Belongs to' }).click()
	await page.getByRole('option').filter({ hasText: 'Oil change' }).click()
	await ask.getByRole('button', { name: 'Attach' }).click()
	await expect(ask).toBeHidden()

	const section = page.locator('.documents')
	await expect(section.getByRole('link', { name: 'Rechnung Ölwechsel.txt' })).toBeVisible()
	await expect(section).toContainText('Belongs to a maintenance record')
	await audit(page, 'documents section', ['.documents', '.timeline'])

	const clip = page.locator('.timeline').getByRole('link', { name: 'Open Rechnung Ölwechsel.txt' })
	const [download] = await Promise.all([page.waitForEvent('download'), clip.click()])
	expect(download.suggestedFilename()).toBe('Rechnung Ölwechsel.txt')
})

/**
 * A German car with one March of last year: a business trip, a fill-up and a workshop bill. Not the
 * demo fleet, whose months move with the seeding day (docs/development.md, seed data).
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} plate - the label to find it by
 * @return {Promise<{vehicle: any, year: number}>} the vehicle and the year its rows are in
 */
async function march(page, plate) {
	const year = new Date().getUTCFullYear() - 1
	const at = (/** @type {number} */ day, /** @type {number} */ hour) => Math.floor(Date.UTC(year, 2, day, hour) / 1000)
	const vehicle = await api(page, {
		method: 'POST',
		path: '/api/vehicles',
		body: { plate, jurisdiction: 'de', vehicle_type: 'car', engine: 'diesel', energy_types: ['diesel'], currency: 'EUR' },
	})
	const post = (/** @type {string} */ table, /** @type {object} */ body) => api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/${table}`, body })
	// 250 km at the statutory 0,30 € is 75,00 € (De\RateProvider).
	await post('trips', {
		started_at: at(5, 10),
		started_at_off: 60,
		ended_at: at(5, 13),
		ended_at_off: 60,
		start_odo: 10000,
		end_odo: 10250,
		category: 'business',
		from_label: 'Stuttgart, Büro',
		to_label: 'Freiburg',
		purpose: 'Kundentermin',
		partner: 'Müller GmbH',
	})
	await post('energy', { filled_at: at(10, 12), filled_at_off: 60, odo: 10500, energy: 'diesel', amount: 40000, total: 7000, full_tank: true })
	await post('maintenance', { done_at: at(20, 12), done_at_off: 60, odo: 10800, type: 'service', title: 'Oil change', cost: 12000 })

	return { vehicle, year }
}

// The month's figures come from the server's year (kpi#year); the screen only lays them out.
test('the Costs screen reads a month and exports its trips as CSV', async ({ page }) => {
	const plate = `${plates}costs-${Date.now()}`
	const { year } = await march(page, plate)

	await page.goto(appPage)
	await open(page, plate)
	await page.getByRole('button', { name: 'Costs', exact: true }).click()
	await page.getByRole('button', { name: 'Previous year' }).click()
	await expect(page.locator('.costs__year-number')).toHaveText(String(year))

	const table = page.getByRole('region', { name: 'Costs by month' })
	// March is the third column head. The cells are asserted rather than read, so they wait out the
	// current year's answer, which is on screen until last year's arrives.
	await expect(table.locator('thead th').nth(2)).toHaveText('Mar')
	const cell = (/** @type {string} */ row) => table.getByRole('row').filter({ has: page.getByRole('rowheader', { name: row, exact: true }) }).locator('td').nth(2)
	await expect(cell('Energy')).toHaveText('€70.00')
	await expect(cell('Maintenance')).toHaveText('€120.00')
	await expect(cell('Total')).toHaveText('€190.00')

	await page.getByRole('button', { name: 'Export' }).click()
	const [download] = await Promise.all([
		page.waitForEvent('download'),
		page.getByRole('menuitem', { name: 'Trips' }).click(),
	])
	expect(download.suggestedFilename()).toBe(`${plate}-${year}-trips.csv`)
	const csv = await readFile(await download.path(), 'utf8')
	// A spreadsheet's CSV: a BOM, commas, CRLF, and a value with a comma in it quoted (PRD M5).
	const [head, row, end] = csv.split('\r\n')
	expect(head).toBe('﻿uuid,started,started_offset_min,ended,ended_offset_min,start_odo,end_odo,distance,odo_unit,from,to,purpose,partner,category,reconciled,voided')
	expect(row).toContain(`,${year}-03-05 11:00,60,${year}-03-05 14:00,60,10000,10250,`)
	expect(row).toContain(',km,"Stuttgart, Büro",Freiburg,Kundentermin,Müller GmbH,business,')
	expect(end).toBe('')
})

/**
 * The claim is a page the browser prints, opened from Reports as the Fahrtenbuch is
 * (tests/e2e/m2-slice.spec.js), and German whoever reads it (docs/ui.md#languages).
 */
test('the mileage claim values the business trip and prints', async ({ page }) => {
	const plate = `${plates}claim-${Date.now()}`
	const { vehicle, year } = await march(page, plate)

	await page.goto(appPage)
	await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
	const screen = page.locator('#nextfleet').getByRole('main')
	await screen.getByRole('combobox', { name: 'Vehicle' }).click()
	await page.getByRole('option').filter({ hasText: plate }).click()
	await screen.getByRole('textbox', { name: 'Year' }).fill(String(year))
	const link = screen.getByRole('link', { name: 'Open mileage claim' })
	await expect(link).toHaveAttribute('href', new RegExp(`/apps/nextfleet/vehicles/${vehicle.uuid}/mileage/${year}$`))

	const [tab] = await Promise.all([page.waitForEvent('popup'), link.click()])
	await expect(tab.getByRole('heading', { name: `Fahrtkosten für Dienstfahrten ${year}` })).toBeVisible()
	const body = tab.locator('body')
	await expect(body).toContainText('Kundentermin')
	await expect(body).toContainText('75,00 €')
	// What the browser's print dialog would produce, as far as a headless one can say.
	expect((await tab.pdf()).subarray(0, 5).toString()).toBe('%PDF-')
})

// The sticker's address is the one step that has nothing to do with the data (docs/ui.md#the-qr-shortcut).
test('the sticker opens the entry sheet on its vehicle', async ({ page }) => {
	const plate = `${plates}qr-${Date.now()}`
	await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de' } })

	await page.goto(appPage)
	await open(page, plate)
	await page.getByRole('button', { name: 'QR sticker' }).click()
	const address = await page.locator('.nextfleet-sticker__hint a[href]').getAttribute('href')
	expect(address).toContain('entry=new')

	await page.goto(/** @type {string} */ (address))
	await expect(page.getByRole('dialog', { name: 'New entry' })).toBeVisible()
	await expect(page.getByRole('heading', { name: plate })).toBeVisible()
	// Read once: a reload shows the vehicle, not a second sheet.
	expect(new URL(page.url()).searchParams.has('entry')).toBe(false)
})

// Every screen and sheet at the narrowest width, in both themes: the accessibility sweep (PRD M5).
// One major, for the reason tests/e2e/m1-slice.spec.js gives.
for (const colorScheme of /** @type {const} */ (['light', 'dark'])) {
	test.describe(`at 320 x 640, ${colorScheme}`, { tag: '@nc34' }, () => {
		test.use({ viewport: { width: 320, height: 640 }, colorScheme })

		test('every screen fits the width and passes an axe audit', async ({ page }) => {
			test.slow()
			const plate = `${plates}320-${colorScheme}`
			const { vehicle } = await march(page, plate)
			// Due within the warning window, so the banner and the overview's traffic light show.
			const soon = new Date(Date.now() + 10 * 86400_000).toISOString().slice(0, 10)
			await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/reminders`, body: { template_key: 'oil_change', due_date: soon, due_odo: 11000 } })
			const withSheet = ['#nextfleet', '[role="dialog"]']
			/**
			 * @param {string} screen - what is on screen, so a failure names it
			 * @param {string[]} [within] - what the app owns there
			 * @param {string[]} [scrolls] - what scrolls sideways on purpose
			 */
			const check = async (screen, within, scrolls) => {
				await fits(page, screen, scrolls)
				await audit(page, screen, within)
			}
			/**
			 * @param {string} button - what opens it
			 * @param {string} name - the sheet's title
			 * @return {Promise<import('@playwright/test').Locator>} the sheet, all the way open
			 */
			const sheet = async (button, name) => {
				await page.getByRole('button', { name: button, exact: true }).click()
				const dialog = page.getByRole('dialog', { name })
				await opened(dialog)
				return dialog
			}

			await page.goto(appPage)
			await expect(row(page, plate)).toBeVisible()
			await check('the overview')

			await open(page, plate)
			await expect(page.locator('.due__row')).toHaveCount(1)
			await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(3)
			await check('the vehicle screen')

			const entry = await sheet('New entry', 'New entry')
			for (const kind of ['Trip', 'Energy', 'Maintenance', 'Expense', 'Odometer']) {
				await choice(entry, kind).click()
				await check(`the entry sheet, ${kind}`, withSheet)
			}
			await entry.getByRole('button', { name: 'Cancel' }).click()

			await page.locator('.due__open').click()
			const reminder = page.getByRole('dialog', { name: 'Edit reminder' })
			await opened(reminder)
			await check('the reminder sheet', withSheet)
			await reminder.getByRole('button', { name: 'Cancel' }).click()

			// With the account picker in it (docs/ui.md).
			const edit = await sheet('Edit vehicle', 'Edit vehicle')
			await check('the vehicle sheet', withSheet)
			await edit.getByRole('button', { name: 'Cancel' }).click()

			await sheet('QR sticker', 'QR sticker')
			await check('the sticker', withSheet)
			await page.keyboard.press('Escape')

			await page.getByRole('button', { name: 'Costs', exact: true }).click()
			await page.getByRole('button', { name: 'Previous year' }).click()
			await expect(page.getByRole('region', { name: 'Costs by month' })).toBeVisible()
			await check('the Costs screen', undefined, ['.costs__table', '.costs__months li'])

			await page.getByRole('button', { name: 'Open navigation' }).click()
			await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
			await page.getByRole('button', { name: 'Close navigation' }).click()
			await expect(page.getByRole('link', { name: 'Open logbook' })).toBeVisible()
			await check('the reports screen')

			await page.goto(settingsPage)
			await expect(page.locator('#nextfleet-settings')).toContainText('Germany')
			await check('the personal settings', ['#nextfleet-settings'])
		})
	})
}
