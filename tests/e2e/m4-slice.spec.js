/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { add, api, appPage, login, open, removeVehicles } from './app.js'
import { runJobAt } from './server.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M4-E2E-'
// The recipient each run invents: a German account with a mail address and no Vehicle Access.
const people = 'm4e2e-'
const mailpit = process.env.NEXTFLEET_MAILPIT ?? 'http://localhost:8025'

const DAY = 86400_000

/**
 * Nextcloud's OCS API from inside the signed-in page, as the admin.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} method - the HTTP verb
 * @param {string} path - below /ocs/v2.php
 * @param {object} [body] - sent as JSON
 * @return {Promise<any>} `ocs.data`
 */
function ocs(page, method, path, body) {
	return page.evaluate(async ({ method, path, body }) => {
		const response = await fetch(`/ocs/v2.php${path}`, {
			method,
			headers: {
				Accept: 'application/json',
				'Content-Type': 'application/json',
				'OCS-APIRequest': 'true',
				requesttoken: document.head.dataset.requesttoken ?? '',
			},
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			throw new Error(`${method} ${path} answered ${response.status}`)
		}

		return (await response.json()).ocs.data
	}, { method, path, body })
}

/**
 * @param {Date} day - any moment in it
 * @return {string} its UTC day, `YYYY-MM-DD`
 */
const iso = (day) => day.toISOString().slice(0, 10)

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	const { users } = await ocs(page, 'GET', `/cloud/users?search=${people}`)
	for (const uid of users) {
		await ocs(page, 'DELETE', `/cloud/users/${uid}`)
	}
})

test('a HU/AU from the sticker is told by notification and by mail, once', async ({ page, playwright }, testInfo) => {
	const plate = `${plates}hu-${Date.now()}`
	await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de' } })

	// A sticker two months ahead: nothing is due at the real time, so the moved clock below is the
	// only thing that warns. The cron container's hourly run at the real time finds it planned and
	// takes the notification back; one landing in the second between our run and our read would
	// fail this test.
	const today = new Date()
	const sticker = new Date(Date.UTC(today.getUTCFullYear(), today.getUTCMonth() + 2, 1))
	const due = new Date(Date.UTC(sticker.getUTCFullYear(), sticker.getUTCMonth() + 1, 0))
	const monthName = new Intl.DateTimeFormat('en', { month: 'long', timeZone: 'UTC' }).format(sticker)

	await page.goto(appPage)
	await open(page, plate)
	const banner = page.getByRole('region', { name: 'Reminders' })
	await expect(banner).toContainText('When is the next HU/AU?')
	await banner.getByRole('combobox', { name: 'Month' }).click()
	await page.getByRole('option', { name: monthName, exact: true }).click()
	await banner.getByRole('textbox', { name: 'Year' }).fill(String(sticker.getUTCFullYear()))
	await banner.getByRole('button', { name: 'Add HU/AU reminder' }).click()
	// Due is the month's last day (docs/ui.md, the due banner).
	await expect(banner.getByRole('listitem')).toHaveCount(1)
	await expect(banner.getByRole('listitem')).toContainText('Technical inspection (HU/AU)')
	await expect(banner.getByRole('listitem')).toContainText('Planned')
	await expect(banner).not.toContainText('When is the next HU/AU?')

	// Someone who is told but has no Vehicle Access, in German, so the words are the notifier's
	// and the digest's own translation rather than the English they were written in.
	const uid = `${people}${testInfo.project.name}-${Date.now()}`
	const password = `${uid}-Secret-2026!`
	const address = `${uid}@example.org`
	await ocs(page, 'POST', '/cloud/users', { userid: uid, password, email: address, language: 'de' })

	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	await sheet.getByRole('combobox', { name: 'Reminders go to' }).fill(uid)
	await page.getByRole('option').filter({ hasText: uid }).click()
	await expect(sheet.getByRole('combobox', { name: 'Reminders go to' }).locator('xpath=ancestor::*[contains(@class, "v-select")][1]'))
		.toContainText(uid)
	await sheet.getByRole('combobox', { name: 'Reminder mail' }).click()
	await page.getByRole('option', { name: 'Daily' }).click()
	await sheet.getByRole('button', { name: 'Save', exact: true }).click()
	await expect(sheet).toBeHidden()

	// 28 days before the due date: past the month-before point, before the month the HU/AU is
	// due in. Noon UTC is past 07:00 for a recipient on the server's zone, when a digest goes.
	const moved = new Date(due.getTime() - 28 * DAY + 12 * 3600_000)
	await runJobAt(testInfo.project.name, moved)
	// An hour later: nothing new, on either channel.
	await runJobAt(testInfo.project.name, new Date(moved.getTime() + 3600_000))

	const [year, month, day] = iso(due).split('-')
	const told = `${plate}: Hauptuntersuchung (HU/AU) ist am ${day}.${month}.${year} fällig`

	const phone = await playwright.request.newContext({
		baseURL: testInfo.project.use.baseURL,
		httpCredentials: { username: uid, password, send: 'always' },
		extraHTTPHeaders: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
	})
	const listed = await phone.get('/ocs/v2.php/apps/notifications/api/v2/notifications')
	expect(listed.ok()).toBe(true)
	const notifications = (await listed.json()).ocs.data
	expect(notifications.map((/** @type {{subject: string}} */ one) => one.subject)).toEqual([told])
	await phone.dispose()

	// One digest, although the job ran twice that day. The account's welcome mail is core's.
	const found = await (await fetch(`${mailpit}/api/v1/search?query=${encodeURIComponent(`to:${address}`)}`)).json()
	const digests = found.messages.filter((/** @type {{Subject: string}} */ one) => one.Subject === 'Erinnerungen für deine Fahrzeuge')
	expect(digests).toHaveLength(1)
	const mail = await (await fetch(`${mailpit}/api/v1/message/${digests[0].ID}`)).json()
	expect(mail.Text).toContain(plate)
	expect(mail.Text).toContain(`Hauptuntersuchung (HU/AU) ist am ${day}.${month}.${year} fällig`)
})

test('an oil change closed from the banner schedules the next one, and the overview reorders', async ({ page }) => {
	const stamp = Date.now()
	const oil = await add(page, `${plates}oil-${stamp}`, 10000)
	const inspected = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: `${plates}tuv-${stamp}`, jurisdiction: 'de' } })
	const now = Date.now()
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${oil.uuid}/reminders`,
		body: { template_key: 'oil_change', due_date: iso(new Date(now + 10 * DAY)), due_odo: 20000 },
	})
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${inspected.uuid}/reminders`,
		body: { template_key: 'hu_au', due_date: iso(new Date(now + 80 * DAY)) },
	})

	/** @return {Promise<string[]>} the overview's rows, top to bottom */
	const order = () => page.locator('#nextfleet').getByRole('main').locator('.overview__list')
		.getByRole('listitem').allInnerTexts()
	/**
	 * @return {Promise<boolean|null>} whether the oil change's vehicle stands above the
	 *   inspection's; null until both are listed, so a list still loading proves nothing
	 */
	const oilFirst = async () => {
		const rows = await order()
		const oiled = rows.findIndex((text) => text.includes(oil.plate))
		const due = rows.findIndex((text) => text.includes(inspected.plate))
		return oiled < 0 || due < 0 ? null : oiled < due
	}

	// Coming up beats planned, whatever the dates (docs/ui.md, the overview).
	await page.goto(appPage)
	await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect.poll(oilFirst).toBe(true)

	await open(page, oil.plate)
	const banner = page.getByRole('region', { name: 'Reminders' })
	await expect(banner.getByRole('listitem')).toContainText('Coming up')
	await banner.getByRole('button', { name: 'Done: Oil change' }).click()

	const entry = page.getByRole('dialog', { name: 'New entry' })
	// Opened on Maintenance with that reminder picked.
	await expect(entry.getByRole('button', { name: 'Oil change', pressed: true })).toBeVisible()
	await entry.getByRole('textbox', { name: 'Title' }).fill('Oil change')
	await entry.getByRole('textbox', { name: 'Counter reading' }).fill('10500')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// The next one counts from the work itself: a year from today, 15,000 km from its counter.
	const next = new Date(now)
	next.setUTCFullYear(next.getUTCFullYear() + 1)
	const row = banner.getByRole('listitem')
	await expect(row).toContainText('Planned')
	await expect(row).toContainText('25,500 km')
	await expect(row).toContainText(new Intl.DateTimeFormat('en', { dateStyle: 'medium' }).format(next))

	await page.goto(appPage)
	await expect(page.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect.poll(oilFirst).toBe(false)
})
