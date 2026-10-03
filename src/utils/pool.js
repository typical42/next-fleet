/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'

import { formatSpan, formatWhen } from './format.js'

/**
 * Who has the car, as the overview row and the vehicle header say it (docs/ui.md), or null while
 * it is not out. Past its end it is overdue: nobody has given it back. The words go into text
 * Vue escapes, so the names are not escaped twice.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - as listed, with `out_with`
 * @return {{words: string, overdue: boolean}|null} the line, and whether it is a warning
 */
export function holderWords(vehicle) {
	const out = vehicle.out_with
	if (!out) {
		return null
	}
	const when = { value: formatWhen(out.ends_at, out.ends_at_off), escape: false }
	const name = { value: out.user_name, escape: false }
	const overdue = out.ends_at * 1000 <= Date.now()
	const yours = out.user_id === getCurrentUser()?.uid
	let words
	if (overdue) {
		words = yours
			? t('nextfleet', 'With you, overdue since {when}', { when })
			: t('nextfleet', 'With {name}, overdue since {when}', { name, when })
	} else {
		words = yours
			? t('nextfleet', 'With you until {when}', { when })
			: t('nextfleet', 'With {name} until {when}', { name, when })
	}

	return { words, overdue }
}

/**
 * The reader's own next booking of the car within a week, or null.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - as listed, with `my_next_booking`
 * @return {string|null} the line
 */
export function nextBookingWords(vehicle) {
	const next = vehicle.my_next_booking
	if (!next) {
		return null
	}
	const span = { value: formatSpan(next.starts_at, next.starts_at_off, next.ends_at, next.ends_at_off), escape: false }

	return t('nextfleet', 'Your booking: {span}', { span })
}
