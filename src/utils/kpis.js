/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { energyWord, formatConsumption, formatCount, formatMoney, formatOdometer, formatWallSide } from './format.js'
import { t } from './l10n.js'

/**
 * @typedef {object} Tile
 * @property {string} label - what the figure is
 * @property {string} figure - the figure, with its unit
 * @property {string|null} change - how it moved since the period before, or null when there is
 *   nothing to compare it with
 * @property {string[]} notes - what the figure includes or leaves out
 * @property {true} [hint] - the figure is a sentence saying what the figure needs
 */

/**
 * The vehicle header, one tile per figure (docs/ui.md). Every figure but the odometer belongs to
 * the period and is compared with the period before: a number without context means nothing. A
 * figure either period lacks gets no comparison rather than one against zero.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - the vehicle as it was read
 * @param {import('../services/api.js').Kpis|null} now - the period's figures, null until read
 * @param {import('../services/api.js').Kpis|null} before - the period before's, null until read
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Tile[]} the tiles, in reading order
 */
export function tilesOf(vehicle, now, before, locale = getCanonicalLocale()) {
	return [
		{
			label: t('nextfleet', 'Odometer'),
			figure: formatOdometer(vehicle, locale) || t('nextfleet', 'Never read'),
			change: null,
			notes: [],
		},
		...(now === null ? [] : periodTilesOf(vehicle, now, before, locale)),
	]
}

/**
 * The header's figures but the odometer, which belongs to no period. The Costs screen states them
 * for its year.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - the vehicle as it was read
 * @param {import('../services/api.js').Kpis} now - the period's figures
 * @param {import('../services/api.js').Kpis|null} before - the period before's, null for no comparison
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {Tile[]} the tiles, in reading order
 */
export function periodTilesOf(vehicle, now, before, locale = getCanonicalLocale()) {
	/** @type {Tile[]} */
	const tiles = []

	/**
	 * @param {string} label - what the figure is
	 * @param {import('../services/api.js').PeriodConsumption} consumption - one energy's figure
	 * @param {import('../services/api.js').PeriodConsumption[]|null|undefined} earlier - the same
	 *   kind of figure for the period before
	 * @param {string[]} notes - what it includes
	 * @return {Tile} the tile
	 */
	const consumptionTile = (label, consumption, earlier, notes) => {
		/** @type {(value: number) => string} */
		const write = (value) => formatConsumption({ value, per: consumption.per }, consumption.energy, locale)
		return {
			label,
			figure: write(consumption.value),
			change: changeOf(consumption.value, earlier?.find((one) => one.energy === consumption.energy && one.per === consumption.per)?.value, write),
			notes,
		}
	}

	// One per energy and never a blend: a litre and a kilowatt-hour do not add up (docs/ui.md). An
	// energy's figure per engine hour follows its figure per 100 km; either may be missing, as a
	// segment counts per hour only when both its fill-ups carry engine hours. Gated on the vehicle
	// as the engine-hours tile is, so an answer read before the hours were switched off shows none.
	const perHour = vehicle.second_unit ? now.consumption_per_hour ?? [] : []
	// A hint stands in for a figure the data can never give yet; a period that closed no segment
	// of an energy measured before just has no figure. Electricity is rarely charged full, so its
	// figure is the charger's, which any period with a charge gives: full tanks are not what it
	// needs (docs/architecture.md#numbers-consumption-cost-emissions).
	const measuredPerHour = vehicle.second_unit ? now.measured_per_hour : null
	const neverMeasured = (vehicle.energy_types ?? []).filter((energy) => energy !== 'electric'
		&& !now.measured.includes(energy)
		&& !(measuredPerHour ?? []).includes(energy))
	// Only beside the period's figure per 100 km, which says the fill-ups lack nothing but the hours.
	const neverPerHour = measuredPerHour === null ? [] : now.consumption.map((one) => one.energy).filter((energy) => !measuredPerHour.includes(energy))
	const energies = [...new Set([...now.consumption, ...perHour].map((one) => one.energy).concat(neverMeasured))]
	for (const energy of energies) {
		const word = energyWord(energy)
		const label = t('nextfleet', '{energy} consumption', { energy: word })
		const distance = now.consumption.find((one) => one.energy === energy)
		if (distance) {
			tiles.push(consumptionTile(label, distance, before?.consumption, []))
		} else if (neverMeasured.includes(energy)) {
			tiles.push(hint(label, t('nextfleet', 'Needs two full fill-ups')))
		}
		const perHourLabel = t('nextfleet', '{energy} consumption per engine hour', { energy: word })
		const hours = perHour.find((one) => one.energy === energy)
		if (hours) {
			// The mirror of the engine-hours tile's note: no counter tells driving from working.
			tiles.push(consumptionTile(perHourLabel, hours, before?.consumption_per_hour, [t('nextfleet', 'Includes fuel used while driving')]))
		} else if (neverPerHour.includes(energy)) {
			tiles.push(hint(perHourLabel, t('nextfleet', 'Enter engine hours on full fill-ups')))
		}
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

	tiles.push(...costTiles(now.cost, before?.cost ?? null, Boolean(vehicle.second_unit), locale))

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
 * The figures without those per engine hour, for a screen that states a period per 100 km alone.
 *
 * @param {import('../services/api.js').Kpis} figures - the period's
 * @return {import('../services/api.js').Kpis} the same, per 100 km
 */
export function perDistanceOnly(figures) {
	return {
		...figures,
		consumption_per_hour: null,
		// An energy measured per engine hour alone has its full fill-ups; it is not one to ask them for.
		measured: [...new Set([...figures.measured, ...(figures.measured_per_hour ?? [])])],
		measured_per_hour: null,
		cost: { ...figures.cost, hours: null },
	}
}

/**
 * Cost, energy-only cost and TCO. Per 100 km or per hour when the period has a distance, as a
 * period total when it has none; and one "no currency" tile in place of all of them on a vehicle
 * whose costs have no currency to be summed in. Cost and energy cost per engine hour follow their
 * tiles where the period has engine hours.
 *
 * @param {import('../services/api.js').Cost} cost - the period's
 * @param {import('../services/api.js').Cost|null} before - the period before's
 * @param {boolean} engineHours - whether the vehicle counts them, as an older answer may not know
 * @param {string} locale - the reader's
 * @return {Tile[]} the tiles
 */
function costTiles(cost, before, engineHours, locale) {
	if (cost.currency === null) {
		return [{ label: t('nextfleet', 'Cost'), figure: t('nextfleet', 'No currency'), change: null, notes: [] }]
	}

	const notes = [
		...(cost.net ? [t('nextfleet', 'Net of VAT')] : []),
		...(cost.incomplete ? [t('nextfleet', 'Incomplete: a fill-up in the period has no price')] : []),
		...(cost.unstated ? [t('nextfleet', 'Entries without a VAT rate count gross')] : []),
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
	 * @param {'total'|'energy'|'value'|'energy_value'|'tco'|'value_per_hour'|'energy_value_per_hour'} field - which of the cost's sums
	 * @param {string[]} notes - what it leaves out
	 * @param {(cents: number) => string} [writing] - how the figure is written
	 * @param {boolean} [compares] - whether the period before has the same kind of figure
	 * @return {Tile} the tile
	 */
	const tile = (label, field, notes, writing = write, compares = comparable) => ({
		label,
		// No sums read as zero, but a swing to or from a period without a figure says nothing
		// (docs/architecture.md#numbers-consumption-cost-emissions).
		figure: writing(cost[field] ?? 0),
		change: compares && cost[field] !== null ? changeOf(cost[field], before?.[field], writing) : null,
		notes,
	})

	// Per engine hour where the period has them, as the rates above are per distance where it has one.
	const hours = engineHours && cost.hours !== null
	/** @type {(cents: number) => string} */
	const writePerHour = (cents) => `${formatMoney(cents, cost.currency, locale)}/h`
	const comparablePerHour = before !== null && before.currency === cost.currency && before.hours !== null
	/**
	 * @param {string} label - what the figure is
	 * @param {'value_per_hour'|'energy_value_per_hour'} field - which of the cost's sums
	 * @return {Tile[]} the tile, or none when the period has no engine hours
	 */
	const perHour = (label, field) => hours ? [tile(label, field, notes, writePerHour, comparablePerHour)] : []

	if (!perDistance) {
		return [
			tile(t('nextfleet', 'Cost in the period'), 'total', notes),
			...perHour(t('nextfleet', 'Cost per engine hour'), 'value_per_hour'),
			tile(t('nextfleet', 'Energy cost in the period'), 'energy', notes),
			...perHour(t('nextfleet', 'Energy cost per engine hour'), 'energy_value_per_hour'),
		]
	}

	return [
		tile(t('nextfleet', 'Cost'), 'value', notes),
		...perHour(t('nextfleet', 'Cost per engine hour'), 'value_per_hour'),
		tile(t('nextfleet', 'Energy cost'), 'energy_value', notes),
		...perHour(t('nextfleet', 'Energy cost per engine hour'), 'energy_value_per_hour'),
		...(cost.tco === null ? [] : [tile(t('nextfleet', 'Total cost of ownership'), 'tco', [])]),
	]
}

/**
 * A tile that says what its figure needs, in the figure's place.
 *
 * @param {string} label - the figure it stands in for
 * @param {string} what - what the figure needs
 * @return {Tile} the tile
 */
function hint(label, what) {
	return { label, figure: what, change: null, notes: [], hint: true }
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

	return t('nextfleet', '{change} vs. the period before', { change: `${sign}${size}` })
}
