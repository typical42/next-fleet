/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'
import { readFile } from 'node:fs/promises'

import { runAccount } from './accounts.js'
import { api, appPage, audit, choice, dav, fileId, fits, importing, inboxAt, login, ocs, open, opened, photo, pick, removeVehicles, row, settingsPage } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M5-E2E-'
// The accounts each run invents, and the folder in the run account's Files the papers sit in.
const people = 'm5e2e-'
const folder = 'M5-E2E'
const me = runAccount().uid

/**
 * Puts a file in the run account's Files and answers its id, which is what the file picker hands over.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} name - the file's name in the folder
 * @param {string} content - what it holds
 * @return {Promise<number>} its `file_id`
 */
async function upload(page, name, content) {
	const path = `${folder}/${encodeURIComponent(name)}`
	expect((await dav(page, me, 'PUT', path, { body: content })).status).toBe(201)
	return fileId(page, me, path)
}

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
	const { groups } = await ocs(page, 'GET', `/cloud/groups?search=${people}`)
	for (const gid of groups) {
		await ocs(page, 'DELETE', `/cloud/groups/${gid}`)
	}
	await dav(page, me, 'DELETE', folder)
	expect((await dav(page, me, 'MKCOL', folder)).status).toBe(201)
})

// The one place in M5 where a bug leaks a file across an access boundary (PRD M5): the paper is in
// the run account's Files and shared with nobody, so only the vehicle's grant can be serving it.
test('a paper downloads for a driver of the vehicle, for nobody else, and not once it is deleted', async ({ page, playwright }, testInfo) => {
	// Two accounts and a grant: 33 s on an idle stack, past 90 s beside the
	// rest of the suite.
	test.slow()
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: `${plates}doc-${Date.now()}`, jurisdiction: 'de' } })
	// An SVG, because it is the receipt that would run script if it were ever shown inline.
	const receipt = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>'
	const id = await upload(page, 'Beleg Werkstatt.svg', receipt)
	const [paper] = await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/documents`, body: { file_id: id, kind: 'receipt' } })

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
			await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: uid, grantee_type: 'user', role } })
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
	expect((await dav(page, me, 'DELETE', `${folder}/${encodeURIComponent('Beleg Werkstatt.svg')}`)).status).toBe(204)
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
	await pick(page.getByRole('dialog', { name: 'Choose a document' }), 'Rechnung Ölwechsel', folder)

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

	// Removed and taken back from the toast, as every other delete is.
	await section.getByRole('button', { name: 'Remove Rechnung Ölwechsel.txt' }).click()
	await expect(section.getByRole('link', { name: 'Rechnung Ölwechsel.txt' })).toBeHidden()
	await expect(page.locator('.toast')).toContainText('The document was removed.')
	await page.locator('.toast').getByRole('button', { name: 'Undo' }).click()
	await expect(page.locator('.toast')).toBeHidden()
	await expect(section.getByRole('link', { name: 'Rechnung Ölwechsel.txt' })).toBeVisible()

	// The file deleted behind the screen's back: the tap says so in place and the app stays.
	expect((await dav(page, me, 'DELETE', `${folder}/${encodeURIComponent('Rechnung Ölwechsel.txt')}`)).status).toBe(204)
	await section.getByRole('link', { name: 'Rechnung Ölwechsel.txt' }).click()
	await expect(section).toContainText('This document is gone')
	await clip.click()
	await expect(page.locator('.row__papers')).toContainText('This document is gone')
	expect(page.url()).toContain('/apps/nextfleet')
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
	expect(head).toBe('﻿uuid,started,started_offset_min,ended,ended_offset_min,start_odo,end_odo,distance,odo_unit,from,to,purpose,partner,category,reconciled,voided,created_at,entered_by')
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
	await expect(tab.getByRole('heading', { name: `Fahrtkosten für geschäftliche Fahrten ${year}` })).toBeVisible()
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

/** What the app owns while a sheet is open. */
const withSheet = ['#nextfleet', '[role="dialog"]']

/**
 * @param {import('@playwright/test').Page} page - the page as it stands
 * @param {string} screen - what is on screen, so a failure names it
 * @param {string[]} [within] - what the app owns there
 * @param {string[]} [scrolls] - what scrolls sideways on purpose
 */
async function check(page, screen, within, scrolls) {
	await fits(page, screen, scrolls)
	await audit(page, screen, within)
}

/**
 * @param {import('@playwright/test').Page} page - a page showing the vehicle screen
 * @param {string} button - what opens it
 * @param {string} name - the sheet's title
 * @return {Promise<import('@playwright/test').Locator>} the sheet, all the way open
 */
async function sheet(page, button, name) {
	await page.getByRole('button', { name: button, exact: true }).click()
	const dialog = page.getByRole('dialog', { name })
	await opened(dialog)
	return dialog
}

/**
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} uuid - the vehicle's
 */
async function soonDue(page, uuid) {
	// Due within the warning window, so the banner and the overview's traffic light show.
	const soon = new Date(Date.now() + 10 * 86400_000).toISOString().slice(0, 10)
	await api(page, { method: 'POST', path: `/api/vehicles/${uuid}/reminders`, body: { template_key: 'oil_change', due_date: soon, due_odo: 11000 } })
}

// Every screen and sheet in both themes, at the narrowest width and at the store screenshots'
// (tools/screenshots.mjs): the accessibility sweep (PRD M5, M6). One major, for the reason
// tests/e2e/m1-slice.spec.js gives.
for (const viewport of [{ width: 320, height: 640 }, { width: 1280, height: 800 }]) {
	// Below Nextcloud's 1024 px breakpoint the navigation folds behind a toggle.
	const folded = viewport.width < 1024
	for (const colorScheme of /** @type {const} */ (['light', 'dark'])) {
		const at = `${viewport.width}-${colorScheme}`
		test.describe(`at ${viewport.width} x ${viewport.height}, ${colorScheme}`, { tag: '@nc34' }, () => {
			test.use({ viewport, colorScheme })

			test('every screen fits the width and passes an axe audit', async ({ page }) => {
				test.slow()
				const plate = `${plates}${at}`
				const { vehicle } = await march(page, plate)
				await soonDue(page, vehicle.uuid)

				await page.goto(appPage)
				await expect(row(page, plate)).toBeVisible()
				await check(page, 'the overview')

				await open(page, plate)
				await expect(page.locator('.due__row')).toHaveCount(1)
				await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(3)
				await check(page, 'the vehicle screen')

				const entry = await sheet(page, 'New entry', 'New entry')
				for (const kind of ['Trip', 'Energy', 'Maintenance', 'Expense', 'Odometer']) {
					await choice(entry, kind).click()
					await check(page, `the entry sheet, ${kind}`, withSheet)
				}
				await entry.getByRole('button', { name: 'Cancel' }).click()

				await page.locator('.due__open').click()
				const reminder = page.getByRole('dialog', { name: 'Edit reminder' })
				await opened(reminder)
				await check(page, 'the reminder sheet', withSheet)
				await reminder.getByRole('button', { name: 'Cancel' }).click()

				// With the account picker in it (docs/ui.md).
				const edit = await sheet(page, 'Edit vehicle', 'Edit vehicle')
				await check(page, 'the vehicle sheet', withSheet)
				await edit.getByRole('button', { name: 'Cancel' }).click()

				await sheet(page, 'QR sticker', 'QR sticker')
				await check(page, 'the sticker', withSheet)
				await page.keyboard.press('Escape')

				await page.getByRole('button', { name: 'Costs', exact: true }).click()
				await page.getByRole('button', { name: 'Previous year' }).click()
				await expect(page.getByRole('region', { name: 'Costs by month' })).toBeVisible()
				await expect(page.locator('.costs__co2-sources').getByRole('link', { name: 'Fuel factors 2022' })).toBeVisible()
				await check(page, 'the Costs screen', undefined, ['.costs__table', '.costs__months li'])

				if (folded) {
					await page.getByRole('button', { name: 'Open navigation' }).click()
				}
				await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
				if (folded) {
					await page.getByRole('button', { name: 'Close navigation' }).click()
				}
				await expect(page.getByRole('link', { name: 'Open logbook' })).toBeVisible()
				await check(page, 'the reports screen')

				await page.goto(settingsPage)
				await expect(page.locator('#nextfleet-settings')).toContainText('Germany')
				await check(page, 'the personal settings', ['#nextfleet-settings'])
			})

			// M5's small gaps (PRD M10): a refused download said in place, on the section and on the
			// row, and the undo toast a removed paper gets.
			test('the papers\' refusals and their undo fit the width and pass an axe audit', async ({ page }) => {
				test.slow()
				const plate = `${plates}papers-${at}`
				const { vehicle } = await march(page, plate)
				const { rows } = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}/timeline?type=maintenance` })
				const attach = (/** @type {number} */ id, /** @type {object} */ link) => api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/documents`, body: { file_id: id, kind: 'receipt', ...link } })
				const bill = 'Rechnung Werkstatt März mit einem sehr langen Dateinamen.pdf'
				await attach(await upload(page, 'Fahrzeugschein.pdf', '%PDF'), {})
				await attach(await upload(page, bill, '%PDF'), { linked_type: 'maintenance', linked_uuid: rows[0].maintenance.uuid })

				await page.goto(appPage)
				await open(page, plate)
				const section = page.locator('.documents')
				await expect(section.getByRole('link', { name: bill })).toBeVisible()
				await expect(page.locator('.timeline__waiting')).toHaveCount(0)
				expect((await dav(page, me, 'DELETE', `${folder}/${encodeURIComponent(bill)}`)).status).toBe(204)

				await page.locator('.timeline').getByRole('link', { name: `Open ${bill}` }).click()
				await expect(page.locator('.row__papers')).toContainText('This document is gone')
				await section.getByRole('link', { name: bill }).click()
				await expect(section).toContainText('This document is gone')
				await check(page, 'a refused paper')

				await section.getByRole('button', { name: 'Remove Fahrzeugschein.pdf' }).click()
				await expect(page.locator('.toast')).toContainText('The document was removed.')
				await check(page, 'the undo toast for a paper')
			})

			// What a grant changes (PRD M6). The owner's Access section holds a long name and a
			// group. The grantee reaches one vehicle in person as a viewer, which trims its screen
			// and offers Leave, and another only through that group as a driver, which says so.
			test('the screens a grant changes fit the width and pass an axe audit', async ({ page, browser }) => {
				// A new account's first sign-in alone took 30 s (tests/e2e/m6-slice.spec.js).
				test.slow()
				const plate = `${plates}viewed-${at}`
				const { vehicle } = await march(page, plate)
				await soonDue(page, vehicle.uuid)
				const crewPlate = `${plates}crew-${at}`
				const crewCar = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: crewPlate, jurisdiction: 'de' } })
				// Fresh each run: an account a failed run left behind would refuse the create.
				const uid = `${people}${at}-${Date.now()}`
				const crew = `${uid}-crew`
				const password = `${uid}-Secret-2026!`
				await ocs(page, 'POST', '/cloud/users', { userid: uid, password, displayName: 'Maximiliane Alexandra von Hohenzollern-Sigmaringen' })
				await ocs(page, 'POST', '/cloud/groups', { groupid: crew, displayname: 'Werkstatt und Fuhrpark Stuttgart-Feuerbach' })
				await ocs(page, 'POST', `/cloud/users/${uid}/groups`, { groupid: crew })
				/**
				 * @param {string} uuid - the vehicle's
				 * @param {string} grantee - the account or group
				 * @param {string} type - which of the two
				 * @param {string} role - what it may do
				 */
				const give = (uuid, grantee, type, role) => api(page, { method: 'POST', path: `/api/vehicles/${uuid}/grants`, body: { grantee, grantee_type: type, role } })
				// The group's viewer row adds nothing to the account's own: the strongest row wins.
				await give(vehicle.uuid, uid, 'user', 'viewer')
				await give(vehicle.uuid, crew, 'group', 'viewer')
				await give(crewCar.uuid, crew, 'group', 'driver')

				await page.goto(appPage)
				await open(page, plate)
				await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(3)
				await expect(page.locator('.timeline')).toContainText('Entered by')
				await check(page, 'the vehicle screen, saying who entered each row')
				const edit = await sheet(page, 'Edit vehicle', 'Edit vehicle')
				await expect(edit.locator('.grants__row')).toHaveCount(2)
				await check(page, 'the Access section', withSheet)
				await edit.getByRole('button', { name: 'Cancel' }).click()

				const context = await browser.newContext({ viewport, colorScheme })
				try {
					const grantee = await context.newPage()
					await login(grantee, uid, password)
					await grantee.goto(appPage)
					await expect(row(grantee, plate)).toContainText('Owned by')
					await check(grantee, 'the overview, naming the owner')

					await open(grantee, plate)
					await expect(grantee.locator('.due__row')).toHaveCount(1)
					await expect(grantee.locator('.timeline').getByRole('listitem')).toHaveCount(3)
					await expect(grantee.getByRole('button', { name: 'New entry' })).toHaveCount(0)
					await grantee.getByRole('button', { name: 'Leave vehicle' }).click()
					await expect(grantee.getByRole('button', { name: 'Stay' })).toBeVisible()
					await check(grantee, 'a viewer\'s vehicle screen, asked before leaving')
					await grantee.getByRole('button', { name: 'Stay' }).click()
					await check(grantee, 'a viewer\'s vehicle screen')

					await grantee.goto(appPage)
					await open(grantee, crewPlate)
					await expect(grantee.locator('.leave__group')).toContainText('Werkstatt und Fuhrpark')
					await expect(grantee.locator('.timeline__waiting')).toHaveCount(0)
					await check(grantee, 'a driver\'s vehicle screen, reached through a group')
				} finally {
					await context.close()
				}
			})

			// What the pool and the inbox add (PRD M7). A driver with a long name has the car while
			// its owner has booked it for tomorrow with a long purpose, and a photo of the handover
			// hangs on the driver's booking. The owner walks every sheet a booking opens and gives
			// the car back into the trip it prefills; the driver files from an inbox of two.
			test('the pool and the inbox fit the width and pass an axe audit', async ({ page, browser }) => {
				// A new account's first sign-in alone took 30 s (tests/e2e/m6-slice.spec.js).
				test.slow()
				const plate = `${plates}pool-${at}`
				const { vehicle } = await march(page, plate)
				const uid = `${people}pool-${at}-${Date.now()}`
				const password = `${uid}-Secret-2026!`
				await ocs(page, 'POST', '/cloud/users', { userid: uid, password, displayName: 'Maximiliane Alexandra von Hohenzollern-Sigmaringen' })
				await api(page, { method: 'POST', path: `/api/vehicles/${vehicle.uuid}/grants`, body: { grantee: uid, grantee_type: 'user', role: 'driver' } })
				const bookings = `/api/vehicles/${vehicle.uuid}/bookings`
				const hour = 3600
				const now = Math.floor(Date.now() / 1000)
				const tomorrow = (Math.floor(now / hour) + 24) * hour
				await api(page, {
					method: 'POST',
					path: bookings,
					body: { starts_at: tomorrow, starts_at_off: 0, ends_at: tomorrow + 3 * hour, ends_at_off: 0, purpose: 'Werkstatttermin in Stuttgart-Feuerbach, danach Ersatzteile beim Großhändler abholen' },
				})

				const context = await browser.newContext({ viewport, colorScheme })
				try {
					const driver = await context.newPage()
					await login(driver, uid, password)
					await driver.goto(appPage)
					const held = await api(driver, { method: 'POST', path: bookings, body: { starts_at: now, starts_at_off: 0, ends_at: now + 3 * hour, ends_at_off: 0 } })
					await api(driver, { method: 'POST', path: `${bookings}/${held.uuid}/check-out`, body: { odo: 10800, level: 80, at_off: 0 } })
					const snap = `${folder}/${encodeURIComponent('Übergabe Kratzer hinten links.png')}`
					expect((await dav(page, me, 'PUT', snap, { body: photo })).status).toBe(201)
					await api(page, {
						method: 'POST',
						path: `/api/vehicles/${vehicle.uuid}/documents`,
						body: { file_id: await fileId(page, me, snap), kind: 'photo', linked_type: 'booking', linked_uuid: held.uuid },
					})

					await page.goto(appPage)
					await expect(row(page, plate).locator('.overview__holder')).toContainText('With Maximiliane')
					await check(page, 'the overview, saying who has the car')

					await open(page, plate)
					await expect(page.locator('.bookings__booking')).toHaveCount(2)
					await expect(page.locator('.bookings__papers a')).toHaveCount(1)
					await expect(page.locator('.vehicle__holder')).toBeVisible()
					await expect(page.locator('.vehicle__next')).toBeVisible()
					await expect(page.locator('.timeline__waiting')).toHaveCount(0)
					await check(page, 'the vehicle screen with its bookings')

					// The sheet's default span runs into the driver's, out with them, so the server names them.
					const book = await sheet(page, 'Book', 'Book vehicle')
					await book.getByRole('button', { name: 'Book', exact: true }).click()
					await expect(book).toContainText('With Maximiliane')
					await check(page, 'the booking sheet, refused', withSheet)
					await book.getByRole('button', { name: 'Cancel' }).click()

					const own = page.locator('.bookings__booking').filter({ hasText: 'Werkstatttermin' })
					await own.getByRole('button', { name: 'Take the car' }).click()
					const take = page.getByRole('dialog', { name: 'Take the car' })
					await opened(take)
					await check(page, 'the check-out sheet', withSheet)
					await take.getByRole('button', { name: 'Cancel' }).click()

					const out = page.locator('.bookings__booking').filter({ hasText: 'Maximiliane' })
					await out.getByRole('button', { name: 'Return the car' }).click()
					const giving = page.getByRole('dialog', { name: 'Return the car' })
					await opened(giving)
					await check(page, 'the check-in sheet', withSheet)
					await giving.getByRole('textbox', { name: 'Counter reading (km)' }).fill('10842')
					await giving.getByRole('button', { name: 'Return the car' }).click()
					const trip = page.getByRole('dialog', { name: 'New entry' })
					await opened(trip)
					await expect(trip.getByRole('textbox', { name: 'Odometer at arrival' })).toHaveValue(/^10[,.]?842$/)
					await check(page, 'the entry sheet, a trip from a booking', withSheet)
					await trip.getByRole('button', { name: 'Cancel' }).click()

					await expect(out.getByRole('button', { name: 'Log the trip' })).toBeVisible()
					await check(page, 'the vehicle screen, the car given back')
					const instant = await sheet(page, 'Take it now', 'Take the car')
					await expect(instant.getByLabel('Back by')).toBeVisible()
					await check(page, 'the check-out sheet, taking it now', withSheet)
					await instant.getByRole('button', { name: 'Cancel' }).click()

					await inboxAt(driver, uid, 'Belege')
					const waiting = { 'Tankbeleg Aral Stuttgart-Feuerbach Heilbronner Straße.png': photo, 'Rechnung.pdf': '%PDF-1.4\n%%EOF\n' }
					for (const [name, body] of Object.entries(waiting)) {
						expect((await dav(driver, uid, 'PUT', `Belege/${encodeURIComponent(name)}`, { body })).status).toBe(201)
					}
					await driver.reload()
					if (folded) {
						await driver.getByRole('button', { name: 'Open navigation' }).click()
					}
					await driver.locator('.app-navigation').getByRole('link', { name: /Inbox/ }).click()
					if (folded) {
						await driver.getByRole('button', { name: 'Close navigation' }).click()
					}
					const tiles = driver.locator('.inbox__file img')
					await expect(tiles).toHaveCount(2)
					// Lazy thumbnails are blank until they load, and a blank tile measures nothing.
					await expect.poll(() => tiles.evaluateAll((images) => images.every((image) => image instanceof HTMLImageElement && image.complete && image.naturalWidth > 0))).toBe(true)
					await check(driver, 'the inbox')

					await driver.locator('.inbox__file').filter({ hasText: 'Tankbeleg' }).click()
					const attach = driver.getByRole('dialog', { name: 'Attach document' })
					await opened(attach)
					await expect(attach.getByRole('button', { name: 'New fill-up' })).toBeVisible()
					await check(driver, 'the inbox sheet', withSheet)
				} finally {
					await context.close()
				}
			})

			// What the import adds (PRD M9). One file whose dates read either way round, so the
			// preview asks; one with a long name and thirteen columns, which is imported.
			test('the import screens fit the width and pass an axe audit', async ({ page }) => {
				test.slow()
				const plate = `${plates}import-${at}`
				await march(page, plate)
				const ambiguousFile = 'Tankbuch.csv'
				const wideFile = 'Spritmonitor-Export Tankbuch Diesel 2026 Stuttgart-Feuerbach.csv'
				const fixture = (/** @type {string} */ name) => readFile(new URL(`../Fixture/import/${name}`, import.meta.url), 'utf8')
				const [columns] = (await fixture('lubelogger-fuel.csv')).split('\n')
				// Every day is 12 or less, so each date reads either way round and the order is asked.
				await upload(page, ambiguousFile, `${columns}\n3/4/2026,52000,40.00,60.00,,True,False,,,,,,\n4/5/2026,52600,36.00,54.00,,True,False,,,,,,\n`)
				await upload(page, wideFile, await fixture('spritmonitor-fuel.csv'))

				await page.goto(appPage)
				await open(page, plate)
				const asking = await importing(page, ambiguousFile, 'CSV (LubeLogger format) — fuel', folder)
				await check(page, 'the import sheet, the format step', withSheet)
				await asking.getByRole('button', { name: 'Preview' }).click()
				await expect(asking.getByRole('combobox', { name: 'Order of the dates' })).toBeVisible()
				await check(page, 'the import preview, asking the date order', withSheet)
				await asking.getByRole('button', { name: 'Back' }).click()
				await asking.getByRole('button', { name: 'Cancel' }).click()
				await expect(asking).toBeHidden()

				const wide = await importing(page, wideFile, 'CSV (Spritmonitor format) — fuel', folder)
				await expect(wide.getByRole('combobox', { name: 'Volume in the file' })).toBeVisible()
				await check(page, 'the import sheet, the format step with units', withSheet)
				await wide.getByRole('button', { name: 'Preview' }).click()
				await expect(wide).toContainText('New: 3. Already there: 0. Not readable: 2.')
				await check(page, 'the import preview, a long column list', withSheet)
				await wide.getByRole('button', { name: 'Import', exact: true }).click()
				await expect(wide).toBeHidden()
				await expect(page.locator('.toast')).toContainText('Entries imported: 3.')
				// The timeline reads the new rows behind a spinner that spills while it turns.
				await expect(page.locator('.timeline__waiting')).toHaveCount(0)
				await check(page, 'the import result')
			})
		})
	}
}
