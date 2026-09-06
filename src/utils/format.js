/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, t } from '@nextcloud/l10n'

/**
 * A grouped whole number, in any locale: at most three digits, then groups of exactly three. `\s`
 * is what covers the spaces Intl groups with - it matches the no-break and narrow no-break ones
 * as well as the one a keyboard gives.
 */
const GROUPED = /^\d{1,3}(?:[.,\s]\d{3})+$/

/**
 * What to call a vehicle on screen. The plate is the label a driver uses, and only a label: a
 * vehicle without one is still a vehicle, identified by its uuid (CONTEXT.md).
 *
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string} its plate, its make and model, or a stand-in
 */
export function nameOf(vehicle) {
	return vehicle.plate || madeOf(vehicle) || t('nextfleet', 'Unnamed vehicle')
}

/**
 * The line under the name, which says only what the name did not. A lifecycle appears there when
 * it is not the ordinary one - that is what explains a vehicle sinking down the overview
 * (docs/ui.md).
 *
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string} the make, the lifecycle, both, or nothing
 */
export function subtitleOf(vehicle) {
	const made = madeOf(vehicle)
	const parts = [vehicle.plate ? made : '']
	if (vehicle.lifecycle && vehicle.lifecycle !== 'active') {
		parts.push(lifecycleWord(vehicle.lifecycle))
	}

	return parts.filter(Boolean).join(' · ')
}

/**
 * A lifecycle is a code in the database and a word on screen (docs/ui.md#languages). Translated
 * on call, never at import: the catalogue is registered by the page, not by this module.
 *
 * @param {string} lifecycle - `active`, `laid_up` or `disposed` (CONTEXT.md)
 * @return {string} the word for it
 */
export function lifecycleWord(lifecycle) {
	/** @type {Record<string, string>} */
	const words = {
		active: t('nextfleet', 'Active'),
		laid_up: t('nextfleet', 'Laid up'),
		disposed: t('nextfleet', 'Disposed of'),
	}

	return words[lifecycle] ?? lifecycle
}

/**
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string} manufacturer and model, as much of the two as the vehicle carries
 */
function madeOf(vehicle) {
	return [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' ')
}

/**
 * A count as the reader's locale writes it. Nothing here is hand-rolled: separators, grouping and
 * the digits themselves belong to the locale and not to the language (docs/ui.md#languages).
 *
 * @param {number} value - the whole number to write out
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the number, grouped
 */
export function formatCount(value, locale = getCanonicalLocale()) {
	return new Intl.NumberFormat(locale).format(value)
}

/**
 * A vehicle's counter as the overview shows it. The unit is the vehicle's own `odo_unit`, so a
 * generator counted in hours never borrows a car's kilometres (docs/ui.md).
 *
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the counter with its unit, or nothing when the vehicle has never been read
 */
export function formatOdometer(vehicle, locale = getCanonicalLocale()) {
	if (vehicle.odo_value === null || vehicle.odo_value === undefined) {
		return ''
	}

	return `${formatCount(vehicle.odo_value, locale)} ${vehicle.odo_unit}`
}

/**
 * A counter as somebody typed it into the sheet. It reads in whole kilometres or whole hours
 * (docs/architecture.md#data-model), so a dot, comma or space in it groups thousands - which is
 * what the app itself wrote out a moment earlier, `148.320` in German and `148,320` in English
 * (docs/ui.md#languages). Grouping is what separates three digits and nothing else, so `7,2` is
 * not a counter: it is a question for the driver rather than a number to round.
 *
 * A decimal parser belongs to the sheet that has a decimal field; litres arrive with M2.
 *
 * @param {string} input - what the field holds
 * @return {number|null} the counter, or null when the field says nothing usable
 */
export function parseWhole(input) {
	const typed = String(input).trim()
	if (/^\d+$/.test(typed)) {
		return Number(typed)
	}

	return GROUPED.test(typed) ? Number(typed.replace(/\D/g, '')) : null
}
