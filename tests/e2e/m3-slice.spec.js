/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '@playwright/test'

import { api, appPage, audit, choice, login, open, opened, removeVehicles } from './app.js'

// Disjoint from every other file's prefix, as a substring too (tests/e2e/m2-slice.spec.js says why).
const plates = 'M3-E2E-'

/** @type {(days: number) => number} Unix seconds, a number of days before now. */
const ago = (days) => Math.floor(Date.now() / 1000) - days * 86400

test.beforeEach(async ({ page }) => {
	await login(page)
	await removeVehicles(page, plates)
	// The period is a preference kept on the server (lib/Service/PreferencesService.php), so the
	// picker case below would otherwise leave every later run on the period it chose last.
	await api(page, { method: 'PUT', path: '/api/preferences', body: { kpi_period: 'last-12', reclaim_vat: false } })
})

/**
 * A vehicle with energy, under `de` by name: the personal settings case in the M1 slice may have
 * moved this user's default, and `de` is what gives it a currency.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {string} plate - the label to find it by
 * @param {string[]} energies - its `energy_types`
 * @return {Promise<any>} the vehicle as the server made it
 */
function vehicleWith(page, plate, energies) {
	return api(page, { method: 'POST', path: '/api/vehicles', body: { plate, jurisdiction: 'de', energy_types: energies } })
}

/**
 * A full fill-up with a counter, the kind that opens and closes a consumption segment.
 *
 * @param {import('@playwright/test').Page} page - a page on a signed-in Nextcloud
 * @param {any} vehicle - the vehicle it is for
 * @param {object} fill - what differs between fill-ups: `filled_at`, `odo`, `amount` and more
 * @return {Promise<any>} the fill-up as the server made it
 */
function fillUp(page, vehicle, fill) {
	return api(page, {
		method: 'POST',
		path: `/api/vehicles/${vehicle.uuid}/energy`,
		body: { energy: vehicle.energy_types[0], filled_at_off: 0, total: 5100, vat_rate: 1900, full_tank: true, ...fill },
	})
}

/**
 * @param {import('@playwright/test').Page} page - a page showing a vehicle
 * @param {string} label - the tile's term
 * @return {import('@playwright/test').Locator} the header tile of that name
 */
function tile(page, label) {
	return page.locator('.kpis .tile').filter({ has: page.getByRole('term').getByText(label, { exact: true }) })
}

test('a fill-up entered in the sheet moves the header', async ({ page }) => {
	const plate = `${plates}fill-${Date.now()}`
	const vehicle = await vehicleWith(page, plate, ['diesel'])
	await fillUp(page, vehicle, { filled_at: ago(30), odo: 10000, amount: 30000 })
	await page.goto(appPage)
	await open(page, plate)

	// One full fill-up opens a segment and closes none, and one Reading is no distance - so no
	// consumption yet, and the cost is a period total (docs/architecture.md#numbers-consumption-cost-emissions).
	await expect(tile(page, 'Cost in the period')).toContainText('€51.00')
	await expect(tile(page, 'Diesel consumption')).toHaveCount(0)

	await page.getByRole('button', { name: 'New entry' }).click()
	const entry = page.getByRole('dialog', { name: 'New entry' })
	await choice(entry, 'Energy').click()
	await entry.getByRole('textbox', { name: 'Amount (l)' }).fill('30')
	await entry.getByRole('textbox', { name: 'Total price' }).fill('51,00')
	await entry.getByRole('textbox', { name: 'Counter reading' }).fill('10500')
	await entry.getByRole('button', { name: 'Save' }).click()
	await expect(entry).toBeHidden()

	// 30 l over 500 km, on the closing fill-up's row and in the header, without a reload.
	await expect(tile(page, 'Diesel consumption')).toContainText('6.0 l/100 km')
	await expect(tile(page, 'Odometer')).toContainText('10,500 km')
	// Both fill-ups over the 500 km between their counters.
	await expect(tile(page, 'Cost')).toContainText('€20.40/100 km')
	await expect(page.locator('.timeline').getByRole('listitem').first()).toContainText('6.0 l/100 km')
})

test('a plug-in hybrid shows both consumptions side by side, and the one at the charger', async ({ page }) => {
	const plate = `${plates}hybrid-${Date.now()}`
	const vehicle = await vehicleWith(page, plate, ['petrol', 'electric'])
	await fillUp(page, vehicle, { energy: 'petrol', filled_at: ago(20), odo: 20000, amount: 40000 })
	await fillUp(page, vehicle, { energy: 'electric', filled_at: ago(19), odo: 20050, amount: 10000, location_kind: 'home' })
	await fillUp(page, vehicle, { energy: 'petrol', filled_at: ago(10), odo: 20600, amount: 36000 })
	await fillUp(page, vehicle, { energy: 'electric', filled_at: ago(9), odo: 20650, amount: 108000, location_kind: 'home' })
	await page.goto(appPage)
	await open(page, plate)

	// Never a blend: a litre and a kilowatt-hour do not add up (docs/ui.md).
	await expect(tile(page, 'Petrol consumption')).toContainText('6.0 l/100 km')
	await expect(tile(page, 'Electric consumption')).toContainText('18.0 kWh/100 km')
	const wall = tile(page, 'Electric, at the charger')
	await expect(wall).toContainText('≈')
	await expect(wall).toContainText('charging losses are included')
})

/** docs/ui.md: every Entry opens from its row, and the way back from a delete is the toast. */
test('a fill-up is edited from its row, and deleted and brought back from the toast', async ({ page }) => {
	const plate = `${plates}edit-${Date.now()}`
	const vehicle = await vehicleWith(page, plate, ['diesel'])
	await fillUp(page, vehicle, { filled_at: ago(30), odo: 10000, amount: 30000 })
	await fillUp(page, vehicle, { filled_at: ago(20), odo: 10500, amount: 30000 })
	await page.goto(appPage)
	await open(page, plate)
	const consumption = tile(page, 'Diesel consumption')
	await expect(consumption).toContainText('6.0 l/100 km')

	const closing = page.locator('.timeline').getByRole('listitem').first()
	await closing.getByRole('button', { name: 'Diesel' }).click()
	const sheet = page.getByRole('dialog', { name: 'Edit entry' })
	await expect(sheet.getByRole('textbox', { name: 'Amount (l)' })).toHaveValue('30')
	await sheet.getByRole('textbox', { name: 'Amount (l)' }).fill('36')
	await sheet.getByRole('button', { name: 'Save' }).click()
	await expect(sheet).toBeHidden()
	await expect(closing).toContainText('36')
	await expect(consumption).toContainText('7.2 l/100 km')

	// Nothing asks "are you sure?".
	await closing.getByRole('button', { name: 'Diesel' }).click()
	await sheet.getByRole('button', { name: 'Delete' }).click()
	await expect(sheet).toBeHidden()
	const toast = page.locator('.toast')
	await expect(toast).toContainText('The entry was deleted.')
	await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(1)
	await expect(consumption).toHaveCount(0)

	await toast.getByRole('button', { name: 'Undo' }).click()
	await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(2)
	await expect(consumption).toContainText('7.2 l/100 km')
})

test('the period picker changes the figures, and each is compared with the period before', async ({ page }) => {
	const plate = `${plates}period-${Date.now()}`
	const vehicle = await vehicleWith(page, plate, ['diesel'])
	// Early January of last year is in "Last year" and outside the last 12 months for all but the
	// first days of a year; the last two hours are in the last 12 months and outside last year
	// for all but the first hours of one.
	const lastYear = new Date().getUTCFullYear() - 1
	const hour = 3600
	await fillUp(page, vehicle, { filled_at: Date.UTC(lastYear, 0, 2, 12) / 1000, odo: 10000, amount: 30000 })
	await fillUp(page, vehicle, { filled_at: Date.UTC(lastYear, 0, 3, 12) / 1000, odo: 10500, amount: 30000 })
	// The segment across the months between is not measured: a fill-up in it went unrecorded.
	await fillUp(page, vehicle, { filled_at: ago(0) - 2 * hour, odo: 11000, amount: 40000, missed_previous: true })
	await fillUp(page, vehicle, { filled_at: ago(0) - hour, odo: 11400, amount: 28000 })
	await page.goto(appPage)
	await open(page, plate)

	const consumption = tile(page, 'Diesel consumption')
	await expect(consumption).toContainText('7.0 l/100 km')
	// The period before the last 12 months is the 12 before them, which holds last January.
	await expect(consumption).toContainText('+1.0 l/100 km vs. the period before')

	await page.locator('.kpis').getByRole('combobox', { name: 'Period' }).click()
	await page.getByRole('option', { name: 'Last year' }).click()
	await expect(consumption).toContainText('6.0 l/100 km')

	// Kept on the server, so the next visit opens on it. Polled: the write is queued behind the
	// figures (src/store/preferences.js).
	await expect.poll(async () => (await api(page, { method: 'GET', path: '/api/preferences' })).preferences.kpi_period)
		.toBe('last-year')
})

/**
 * Dark mode and 320 px are acceptance criteria for the header and the sheet, as they are for the
 * timeline (tests/e2e/m2-slice.spec.js says why this runs on one major).
 */
test.describe('at 320 x 640, in the dark', { tag: '@nc34' }, () => {
	test.use({ viewport: { width: 320, height: 640 }, colorScheme: 'dark' })

	test('the vehicle screen and an open fill-up pass an axe audit', async ({ page }) => {
		const plate = `${plates}dark`
		const vehicle = await vehicleWith(page, plate, ['petrol', 'electric'])
		await fillUp(page, vehicle, { energy: 'petrol', filled_at: ago(40), odo: 20000, amount: 40000 })
		await fillUp(page, vehicle, { energy: 'electric', filled_at: ago(39), odo: 20050, amount: 10000, location_kind: 'public', is_dc: true })
		await fillUp(page, vehicle, { energy: 'petrol', filled_at: ago(30), odo: 20600, amount: 18000, full_tank: false })
		// No price: the row says so in words, and the cost tiles say they are incomplete.
		await fillUp(page, vehicle, { energy: 'petrol', filled_at: ago(20), odo: 21000, amount: 36000, total: null })
		await api(page, {
			method: 'POST',
			path: `/api/vehicles/${vehicle.uuid}/maintenance`,
			body: { done_at: ago(15), done_at_off: 0, title: 'Oil change', type: 'service', cost: 18900, vat_rate: 1900, vendor: 'Autohaus Müller', odo: 21100 },
		})
		await api(page, {
			method: 'POST',
			path: `/api/vehicles/${vehicle.uuid}/expenses`,
			body: { spent_at: ago(10), spent_at_off: 0, category: 'insurance', amount: 42000 },
		})

		await page.goto(appPage)
		await open(page, plate)
		await expect(tile(page, 'Petrol consumption')).toBeVisible()
		await expect(page.locator('.timeline').getByRole('listitem')).toHaveCount(6)
		await expect(page.locator('.row__flag').filter({ hasText: 'No price' })).toHaveCount(1)
		await audit(page, 'the vehicle screen, header and all')

		await page.locator('.timeline').getByRole('button', { name: 'Electric' }).click()
		const sheet = page.getByRole('dialog', { name: 'Edit entry' })
		await opened(sheet)
		await audit(page, 'a fill-up open in the sheet', ['#nextfleet', '[role="dialog"]'])
	})
})
