/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Store screenshots, taken from a seeded dev container.
 *
 * The app store fetches the images by URL rather than reading the repository, so these are
 * committed and served from `main`; `appinfo/info.xml` names them. Re-run whenever a screen
 * changes, or the store shows the old one forever.
 *
 * The fleet is what `occ nextfleet:seed` writes, so the pictures show the awkward rows the
 * demo fleet exists for rather than a tidy invention.
 *
 *   docker compose -f .docker/compose.yml exec -u www-data app php occ nextfleet:seed admin
 *   npm run screenshots:docker
 */

/* eslint-disable no-console -- naming each file it wrote is this script's only output. */

import { mkdir } from 'node:fs/promises'

import { chromium } from '@playwright/test'

import { appPage, login, logout } from '../tests/e2e/app.js'

const baseURL = process.env.NEXTFLEET_URL_NC34 ?? 'http://localhost:8080'
const out = 'design/screenshots'

// The store's listing is narrow and a wide shot reads as whitespace; at this width the navigation
// and the content column both still carry weight.
const viewport = { width: 1280, height: 800 }
const thumbnail = { width: 460, height: 288 }
// Tall enough for the CO₂ estimate under the year's table.
const costsViewport = { width: 1280, height: 960 }

/**
 * @param {import('@playwright/test').Page} page - a signed-in page
 * @param {string} name - file stem under design/screenshots
 * @param {import('@playwright/test').Locator} [clip] - element to frame, else the viewport
 */
async function shoot(page, name, clip) {
	await page.screenshot({ path: `${out}/${name}.png`, ...(clip ? { clip: await clip.boundingBox() ?? undefined } : {}) })
	console.log(`${out}/${name}.png`)
}

const browser = await chromium.launch()
const page = await browser.newPage({ baseURL, viewport })

// Nextcloud's font stack starts at system-ui, which this image resolves to a CJK font whose € is
// a full digit wide - every amount would read "€ 19.89". Liberation Sans is Arial's metrics, what
// a desktop without Segoe UI or SF would show. Set on every element because the theme declares
// the variable on body, not only on the root.
await page.addInitScript(() => document.addEventListener('DOMContentLoaded', () => {
	const style = document.createElement('style')
	style.textContent = "* { --font-face: 'Liberation Sans', Arial, sans-serif !important; }"
	document.head.append(style)
}))

await mkdir(out, { recursive: true })
await login(page, 'admin', 'admin')
await page.goto(appPage)

// The overview. The logbook question each German vehicle asks would push the fleet out of frame;
// CSS hides it, since answering it would change admin's preferences for good.
await page.getByRole('main').getByRole('listitem').first().waitFor()
await page.addStyleTag({ content: '#nextfleet .hint > :has(.hint__ask) { display: none; }' })
await shoot(page, 'overview')

// A vehicle screen, with a timeline long enough to show derived and flagged readings apart.
await page.locator('.app-navigation').getByText('NF-DE 100').click()
await page.getByRole('heading', { name: 'NF-DE 100' }).waitFor()
await shoot(page, 'vehicle')

// The entry sheet. The dialog has a box before NcDialog has faded it in from opacity 0, so wait on
// a control inside it and then on the animation.
await page.getByRole('button', { name: 'New entry' }).click()
const sheet = page.getByRole('dialog', { name: 'New entry' })
await sheet.getByRole('button', { name: 'Save' }).waitFor()
await page.waitForFunction(() => [...document.querySelectorAll('[role="dialog"]')]
	.every((d) => getComputedStyle(d).opacity === '1'))
await shoot(page, 'entry-sheet')
await page.keyboard.press('Escape')

// The Passat's year of costs, which the seed fills to the current month. The table answers
// after the chart's frame is up, so wait on a cell. Beside the navigation the twelve months
// scroll inside their region and the picture ends mid-table, so the navigation closes for it.
await page.getByRole('button', { name: 'Costs', exact: true }).click()
await page.getByRole('region', { name: 'Costs by month' }).getByRole('cell').first().waitFor()
await page.getByRole('button', { name: 'Close navigation' }).click()
await page.setViewportSize(costsViewport)
await page.waitForFunction(() => {
	const table = document.querySelector('[aria-label="Costs by month"]')
	return table !== null && table.scrollWidth <= table.clientWidth
		&& document.getAnimations().every((one) => one.playState !== 'running')
})
await page.getByRole('heading', { name: 'CO₂ (estimate)' }).waitFor()
await shoot(page, 'costs')
await page.setViewportSize(viewport)
await page.getByRole('button', { name: 'Open navigation' }).click()

// Reports on the Passat, where both a Fahrtenbuch and a mileage claim print.
await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
const reports = page.locator('#nextfleet').getByRole('main')
await reports.getByRole('combobox', { name: 'Vehicle' }).click()
await page.getByRole('option').filter({ hasText: 'NF-DE 100' }).click()
await reports.getByRole('link', { name: 'Open mileage claim' }).waitFor()
await shoot(page, 'reports')

// The overview again at listing size, not a scaled copy: beside the store's title a shrunk 1280px
// shot is unreadable. The missing-details hint would fill the frame; CSS hides it, since
// dismissing it would change admin's preferences, which the seed does not reset.
await page.setViewportSize(thumbnail)
await page.goto(appPage)
await page.getByRole('main').getByRole('listitem').first().waitFor()
await page.addStyleTag({ content: '#nextfleet .hint { display: none; }' })
await shoot(page, 'overview-thumb')

await logout(page)
await browser.close()
