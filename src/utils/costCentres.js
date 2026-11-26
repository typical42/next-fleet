/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { formatCount, formatMoney } from './format.js'
import { t } from './l10n.js'
import { distanceOf } from './trends.js'

/**
 * @param {import('../services/api.js').CostCentre} centre - a record
 * @return {string} it as a list and a dropdown name it
 */
export function centreWord(centre) {
	const named = codeAndName(centre.code, centre.name)

	return centre.archived ? t('nextfleet', '{name} (archived)', { name: named }) : named
}

/**
 * @param {string|null} code - the record's
 * @param {string} name - the record's
 * @return {string} both as the list shows them, such as "4711 Sales"
 */
function codeAndName(code, name) {
	return code === null ? name : `${code} ${name}`
}

/**
 * The Statistics screen's "By cost centre": a row per cost centre, its trips and distance per unit,
 * then its costs per currency. Every row of the answer holds the same units and currencies.
 *
 * @param {import('../services/api.js').CostCentreSum[]} sums - as the route answers them
 * @param {string} [locale] - the reader's
 * @return {{columns: string[], rows: {key: string, label: string, cells: string[]}[]}} the table
 */
export function centresTableOf(sums, locale = getCanonicalLocale()) {
	const units = sums[0]?.distance.map(({ per }) => per) ?? []
	const currencies = sums[0]?.costs.map(({ currency }) => currency) ?? []
	// One unit needs no name in the head; kilometres and hours side by side do.
	const trips = (/** @type {'km'|'h'} */ per) => units.length === 1
		? t('nextfleet', 'Trips')
		: (per === 'h' ? t('nextfleet', 'Trips (hours)') : t('nextfleet', 'Trips (km)'))

	return {
		columns: [
			...units.flatMap((per) => [trips(per), per === 'h' ? t('nextfleet', 'Hours') : t('nextfleet', 'Distance')]),
			...currencies.map((currency) => t('nextfleet', 'Costs in {currency}', { currency })),
		],
		rows: sums.map((sum) => ({
			key: sum.uuid ?? '',
			label: sum.uuid === null ? t('nextfleet', 'No cost centre') : codeAndName(sum.code, sum.name ?? ''),
			cells: [
				...sum.distance.flatMap(({ per, trips, distance }) => [formatCount(trips, locale), distanceOf(distance, per, locale)]),
				...sum.costs.map(({ currency, total }) => formatMoney(total, currency, locale)),
			],
		})),
	}
}

/**
 * What a sheet offers: the vehicle owner's list, as an entry names one of it (docs/api.md#cost-centres).
 * An archived record is no longer offered, but the one the row already names stays, so the
 * dropdown shows what a save writes back.
 *
 * @param {import('../services/api.js').CostCentre[]} list - every list the session reads
 * @param {string|undefined} owner - the vehicle owner
 * @param {string|null} keep - the uuid the row names now, or null
 * @return {{id: string, label: string}[]} the options, in the list's order
 */
export function centreOptions(list, owner, keep) {
	return list
		.filter((one) => one.user_id === owner && (!one.archived || one.uuid === keep))
		.map((one) => ({ id: one.uuid, label: centreWord(one) }))
}
