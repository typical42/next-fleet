/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { stacksOf } from './costs.js'
import { CATEGORIES, categoryWord, formatCount } from './format.js'
import { t } from './l10n.js'
import { distanceOf } from './trends.js'

/**
 * The trip categories, bottom up in the bars.
 *
 * @typedef {'business'|'private'|'commute'} Category
 */

export const TRIP_BANDS = /** @type {Category[]} */ (CATEGORIES)

/**
 * One stacked bar per bucket, scaled to the longest bucket.
 *
 * @param {import('../services/api.js').DistanceSeries} series - one unit's, as the route answers it
 * @return {import('./costs.js').Bar<Category>[]} one per bucket, in order
 */
export function tripBarsOf(series) {
	const stacks = stacksOf(series.buckets.map((sum) => TRIP_BANDS.map((band) => sum[band])), TRIP_BANDS)

	return series.buckets.map((sum, i) => ({ empty: sum.trips === 0, bands: stacks[i] }))
}

/**
 * Each bar's title: its bucket and how far it went.
 *
 * @param {import('../services/api.js').DistanceSeries} series - one unit's, as the route answers it
 * @param {string[]} labels - one per bucket (labelsOf())
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string[]} one per bucket
 */
export function tripTitlesOf(series, labels, locale = getCanonicalLocale()) {
	return series.buckets.map((sum, i) => `${labels[i]}: ${sum.trips === 0 ? t('nextfleet', 'No entries') : distanceOf(totalOf(sum), series.per, locale)}`)
}

/**
 * The table beneath the trip bars: buckets across, then the whole period and each category's share
 * of it; the categories down, then their total. A bucket without trips has no distance, which is
 * not a zero.
 *
 * @param {import('../services/api.js').DistanceSeries} series - one unit's, as the route answers it
 * @param {string[]} labels - one per bucket (labelsOf())
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {import('./trends.js').Table} the table
 */
export function tripTableOf(series, labels, locale = getCanonicalLocale()) {
	const periods = [...series.buckets, series.range]
	const whole = totalOf(series.range)
	const percent = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 })

	/**
	 * @param {(sum: import('../services/api.js').TripDistance) => number} read - the row's distance
	 * @param {string} none - what a period without trips says instead
	 * @param {boolean} share - whether the row has a share of the whole
	 * @return {string[]} the cells
	 */
	const cells = (read, none, share) => [
		...periods.map((sum) => sum.trips === 0 ? none : distanceOf(read(sum), series.per, locale)),
		// A range of no distance has no shares, which a division would call NaN.
		share && whole > 0 ? percent.format(read(series.range) / whole) : '–',
	]

	return {
		columns: [...labels, t('nextfleet', 'Whole period'), t('nextfleet', 'Share')],
		rows: [
			...TRIP_BANDS.map((band) => ({ key: band, label: categoryWord(band), cells: cells((sum) => sum[band], '–', true) })),
			{ key: 'total', label: t('nextfleet', 'Total'), cells: cells(totalOf, t('nextfleet', 'No entries'), false), total: true },
		],
	}
}

/**
 * Each driver's range: how many trips, how far, and what share of it was business.
 *
 * @param {import('../services/api.js').DriverSeries} series - one unit's, as the route answers it
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {import('./trends.js').Table} the table
 */
export function driversTableOf(series, locale = getCanonicalLocale()) {
	const percent = new Intl.NumberFormat(locale, { style: 'percent', maximumFractionDigits: 0 })

	return {
		columns: [t('nextfleet', 'Trips'), t('nextfleet', 'Distance'), t('nextfleet', 'Business share')],
		rows: series.drivers.map((driver) => ({
			key: driver.user_id,
			label: driver.name,
			cells: [
				formatCount(driver.trips, locale),
				distanceOf(driver.distance, series.per, locale),
				driver.distance > 0 ? percent.format(driver.business / driver.distance) : '–',
			],
		})),
	}
}

/**
 * @param {import('../services/api.js').TripDistance} sum - a period's
 * @return {number} every category's distance
 */
function totalOf(sum) {
	return TRIP_BANDS.reduce((total, band) => total + sum[band], 0)
}
