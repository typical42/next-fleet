/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { add, api, appPage, audit, choice, login, open, removeVehicles, toggle } from './app.js'

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

/** docs/features.md#logbook-mode: saved, flagged, and asked for the rest in the sheet's words. */
test('a business trip missing what the logbook requires is saved and asks for the rest', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, logbook_mode: true } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value: starting, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 },
	})
	await page.goto(appPage)
	await open(page, plate)

	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'New entry' })
	await entry.getByRole('textbox', { name: 'End counter' }).fill(ended)
	await entry.getByRole('textbox', { name: 'Destination' }).fill('Augsburg')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	const trip = page.locator('.timeline').getByRole('listitem').first()
	await expect(trip).toContainText('Augsburg')
	await expect(trip).toContainText('Incomplete')
	await expect(trip).toContainText('Still missing: Start counter, Purpose, Business partner')
})

/**
 * docs/features.md#logbook-mode: a trip that sets off above the counter before it leaves the
 * difference unaccounted, and the month it set off in says so.
 */
test('the month a trip set off above the counter in states the kilometres nobody accounted for', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, logbook_mode: true } })
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/readings`,
		body: { value: starting, read_at: Math.floor(Date.UTC(2026, 6, 1, 8, 0) / 1000), read_at_off: 120 },
	})
	const at = Math.floor(Date.UTC(2026, 7, 3, 8, 0) / 1000)
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/trips`,
		body: {
			started_at: at,
			started_at_off: 120,
			ended_at: at + 3600,
			ended_at_off: 120,
			category: 'private',
			start_odo: starting + 80,
			end_odo: starting + 120,
		},
	})

	await page.goto(appPage)
	await open(page, plate)

	const months = page.locator('.timeline__month')
	await expect(months).toHaveCount(2)
	await expect(months.first()).toContainText('80 km unaccounted')
	await expect(months.last()).not.toContainText('unaccounted')
})

/**
 * The switch that puts a vehicle under its jurisdiction's logbook rules
 * (docs/features.md#logbook-mode). The checkbox behind it is drawn under the toggle it looks like,
 * so vitest cannot say that a finger reaches it; this is where that is proved.
 */
test('Logbook mode is switched on with one gesture and off with an answer', async ({ page }) => {
	const plate = `${plates}${Date.now()}`
	const vehicle = await add(page, plate, starting)
	await page.goto(appPage)
	await open(page, plate)

	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit vehicle' })
	const mode = sheet.getByRole('switch', { name: 'Logbook mode' })
	await expect(mode).not.toBeChecked()

	// On takes something on rather than away, so it asks nothing.
	await toggle(sheet, 'Logbook mode').click()
	await expect(mode).toBeChecked()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()
	expect((await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })).logbook_mode).toBe(true)

	// Off ends the period an auditor reads this vehicle's trips under, so it stands in the way of
	// the save until it is answered.
	await page.getByRole('button', { name: 'Edit vehicle' }).click()
	await expect(mode).toBeChecked()
	await toggle(sheet, 'Logbook mode').click()
	await expect(sheet.getByRole('button', { name: 'Switch it off' })).toBeVisible()
	await expect(sheet.getByRole('button', { name: 'Save' })).toBeDisabled()

	// The way back the question offers puts the switch where it was, and takes itself away with it.
	await sheet.getByRole('button', { name: 'Keep it on' }).click()
	await expect(mode).toBeChecked()
	await expect(sheet.getByRole('button', { name: 'Switch it off' })).toBeHidden()

	await toggle(sheet, 'Logbook mode').click()
	await sheet.getByRole('button', { name: 'Switch it off' }).click()
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()
	expect((await api(page, { method: 'GET', path: `/api/vehicles/${vehicle.uuid}` })).logbook_mode).toBe(false)
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
 * One Gap, one confirmation, one private trip the app marks reconciled - and the month header has
 * nothing left to state (docs/features.md#logbook-mode).
 */
test('a Gap is closed by one confirmed private trip', async ({ page }) => {
	const plate = `${plates}gap-${Date.now()}`
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, logbook_mode: true } })
	// The second journey says it set off 8 km above where the first one ended.
	for (const [hour, ending, claim] of [[8, 148402, undefined], [12, 148484, 148410]]) {
		const at = Math.floor(Date.UTC(2026, 6, 15, hour, 0) / 1000)
		await api(page, {
			method: 'POST',
			path: `/api/vehicles/${vehicle.uuid}/trips`,
			body: { started_at: at, started_at_off: 120, ended_at: at + 3600, ended_at_off: 120, category: 'business', start_odo: claim, end_odo: ending },
		})
	}

	await page.goto(appPage)
	await open(page, plate)
	await expect(page.locator('.timeline__gap')).toHaveText('8 km unaccounted')

	await page.locator('.timeline').getByRole('button', { name: 'Close gap' }).click()
	const question = page.getByRole('dialog', { name: 'Close gap' })
	await expect(question).toContainText('Record 8 km driven between')
	await question.getByRole('button', { name: 'Record private trip' }).click()
	await expect(question).toBeHidden()

	await expect(page.locator('.timeline__gap')).toHaveCount(0)
	await expect(page.locator('.timeline').getByRole('listitem').filter({ hasText: 'Reconciled' })).toHaveCount(1)
})

/**
 * The Fahrtenbuch is a page the browser prints, opened from Reports in a tab of its own
 * (docs/architecture.md#the-fahrtenbuch-export). A navigation, not a request the app makes: this is
 * the case that proves the route answers a signed-in browser with no request token.
 */
test('the logbook opens from Reports for a vehicle and a year', async ({ page }) => {
	const plate = `${plates}report-${Date.now()}`
	// Under `de` by name: the personal settings case may have moved this user's default.
	const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', logbook_mode: true } })
	const at = Math.floor(Date.UTC(2025, 6, 15, 8, 0) / 1000)
	await api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/trips`,
		body: { started_at: at, started_at_off: 120, ended_at: at + 3600, ended_at_off: 120, category: 'private', end_odo: 148402 },
	})

	await page.goto(appPage)
	await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
	const screen = page.locator('#nextfleet').getByRole('main')
	await expect(screen.getByRole('heading', { name: 'Reports' })).toBeVisible()

	await screen.getByRole('combobox', { name: 'Vehicle' }).click()
	// By its text: the dropdown splits a long label into spans, and the accessible name with it.
	await page.getByRole('option').filter({ hasText: plate }).click()
	await screen.getByRole('textbox', { name: 'Year' }).fill('2025')
	const link = screen.getByRole('link', { name: 'Open logbook' })
	// With or without `index.php`, which is the server's rewrite configuration and not the app's.
	await expect(link).toHaveAttribute('href', new RegExp(`/apps/nextfleet/vehicles/${vehicle.uuid}/logbook/2025$`))
	await audit(page, 'the reports screen')

	const [tab] = await Promise.all([page.waitForEvent('popup'), link.click()])
	await expect(tab.getByRole('heading', { name: 'Fahrtenbuch 2025' })).toBeVisible()
	await expect(tab.locator('body')).toContainText(plate)

	const served = await page.request.get(/** @type {string} */ (await link.getAttribute('href')))
	expect(served.headers()['content-type']).toContain('text/html')
})

/** The overview is one click away from every screen, with no reload (docs/ui.md). */
test('the navigation leads back to the overview', async ({ page }) => {
	const plate = `${plates}back-${Date.now()}`
	await add(page, plate, starting)
	await page.goto(appPage)
	const navigation = page.locator('.app-navigation')
	const overview = navigation.getByRole('link', { name: 'Overview' })
	const screen = page.locator('#nextfleet').getByRole('main')

	await open(page, plate)
	await overview.click()
	await expect(screen.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect(page.getByRole('heading', { name: plate })).toBeHidden()

	await navigation.getByRole('link', { name: 'Reports' }).click()
	await expect(screen.getByRole('heading', { name: 'Reports' })).toBeVisible()
	await overview.click()
	await expect(screen.getByRole('heading', { name: 'Vehicles' })).toBeVisible()
	await expect(screen.getByRole('heading', { name: 'Reports' })).toBeHidden()
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
		// Under Logbook Mode, so the audit covers what the mode adds to the list: a month header
		// stating a Gap and the rows saying what they lack (docs/features.md#logbook-mode).
		const vehicle = await api(page, { method: 'POST', path: '/api/vehicles', body: { plate, logbook_mode: true } })
		await api(page, {
			method: 'POST',
			path: `/api/vehicles/${vehicle.uuid}/readings`,
			body: { value: starting, read_at: Math.floor(Date.now() / 1000), read_at_off: 0 },
		})
		// Written through the API rather than through the sheet: what is being audited is the list,
		// and several months of it is what makes a sticky header a sticky header. Both journeys end
		// above the counter read today, so that reading is flagged and the audit covers the question
		// the timeline puts as well (docs/architecture.md#odometer-rules). The second sets off above
		// where the first ended, which is the Gap.
		for (const [month, ending, claim] of [[6, 148402, undefined], [7, 148484, 148410]]) {
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
					start_odo: claim,
					end_odo: ending,
				},
			})
		}

		await page.goto(appPage)
		await open(page, plate)
		await expect(page.locator('.timeline__month')).toHaveCount(3)
		await expect(page.locator('.timeline__gap')).toHaveCount(1)
		// The flagged reading's question, each business trip's missing purpose and partner, and the
		// Gap offered for closing on the trip that opened it.
		await expect(page.locator('.row__flag')).toHaveCount(4)
		await audit(page, 'the vehicle screen, timeline and all')
	})

	test('the reports screen passes an axe audit', async ({ page }) => {
		// Something to print, so the audit covers the form rather than the empty state.
		await api(page, { method: 'POST', path: '/api/vehicles', body: { plate: `${plates}printed`, jurisdiction: 'de' } })
		await page.goto(appPage)
		// At this width the navigation is behind its toggle, and Reports is in it.
		await page.getByRole('button', { name: 'Open navigation' }).click()
		await page.locator('.app-navigation').getByRole('link', { name: 'Reports' }).click()
		await page.getByRole('button', { name: 'Close navigation' }).click()
		await expect(page.getByRole('link', { name: 'Open logbook' })).toBeVisible()
		await audit(page, 'the reports screen')
	})
})
