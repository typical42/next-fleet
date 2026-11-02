/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { energyWord, formatConsumption, formatCount, formatEnergyAmount, formatMoney } from './format.js'
import { t } from './l10n.js'

/**
 * A table as the Statistics screen sets it: column heads, then rows each headed by their label.
 *
 * @typedef {object} Table
 * @property {string[]} columns - the heads of the figures' columns
 * @property {{key: string, label: string, cells: string[], total?: boolean}[]} rows - one cell per
 *   column; a `total` stands for the rows above it
 */

/**
 * @param {'km'|'h'} per - the unit the counters count
 * @return {string} what a rate in it is called
 */
export function rateWord(per) {
	return per === 'h' ? t('nextfleet', 'Cost per hour') : t('nextfleet', 'Cost per 100 km')
}

/**
 * Whether a period's rate leaves out some of its costs: the server rates only what had a distance,
 * but adds up everything (docs/api.md#statistics). Half a cent is the rounding a written figure
 * hides.
 *
 * @param {import('../services/api.js').PerDistance} series - one currency's and unit's
 * @return {boolean} whether some period's rate leaves a cost out
 */
export function bentOf(series) {
	const scale = series.per === 'h' ? 1 : 100

	return [...series.buckets, series.range].some(({ total, distance, value }) => total !== null
		&& (distance === null || value === null || Math.abs(total * scale / distance - value) > 0.5))
}

/**
 * The table beneath the cost-per-distance line: buckets across, then the whole period, with the
 * costs and the distance the rate is read from.
 *
 * @param {import('../services/api.js').PerDistance} series - one currency's and unit's
 * @param {string[]} labels - one per bucket (labelsOf())
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Table} the table
 */
export function rateTableOf(series, labels, locale = getCanonicalLocale()) {
	const periods = [...series.buckets, series.range]

	return {
		columns: [...labels, t('nextfleet', 'Whole period')],
		rows: rateRows(series, locale).map(({ key, label, cell }) => ({ key, label, cells: periods.map(cell) })),
	}
}

/**
 * The vehicles side by side over the whole period, the fleet beneath them: the every-vehicle view
 * draws only the fleet's line.
 *
 * @param {import('../services/api.js').PerDistance} series - one currency's and unit's
 * @param {Record<string, string>} names - each vehicle's, by its uuid
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Table} the table
 */
export function vehiclesTableOf(series, names, locale = getCanonicalLocale()) {
	const figures = rateRows(series, locale)

	return {
		columns: figures.map(({ label }) => label),
		rows: [
			...series.vehicles.map(({ uuid, range }) => ({ key: uuid, label: names[uuid] ?? uuid, cells: figures.map(({ cell }) => cell(range)) })),
			{ key: '', label: t('nextfleet', 'All vehicles'), cells: figures.map(({ cell }) => cell(series.range)), total: true },
		],
	}
}

/**
 * The table beneath one vehicle's consumption line: a row per segment, headed by the day the
 * fill-up that closed it was made.
 *
 * @param {import('../services/api.js').ConsumptionLine} line - one energy's
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @param {string} [timeZone] - the zone the range was asked in; defaults to the reader's
 * @return {Table} the table
 */
export function segmentsTableOf(line, locale = getCanonicalLocale(), timeZone = undefined) {
	const day = new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone })

	return {
		columns: [t('nextfleet', 'Distance'), t('nextfleet', 'Amount'), t('nextfleet', 'Consumption')],
		rows: (line.segments ?? []).map((segment) => ({
			key: segment.closes,
			label: day.format(segment.filled_at * 1000),
			cells: [distanceOf(segment.distance, segment.per, locale), formatEnergyAmount(segment.amount, line.energy, locale), formatConsumption(segment, line.energy, locale)],
		})),
	}
}

/**
 * Each vehicle's average per energy, in the every-vehicle view, which draws no line: segments of
 * many vehicles in one drawing would read as one.
 *
 * @param {import('../services/api.js').ConsumptionLine[]} lines - as the route answers them
 * @param {Record<string, string>} names - each vehicle's, by its uuid
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Table} the table
 */
export function averagesTableOf(lines, names, locale = getCanonicalLocale()) {
	return {
		columns: [t('nextfleet', 'Energy'), t('nextfleet', 'Distance'), t('nextfleet', 'Average')],
		rows: lines.map((line) => ({
			key: `${line.uuid}:${line.energy}`,
			label: names[line.uuid] ?? line.uuid,
			cells: [energyWord(line.energy), distanceOf(line.distance, line.per, locale), formatConsumption(line, line.energy, locale)],
		})),
	}
}

/**
 * @param {number} distance - how far a counter moved
 * @param {'km'|'h'} per - what it counts
 * @param {string} locale - the reader's
 * @return {string} the distance with its unit
 */
export function distanceOf(distance, per, locale) {
	return `${formatCount(distance, locale)} ${per}`
}

/**
 * The three figures of a DistanceCost, each written out.
 *
 * @param {import('../services/api.js').PerDistance} series - what the figures are in
 * @param {string} locale - the reader's
 * @return {{key: 'total'|'distance'|'value', label: string, cell: (cost: import('../services/api.js').DistanceCost) => string}[]} in reading order
 */
function rateRows(series, locale) {
	/**
	 * @param {number|null} cents - a sum, or a rate in cents
	 * @return {string} the money, or a dash for none
	 */
	const money = (cents) => cents === null ? '–' : formatMoney(Math.round(cents), series.currency, locale)

	return [
		{ key: 'total', label: t('nextfleet', 'Costs'), cell: (cost) => money(cost.total) },
		{ key: 'distance', label: t('nextfleet', 'Distance'), cell: (cost) => cost.distance === null ? '–' : distanceOf(cost.distance, series.per, locale) },
		{ key: 'value', label: rateWord(series.per), cell: (cost) => money(cost.value) },
	]
}

/**
 * A line over the buckets, in the bars' drawing box: ten wide per bucket, a hundred high. A bucket
 * without a figure breaks the line, since joining across it would draw a figure nobody measured.
 *
 * @param {(number|null)[]} values - one per bucket
 * @return {{i: number, x: number, y: number}[][]} the unbroken runs, each point at its bucket's
 *   middle, `i` its index in `values`
 */
export function lineOf(values) {
	const highest = Math.max(0, ...values.filter((value) => value !== null))
	/** @type {{i: number, x: number, y: number}[][]} */
	const runs = []
	/** @type {{i: number, x: number, y: number}[]|null} */
	let run = null
	values.forEach((value, i) => {
		if (value === null) {
			run = null
			return
		}
		if (run === null) {
			run = []
			runs.push(run)
		}
		run.push({ i, x: i * 10 + 5, y: highest > 0 ? 100 - value / highest * 100 : 100 })
	})

	return runs
}
