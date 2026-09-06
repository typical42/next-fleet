/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

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
 * @param {import('../services/api.js').Vehicle} vehicle - the vehicle as it was read
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
 * A number as somebody typed it into the sheet. Both separators are accepted, because the sheet is
 * filled one-handed at a pump on whatever keyboard the phone offers (docs/ui.md).
 *
 * @param {string} input - what the field holds
 * @return {number|null} the number, or null when the field says nothing usable
 */
export function parseCount(input) {
	const cleaned = String(input).trim().replace(/\s/g, '').replace(',', '.')
	if (cleaned === '') {
		return null
	}

	const number = Number(cleaned)

	return Number.isFinite(number) ? number : null
}
