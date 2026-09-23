/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, t } from '@nextcloud/l10n'

import { expenseWord, formatMoney } from './format.js'

/**
 * Three bands and no more: six are unreadable at 320 px. Expenses are one band here and itemised in
 * the table (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @typedef {'energy'|'maintenance'|'expenses'} Band
 */

/** The bands, bottom up. @type {Band[]} */
export const BANDS = ['energy', 'maintenance', 'expenses']

/**
 * @typedef {object} Bar
 * @property {number} month - 1 to 12
 * @property {boolean} empty - the month has no rows, which is not the same as costing nothing
 * @property {{band: Band, from: number, to: number}[]} bands - bottom up, as fractions of the
 *   dearest month's height; a band that cost nothing is left out
 */

/**
 * One stacked bar per month, scaled to the dearest month.
 *
 * @param {import('../services/api.js').CostYear} answer - the year as the route answers it
 * @return {Bar[]} twelve bars, January first
 */
export function barsOf(answer) {
	const heights = answer.months.map(({ cost }) => BANDS.map((band) => Math.max(0, bandOf(cost, band) ?? 0)))
	const tallest = Math.max(0, ...heights.map((bands) => bands.reduce((sum, one) => sum + one, 0)))

	return answer.months.map(({ month, cost }, i) => {
		/** @type {Bar['bands']} */
		const bands = []
		let from = 0
		heights[i].forEach((height, j) => {
			if (height > 0) {
				bands.push({ band: BANDS[j], from: from / tallest, to: (from + height) / tallest })
				from += height
			}
		})

		return { month, empty: cost.total === null, bands }
	})
}

/**
 * @typedef {object} Row
 * @property {string} key - unique within the table
 * @property {string} label - what the row counts
 * @property {boolean} item - an expense category, one of the band above it broken out
 * @property {string[]} cells - one per month, then the year
 */

/**
 * The table beneath the bars: months across, the bands down with the expenses broken out beneath
 * theirs, and a total. The year's column is the server's year, not a sum of the months.
 *
 * @param {import('../services/api.js').CostYear} answer - the year as the route answers it
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {{columns: string[], rows: Row[]}} the column heads and the rows
 */
export function tableOf(answer, locale = getCanonicalLocale()) {
	const months = new Intl.DateTimeFormat(locale, { month: 'short', timeZone: 'UTC' })
	const columns = [
		...answer.months.map(({ month }) => months.format(Date.UTC(2000, month - 1, 1))),
		t('nextfleet', 'Year'),
	]
	const costs = [...answer.months.map(({ cost }) => cost), answer.year.cost]

	/**
	 * @param {(cost: import('../services/api.js').Cost) => number|null} read - the row's figure in a period
	 * @param {string} none - what a period with no rows says instead
	 * @return {string[]} the cells
	 */
	const cells = (read, none = '–') => costs.map((cost) => {
		const cents = read(cost)

		return cents === null ? none : formatMoney(cents, cost.currency, locale)
	})

	// The year lists every category any of its months had, already in the server's order.
	const categories = (answer.year.cost.expenses ?? []).map(({ category }) => category)

	return {
		columns,
		rows: [
			...BANDS.flatMap((band) => [
				{ key: band, label: bandWord(band), item: false, cells: cells((cost) => bandOf(cost, band)) },
				...(band === 'expenses' ? categories : []).map((category) => ({
					key: `expenses:${category ?? ''}`,
					label: category === null ? t('nextfleet', 'Uncategorised') : expenseWord(category),
					item: true,
					// A priced period without the category spent nothing on it.
					cells: cells((cost) => cost.expenses === null ? null : (cost.expenses.find((one) => one.category === category)?.total ?? 0)),
				})),
			]),
			{ key: 'total', label: t('nextfleet', 'Total'), item: false, cells: cells((cost) => cost.total, t('nextfleet', 'No entries')) },
		],
	}
}

/**
 * @typedef {object} Co2Text
 * @property {string} figure - the kilograms, or why there are none
 * @property {string[]} notes - what the figure rests on
 * @property {{label: string, href: string}[]} sources - linked, never quoted
 */

/**
 * The year's CO₂ as the Costs screen states it: always an estimate, with each factor's year and
 * source. Null from the server is "unavailable", which is not a zero.
 *
 * @param {import('../services/api.js').Co2|null} co2 - the year's, as the route answers it
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Co2Text} what the screen says
 */
export function co2Of(co2, locale = getCanonicalLocale()) {
	if (co2 === null) {
		return {
			figure: t('nextfleet', 'Unavailable'),
			notes: [t('nextfleet', 'The country this vehicle is kept under states no emission factors')],
			sources: [],
		}
	}

	const kilograms = new Intl.NumberFormat(locale, { style: 'unit', unit: 'kilogram', maximumFractionDigits: 0 })
	const notes = [t('nextfleet', 'An estimate: what was tanked or charged, times its emission factor')]
	const sources = [{ label: t('nextfleet', 'Fuel factors'), href: co2.source }]
	const { grid } = co2
	if (grid !== null && grid.year !== null && grid.source !== null) {
		notes.push(t('nextfleet', 'Electricity at {grams} g/kWh, the average of {year}', { grams: grid.grams, year: grid.year }))
		sources.push({ label: t('nextfleet', 'Grid factor {year}', { year: grid.year }), href: grid.source })
	} else if (grid !== null) {
		notes.push(t('nextfleet', 'Electricity at {grams} g/kWh, your own figure from the settings', { grams: grid.grams }))
	}
	if (co2.unstated) {
		notes.push(t('nextfleet', 'Leaves out fill-ups of an energy with no emission factor'))
	}

	// No grams with something left out means fill-ups without a factor, not a year without any.
	const none = co2.unstated ? t('nextfleet', 'Not stated') : t('nextfleet', 'No fill-ups')

	return {
		figure: co2.grams === null ? none : `≈ ${kilograms.format(co2.grams / 1000)}`,
		notes,
		sources,
	}
}

/**
 * @param {Band} band - a band of the bars
 * @return {string} its word
 */
export function bandWord(band) {
	return {
		energy: t('nextfleet', 'Energy'),
		maintenance: t('nextfleet', 'Maintenance'),
		expenses: t('nextfleet', 'Expenses'),
	}[band]
}

/**
 * @param {import('../services/api.js').Cost} cost - a period's
 * @param {Band} band - which share of it
 * @return {number|null} the band's cents, null when the period has no sums
 */
export function bandOf(cost, band) {
	if (band === 'expenses') {
		return cost.expenses === null ? null : cost.expenses.reduce((sum, one) => sum + one.total, 0)
	}

	return cost[band]
}
