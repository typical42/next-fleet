/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { add, api, appPage, choice, login, open, removeVehicles } from './app.js'

// Every vehicle this file makes wears this prefix, and every run deletes what it finds under it
// before starting. It has to be disjoint from every other spec file's prefix: Playwright runs the
// files at once, and a sweep that matched another file's plates would delete a vehicle out from
// under a test that is still using it.
const plates = 'M2-E2E-'

/** What the vehicle starts on, and what the two journeys below leave it at. */
const starting = 148320
const ended = '148 402'
const covered = '82'

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
})

test('a trip is entered as a counter or as a distance, and the vehicle follows either', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	const vehicle = await add(page, plate, starting)
	await page.goto(appPage)
	await open(page, plate)

	// The sheet opens on the trip, and neither counter is filled in for the driver: the one it set
	// off on is a claim about what the dashboard read, not a copy of the vehicle's own counter
	// (docs/ui.md).
	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'New entry' })
	await expect(entry.getByRole('textbox', { name: 'Start counter' })).toHaveValue('')
	await entry.getByRole('textbox', { name: 'Start counter' }).fill(String(starting))
	await entry.getByRole('textbox', { name: 'End counter' }).fill(ended)
	await entry.getByRole('textbox', { name: 'Purpose' }).fill('Kundentermin')
	await entry.getByRole('textbox', { name: 'Destination' }).fill('Augsburg')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// The counter the journey ended on is a Reading, so the vehicle moved with it
	// (docs/architecture.md#odometer-rules) - and the screen says so without a reload, because the
	// store read the vehicle back rather than counting for itself.
	await expect(page.getByText('148,402 km')).toBeVisible()

	// The other half of the toggle: the driver who read no counter states the kilometres, and the
	// Reading is counted onto the chain from the last one before the journey set off (rule 6).
	await page.getByRole('button', { name: 'New entry' }).click()
	await choice(entry, 'Distance').click()
	await entry.getByRole('textbox', { name: 'Distance' }).fill(covered)
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()
	await expect(page.getByText('148,484 km')).toBeVisible()

	// A reload is what proves the server kept both, and the vehicle it kept them on is the one
	// whose counter the two journeys moved.
	const saved = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })
	expect(saved.odo_value).toBe(148484)
})

test('a trip the server refuses leaves the sheet open with every value in it', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	await add(page, plate, starting)
	await page.goto(appPage)
	await open(page, plate)

	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'New entry' })
	await entry.getByRole('textbox', { name: 'Purpose' }).fill('Kundentermin')
	// Neither a counter nor a distance: the journey leaves the counter with nothing to say, which
	// is the one thing about a trip the server refuses outright (lib/Service/TripService.php).
	await entry.getByRole('button', { name: 'Save' }).click()

	await expect(entry).toBeVisible()
	await expect(entry.getByRole('textbox', { name: 'Purpose' })).toHaveValue('Kundentermin')
	await expect(entry.getByRole('button', { name: 'Try again' })).toBeVisible()
})
