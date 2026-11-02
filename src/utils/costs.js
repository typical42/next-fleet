/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { expenseWord, formatMoney } from './format.js'
import { t } from './l10n.js'

/**
 * Three bands and no more: six are unreadable at 320 px. Expenses are one band here and itemised in
 * the table (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @typedef {'energy'|'maintenance'|'expenses'} Band
 */

/** The bands, bottom up. @type {Band[]} */
export const BANDS = ['energy', 'maintenance', 'expenses']

/**
 * @template {string} [B=Band]
 * @typedef {object} Bar
 * @property {boolean} empty - the bucket has no rows, which is not the same as adding up to nothing
 * @property {{band: B, from: number, to: number}[]} bands - bottom up, as fractions of the
 *   tallest bucket's height; a band of nothing is left out
 */

/**
 * One stacked bar per bucket, scaled to the dearest bucket.
 *
 * @param {import('../services/api.js').CostSeries} series - one currency's, as the route answers it
 * @return {Bar[]} one per bucket, in order
 */
export function barsOf(series) {
	const stacks = stacksOf(series.buckets.map((cost) => BANDS.map((band) => bandOf(cost, band) ?? 0)), BANDS)

	return series.buckets.map((cost, i) => ({ empty: cost.total === null, bands: stacks[i] }))
}

/**
 * Each bucket's bands stacked bottom up, scaled to the tallest bucket.
 *
 * @template {string} B
 * @param {number[][]} heights - each bucket's, one per band in the order of `bands`
 * @param {B[]} bands - bottom up
 * @return {Bar<B>['bands'][]} one per bucket, in order
 */
export function stacksOf(heights, bands) {
	const positive = heights.map((bucket) => bucket.map((height) => Math.max(0, height)))
	const tallest = Math.max(0, ...positive.map((bucket) => bucket.reduce((sum, one) => sum + one, 0)))

	return positive.map((bucket) => {
		/** @type {Bar<B>['bands']} */
		const stack = []
		let from = 0
		bucket.forEach((height, j) => {
			if (height > 0) {
				stack.push({ band: bands[j], from: from / tallest, to: (from + height) / tallest })
				from += height
			}
		})

		return stack
	})
}

/**
 * What each bucket is called: a year by its number, a month by its short name, with the year beside
 * it once the range runs over more than one. Read in the zone the server cut them in.
 *
 * @param {import('../services/api.js').Statistics} answer - the range as the route answers it
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @param {string} [timeZone] - the zone the range was asked in; defaults to the reader's
 * @return {string[]} one per bucket
 */
export function labelsOf(answer, locale = getCanonicalLocale(), timeZone = undefined) {
	const year = new Intl.DateTimeFormat(locale, { year: 'numeric', timeZone })
	if (answer.bucket === 'year') {
		return answer.buckets.map(({ from }) => year.format(from * 1000))
	}
	const years = new Set(answer.buckets.map(({ from }) => year.format(from * 1000)))
	const month = new Intl.DateTimeFormat(locale, { month: 'short', ...(years.size > 1 ? { year: '2-digit' } : {}), timeZone })

	return answer.buckets.map(({ from }) => month.format(from * 1000))
}

/**
 * @typedef {object} Row
 * @property {string} key - unique within the table
 * @property {string} label - what the row counts
 * @property {boolean} item - an expense category, one of the band above it broken out
 * @property {string[]} cells - one per bucket, then the whole range, then its share of the range
 */

/**
 * The table beneath the bars: buckets across, the bands down with the expenses broken out beneath
 * theirs, and a total. The whole range's column is the server's sum, not one of the buckets'.
 *
 * @param {import('../services/api.js').CostSeries} series - one currency's, as the route answers it
 * @param {string[]} labels - one per bucket (labelsOf())
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {{columns: string[], rows: Row[]}} the column heads and the rows
 */
export function tableOf(series, labels, locale = getCanonicalLocale()) {
	const columns = [...labels, t('nextfleet', 'Whole period'), t('nextfleet', 'Share')]
	const costs = [...series.buckets, series.range]
	const whole = series.range.total
	const percent = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 })

	/**
	 * @param {(cost: import('../services/api.js').CostSum) => number|null} read - the row's figure in a period
	 * @param {string} none - what a period with no rows says instead
	 * @return {string[]} the cells
	 */
	const cells = (read, none = '–') => {
		const share = read(series.range)

		return [
			...costs.map((cost) => {
				const cents = read(cost)

				return cents === null ? none : formatMoney(cents, series.currency, locale)
			}),
			// A range that cost nothing has no shares, which a division would call NaN.
			share === null || !whole ? '–' : percent.format(share / whole),
		]
	}

	// The range lists every category any of its buckets had, already in the server's order.
	const categories = (series.range.expenses ?? []).map(({ category }) => category)

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
	const notes = [t('nextfleet', 'An estimate: what was filled up or charged, times its emission factor')]
	const sources = [{
		label: co2.year === null ? t('nextfleet', 'Fuel factors') : t('nextfleet', 'Fuel factors {year}', { year: co2.year }),
		href: co2.source,
	}]
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
 * @param {import('../services/api.js').CostSum} cost - a period's
 * @param {Band} band - which share of it
 * @return {number|null} the band's cents, null when the period has no sums
 */
export function bandOf(cost, band) {
	if (band === 'expenses') {
		return cost.expenses === null ? null : cost.expenses.reduce((sum, one) => sum + one.total, 0)
	}

	return cost[band]
}
