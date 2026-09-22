/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, t } from '@nextcloud/l10n'

import { energyWord, formatConsumption, formatCount, formatMoney, formatOdometer, formatWallSide } from './format.js'

/**
 * @typedef {object} Tile
 * @property {string} label - what the figure is
 * @property {string} figure - the figure, with its unit
 * @property {string|null} change - how it moved since the period before, or null when there is
 *   nothing to compare it with
 * @property {string[]} notes - what the figure includes or leaves out
 */

/**
 * The vehicle header, one tile per figure (docs/ui.md). Every figure but the odometer belongs to
 * the period, and is compared with the same figure of the period before it: a number without
 * context means nothing. A figure either period lacks gets no comparison rather than one against
 * zero.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - the vehicle as it was read
 * @param {import('../services/api.js').Kpis|null} now - the period's figures, null until read
 * @param {import('../services/api.js').Kpis|null} before - the period before's, null until read
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Tile[]} the tiles, in reading order
 */
export function tilesOf(vehicle, now, before, locale = getCanonicalLocale()) {
	/** @type {Tile[]} */
	const tiles = [{
		label: t('nextfleet', 'Odometer'),
		figure: formatOdometer(vehicle, locale) || t('nextfleet', 'Never read'),
		change: null,
		notes: [],
	}]
	if (now === null) {
		return tiles
	}

	// One per energy and never a blend: a litre and a kilowatt-hour do not add up (docs/ui.md).
	for (const consumption of now.consumption) {
		/** @type {(value: number) => string} */
		const write = (value) => formatConsumption({ value, per: consumption.per }, consumption.energy, locale)
		tiles.push({
			label: t('nextfleet', '{energy} consumption', { energy: { value: energyWord(consumption.energy), escape: false } }),
			figure: write(consumption.value),
			change: changeOf(consumption.value, before?.consumption.find((one) => one.energy === consumption.energy && one.per === consumption.per)?.value, write),
			notes: [],
		})
	}

	if (now.wall_side !== null && (vehicle.energy_types ?? []).includes('electric')) {
		const per = now.wall_side.per
		const wall = formatWallSide(now.wall_side, locale)
		tiles.push({
			label: t('nextfleet', 'Electric, at the charger'),
			figure: wall.figure,
			change: changeOf(now.wall_side.value, before?.wall_side?.value, (value) => formatConsumption({ value, per }, 'electric', locale)),
			notes: [wall.note],
		})
	}

	tiles.push(...costTiles(now.cost, before?.cost ?? null, locale))

	if (vehicle.second_unit && now.hours !== null) {
		/** @type {(hours: number) => string} */
		const write = (hours) => `${formatCount(hours, locale)} h`
		tiles.push({
			label: t('nextfleet', 'Engine hours in the period'),
			figure: write(now.hours),
			change: changeOf(now.hours, before?.hours, write),
			// The fuel a truck burns while its crane runs is in the kilometre figure, and no counter
			// tells the two apart (docs/architecture.md#numbers-consumption-cost-emissions).
			notes: [t('nextfleet', 'Consumption includes fuel used while working')],
		})
	}

	return tiles
}

/**
 * Cost, energy-only cost and TCO. Per 100 km or per hour when the period has a distance, as a
 * period total when it has none; and one "no currency" tile in place of all of them on a vehicle
 * whose costs have no currency to be summed in.
 *
 * @param {import('../services/api.js').Cost} cost - the period's
 * @param {import('../services/api.js').Cost|null} before - the period before's
 * @param {string} locale - the reader's
 * @return {Tile[]} the tiles
 */
function costTiles(cost, before, locale) {
	if (cost.currency === null) {
		return [{ label: t('nextfleet', 'Cost'), figure: t('nextfleet', 'No currency'), change: null, notes: [] }]
	}

	const notes = [
		...(cost.net ? [t('nextfleet', 'Net of VAT')] : []),
		...(cost.incomplete ? [t('nextfleet', 'Incomplete: a fill-up in the period has no price')] : []),
		...(cost.unstated ? [t('nextfleet', 'Rows without a VAT rate count gross')] : []),
	]
	const perDistance = cost.distance !== null
	/** @type {(cents: number) => string} */
	const write = perDistance
		? (cents) => `${formatMoney(cents, cost.currency, locale)}${cost.per === 'h' ? '/h' : '/100 km'}`
		: (cents) => formatMoney(cents, cost.currency, locale)
	// A period before without a distance states a total, and a total is no rate to compare with.
	const comparable = before !== null && before.currency === cost.currency && before.per === cost.per && (before.distance !== null) === perDistance

	/**
	 * @param {string} label - what the figure is
	 * @param {'total'|'energy'|'value'|'energy_value'|'tco'} field - which of the cost's sums
	 * @param {string[]} notes - what it leaves out
	 * @return {Tile} the tile
	 */
	const tile = (label, field, notes) => ({
		label,
		// A period that recorded nothing has no sums. It reads as zero, but a swing to or from a
		// period with no figure says nothing (docs/architecture.md#numbers-consumption-cost-emissions).
		figure: write(cost[field] ?? 0),
		change: comparable && cost[field] !== null ? changeOf(cost[field], before[field], write) : null,
		notes,
	})

	if (!perDistance) {
		return [
			tile(t('nextfleet', 'Cost in the period'), 'total', notes),
			tile(t('nextfleet', 'Energy cost in the period'), 'energy', notes),
		]
	}

	return [
		tile(t('nextfleet', 'Cost'), 'value', notes),
		tile(t('nextfleet', 'Energy cost'), 'energy_value', notes),
		...(cost.tco === null ? [] : [tile(t('nextfleet', 'TCO'), 'tco', [])]),
	]
}

/**
 * How a figure moved, signed in words a screen reader says and a print keeps: never a colour
 * (docs/ui.md). A move too small to show at the figure's precision is no move.
 *
 * @param {number} now - the period's figure
 * @param {number|null|undefined} before - the same figure of the period before
 * @param {(value: number) => string} write - how the figure is written
 * @return {string|null} the comparison, or null when there is nothing to compare with
 */
function changeOf(now, before, write) {
	if (before === null || before === undefined) {
		return null
	}

	const size = write(Math.abs(now - before))
	const sign = size === write(0) ? '±' : (now > before ? '+' : '−')

	return t('nextfleet', '{change} vs. the period before', { change: { value: `${sign}${size}`, escape: false } })
}
