/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

/** The periods the vehicle header offers, the default first (docs/ui.md). */
export const PERIODS = ['last-12', 'this-year', 'last-year', 'month']

/**
 * @param {string} period - one of PERIODS
 * @return {string} what the picker calls it
 */
export function periodWord(period) {
	return {
		'last-12': t('nextfleet', 'Last 12 months'),
		'this-year': t('nextfleet', 'This year'),
		'last-year': t('nextfleet', 'Last year'),
		month: t('nextfleet', 'One month'),
	}[period] ?? period
}

/**
 * A period as the header reads it: `[from, to)` in unix seconds between the browser's local
 * midnights, since a year starts where the person is. `before` is the period the figures are
 * compared with, of the same length and directly before it: a year back for the yearly ones, a
 * month back for a month.
 *
 * @param {string} period - one of PERIODS
 * @param {Date} now - the moment the header is read
 * @param {string|null} [month] - `YYYY-MM` for the month period; this month when not given
 * @return {{from: number, to: number, before: {from: number, to: number}}}
 */
export function periodOf(period, now, month = null) {
	const year = now.getFullYear()
	const tomorrow = [year, now.getMonth(), now.getDate() + 1]
	/** @type {[number[], number[], number]} start, end and step in months */
	const [start, end, step] = {
		'last-12': [[year - 1, now.getMonth(), now.getDate() + 1], tomorrow, 12],
		'this-year': [[year, 0, 1], tomorrow, 12],
		'last-year': [[year - 1, 0, 1], [year, 0, 1], 12],
	}[period] ?? monthOf(month, now)

	const instant = ([y, m, d], back = 0) => new Date(y, m - back, d).getTime() / 1000

	return {
		from: instant(start),
		to: instant(end),
		before: { from: instant(start, step), to: instant(end, step) },
	}
}

/**
 * @param {string|null} month - `YYYY-MM`, or null for the month `now` is in
 * @param {Date} now - the moment the header is read
 * @return {[number[], number[], number]} its first day, the next month's, and a one-month step
 */
function monthOf(month, now) {
	const [year, index] = month ? [Number(month.slice(0, 4)), Number(month.slice(5, 7)) - 1] : [now.getFullYear(), now.getMonth()]

	return [[year, index, 1], [year, index + 1, 1], 1]
}
