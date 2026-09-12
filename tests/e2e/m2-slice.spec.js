/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { add, api, appPage, audit, choice, login, open, removeVehicles } from './app.js'

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
	// The KPI, not just anywhere on the screen: the timeline below it states the counter the journey
	// ended on as well, and what moved here is the vehicle's own.
	await expect(page.locator('.vehicle__kpis').getByText('148,402 km')).toBeVisible()

	// The other half of the toggle: the driver who read no counter states the kilometres, and the
	// Reading is counted onto the chain from the last one before the journey set off (rule 6).
	await page.getByRole('button', { name: 'New entry' }).click()
	await choice(entry, 'Distance').click()
	await entry.getByRole('textbox', { name: 'Distance' }).fill(covered)
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()
	await expect(page.locator('.vehicle__kpis').getByText('148,484 km')).toBeVisible()

	// A reload is what proves the server kept both, and the vehicle it kept them on is the one
	// whose counter the two journeys moved.
	const saved = await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })
	expect(saved.odo_value).toBe(148484)
})

/**
 * One timeline of everything that happened to the vehicle, which is the question people actually
 * ask (docs/ui.md). Trips and counter readings are two tables and one list, merged by the server
 * (docs/architecture.md#the-timeline).
 */
test('the timeline lists both kinds of entry, and the chips narrow it to one', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	await add(page, plate, starting)
	await page.goto(appPage)
	await open(page, plate)

	// The counter `add` read is a row of its own, under the month it was read in.
	const timeline = page.locator('.timeline')
	const rows = timeline.getByRole('listitem')
	await expect(rows).toHaveCount(1)
	await expect(timeline.locator('.timeline__month')).toHaveCount(1)

	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'New entry' })
	await entry.getByRole('textbox', { name: 'End counter' }).fill(ended)
	await entry.getByRole('textbox', { name: 'Destination' }).fill('Augsburg')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// Without a reload: the screen reads the list back once the sheet reports a write
	// (src/views/VehicleView.vue). Newest first, so the journey is above the counter it set off on.
	await expect(rows).toHaveCount(2)
	await expect(rows.first()).toContainText('Augsburg')
	await expect(rows.first()).toContainText('148,402 km')
	// Still one month: two entries a minute apart, and the header is stated once above both.
	await expect(timeline.locator('.timeline__month')).toHaveCount(1)

	// A chip is a different question, asked from the top (docs/architecture.md#the-timeline).
	await choice(timeline, 'Trips').click()
	await expect(rows).toHaveCount(1)
	await expect(rows.first()).toContainText('Augsburg')

	await choice(timeline, 'Odometer').click()
	await expect(rows).toHaveCount(1)
	await expect(rows.first()).toContainText('Counter reading')

	await choice(timeline, 'All').click()
	await expect(rows).toHaveCount(2)
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

/**
 * The month is a sticky header (docs/ui.md), which is a statement about where an element is while
 * the list moves under it - so it is measured rather than looked at. One major: the answer is CSS
 * and there is one copy of it.
 */
test.describe('the month above the rows', { tag: '@nc34' }, () => {
	// Short, so the list is longer than the window it is read through without writing enough rows
	// to spend the route's own rate limit (lib/Controller/TripController.php).
	test.use({ viewport: { width: 900, height: 420 } })

	test('stays at the top of the screen while its own month scrolls past', async ({ page }) => {
		const plate = `${plates}sticky`
		const vehicle = await add(page, plate, starting)
		for (let trip = 0; trip < 6; trip++) {
			const at = Math.floor(Date.UTC(2026, 3 + Math.floor(trip / 3), 1 + trip, 8, 0) / 1000)
			await api(page, {
				method: 'POST',
				path: `/api/vehicles/${vehicle.uuid}/trips`,
				body: {
					started_at: at,
					started_at_off: 120,
					ended_at: at + 3600,
					ended_at_off: 120,
					category: 'business',
					to_label: `Stop ${trip}`,
					end_odo: starting + 10 + trip,
				},
			})
		}

		await page.goto(appPage)
		await open(page, plate)
		const month = page.locator('.timeline__month').nth(1)
		await expect(month).toBeVisible()

		// Far enough that the first rows of that month have gone past the top edge, which is when a
		// header that is not sticky has gone with them.
		await page.locator('.app-content').evaluate((scroller) => {
			const group = document.querySelectorAll('.timeline__group')[1]
			scroller.scrollTop += group.getBoundingClientRect().top - scroller.getBoundingClientRect().top + 60
		})

		const settled = await month.boundingBox()
		const scroller = await page.locator('.app-content').boundingBox()
		expect(settled?.y).toBeCloseTo(scroller?.y ?? 0, 0)
	})
})

/**
 * Dark mode and 320 px are acceptance criteria for the timeline, not afterthoughts - the list is
 * rows rather than cards so that it survives both (docs/ui.md). One major, for the reason the M1
 * audit gives: the answer is CSS and there is one copy of it.
 */
test.describe('at 320 x 640, in the dark', { tag: '@nc34' }, () => {
	test.use({ viewport: { width: 320, height: 640 }, colorScheme: 'dark' })

	test('the timeline passes an axe audit with entries in it', async ({ page }) => {
		// Not `small`: a plate has to be disjoint from every other file's as a *substring* too, not
		// just as a prefix. `M2-E2E-small` contains `E2E-small`, and the M1 slice finds the hint's
		// row for its vehicle with a `hasText` filter - which would then match two rows.
		const plate = `${plates}dark`
		const vehicle = await add(page, plate, starting)
		// Written through the API rather than through the sheet: what is being audited is the list,
		// and several months of it is what makes a sticky header a sticky header. Both journeys end
		// above the counter `add` read today, so that reading is flagged and the audit covers the
		// question the timeline puts as well (docs/architecture.md#odometer-rules).
		for (const [month, ending] of [[6, 148402], [7, 148484]]) {
			const at = Math.floor(Date.UTC(2026, month, 15, 8, 0) / 1000)
			await api(page, {
				method: 'POST',
				path: `/api/vehicles/${vehicle.uuid}/trips`,
				body: {
					started_at: at,
					started_at_off: 120,
					ended_at: at + 3600,
					ended_at_off: 120,
					category: 'business',
					to_label: 'Augsburg',
					end_odo: ending,
				},
			})
		}

		await page.goto(appPage)
		await open(page, plate)
		await expect(page.locator('.timeline__month')).toHaveCount(3)
		await expect(page.locator('.row__flag')).toHaveCount(1)
		await audit(page, 'the vehicle screen, timeline and all')
	})
})
