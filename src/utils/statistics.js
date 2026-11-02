/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from './l10n.js'

/** The ranges the Statistics screen offers, the default first (docs/ui.md). */
export const RANGES = ['year', 'last-12', 'all', 'between']

/**
 * @param {string} range - one of RANGES
 * @return {string} what the picker calls it
 */
export function rangeWord(range) {
	return {
		year: t('nextfleet', 'Calendar year'),
		'last-12': t('nextfleet', 'Last 12 months'),
		all: t('nextfleet', 'All time'),
		between: t('nextfleet', 'Between two days'),
	}[range] ?? range
}

/**
 * A range as the route takes it: its first day and the day after its last, read on the reader's
 * calendar; the server cuts them at the reader's midnight (docs/api.md#statistics).
 *
 * @param {string} range - one of RANGES
 * @param {Date} now - the moment the screen is read
 * @param {object} chosen - what the screen picked beside the range
 * @param {number} [chosen.year] - the calendar year's
 * @param {Date|null} [chosen.from] - the first of two days
 * @param {Date|null} [chosen.to] - the last of them, which counts
 * @return {{from?: string, to?: string}|null} neither end for all of it; null while two days are
 *   not a range
 */
export function daysOf(range, now, { year = now.getFullYear(), from = null, to = null }) {
	switch (range) {
	case 'year':
		return { from: day(year, 0, 1), to: day(year + 1, 0, 1) }
	case 'last-12':
		return { from: day(now.getFullYear(), now.getMonth() - 11, 1), to: day(now.getFullYear(), now.getMonth() + 1, 1) }
	case 'between':
		if (from === null || to === null || to < from) {
			return null
		}
		return { from: day(from.getFullYear(), from.getMonth(), from.getDate()), to: day(to.getFullYear(), to.getMonth(), to.getDate() + 1) }
	default:
		return {}
	}
}

/**
 * @param {number} year - the year
 * @param {number} month - from 0; one past either end rolls over, as `new Date()` does
 * @param {number} date - the day of the month
 * @return {string} the day as YYYY-MM-DD
 */
function day(year, month, date) {
	const at = new Date(year, month, date)

	return `${at.getFullYear()}-${String(at.getMonth() + 1).padStart(2, '0')}-${String(at.getDate()).padStart(2, '0')}`
}
