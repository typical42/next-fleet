/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, login } from './app.js'

// The fixture is `occ nextfleet:seed`, run against both majors before the suite
// (.github/workflows/ci.yml). Nothing here writes: the point of the demo fleet is the rows that
// are awkward to make by hand, and a spec that made them would be testing its own arithmetic.
const demo = 'NF-'

// Straight out of lib/Command/SeedCommand.php. The counter is grouped by the admin session's
// locale, English on this stack (docs/ui.md#languages).
const hours = { plate: `${demo}LW 300`, made: 'Fendt 313 Vario', counter: '1,668 h' }
const trailer = { plate: `${demo}AH 400`, made: 'Humbaur HA 752513' }
const disposed = `${demo}XY 500`

test.beforeEach(async ({ page }) => {
	await login(page)
	await page.goto(appPage)
})

test('the demo fleet is on screen with the rows that are awkward on purpose', async ({ page }) => {
	// The app's own content area: the navigation lists the same vehicles, and Nextcloud's chrome
	// has landmarks of its own.
	const overview = page.locator('#nextfleet').getByRole('main')
	const row = (/** @type {string} */ plate) => overview.getByRole('listitem').filter({ hasText: plate })

	await expect(row(hours.plate), `${demo} vehicles are missing - run occ nextfleet:seed`)
		.toBeVisible()

	// A Value is a number and a unit, never "km" (CONTEXT.md): a tractor counts engine hours and
	// the overview has to say so in its own row, beside cars that count kilometres.
	await expect(row(hours.plate)).toContainText(hours.made)
	await expect(row(hours.plate)).toContainText(hours.counter)
	await expect(row(`${demo}DE 100`)).toContainText('111,900 km')

	// A trailer counts neither, so it has no counter at all - and a zero would read as one that
	// has never moved rather than as one that is not kept.
	await expect(row(trailer.plate)).toContainText(trailer.made)
	await expect(row(trailer.plate)).toContainText('Laid up')
	await expect(row(trailer.plate)).not.toContainText('km')

	// Sold, kept for the retention period, and off the overview until something asks for it
	// (docs/ui.md).
	await expect(row(disposed)).toHaveCount(0)
})

test('a flagged reading is the demo fleet, not a broken write', async ({ page }) => {
	// The cluster was swapped, so the counter starts over: the row below the one before it is kept
	// and flagged rather than refused (docs/architecture.md#odometer-rules). No screen shows a
	// flag yet - the timeline that asks about it is M2 - so the vehicle is what answers.
	const fleet = await api(page, { method: 'GET', path: '/api/vehicles' })

	const hybrid = fleet.find((/** @type {{plate: string}} */ vehicle) => vehicle.plate === `${demo}PH 200`)
	expect(hybrid?.energy_types).toEqual(['petrol', 'electric'])
	// The newest reading, which is the one after the reset - not the highest the vehicle ever read.
	expect(hybrid?.odo_value).toBe(13040)
})
