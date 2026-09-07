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

import { appPage, login } from '../tests/e2e/app.js'

const baseURL = process.env.NEXTFLEET_URL_NC34 ?? 'http://localhost:8080'
const out = 'design/screenshots'

// The store's own listing is narrow, so a wide desktop shot reads as a smear of whitespace.
// This is the width at which the navigation and the content column both still carry weight.
const viewport = { width: 1280, height: 800 }
const thumbnail = { width: 460, height: 288 }

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

await mkdir(out, { recursive: true })
await login(page)
await page.goto(appPage)

// The overview: every seeded vehicle in one list, counters included.
await page.getByRole('main').getByRole('listitem').first().waitFor()
await shoot(page, 'overview')

// A vehicle screen, with a timeline long enough to show derived and flagged readings apart.
await page.locator('.app-navigation').getByText('NF-DE 100').click()
await page.getByRole('heading', { name: 'NF-DE 100' }).waitFor()
await shoot(page, 'vehicle')

// The entry sheet, open. `waitFor` returns as soon as the dialog has a box, which is before
// NcDialog has finished fading it in - a screenshot taken then catches it at opacity 0. Waiting
// on a control inside it, and then on the animation, is what makes the picture reproducible.
await page.getByRole('button', { name: 'New entry' }).click()
const sheet = page.getByRole('dialog', { name: 'Odometer' })
await sheet.getByRole('button', { name: 'Save' }).waitFor()
await page.waitForFunction(() => [...document.querySelectorAll('[role="dialog"]')]
	.every((d) => getComputedStyle(d).opacity === '1'))
await shoot(page, 'entry-sheet')
await page.keyboard.press('Escape')

// The thumbnail is the overview again at listing size, not a scaled copy: the store puts it
// beside the title, where a shrunk 1280px shot is unreadable.
await page.setViewportSize(thumbnail)
await page.goto(appPage)
await page.getByRole('main').getByRole('listitem').first().waitFor()
await shoot(page, 'overview-thumb')

await browser.close()
