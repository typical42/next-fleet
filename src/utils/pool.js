/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCurrentUser } from '@nextcloud/auth'

import { formatCount, formatSpan, formatWhen } from './format.js'
import { t } from './l10n.js'

/**
 * Who has the car, as the overview row and the vehicle header say it (docs/ui.md), or null while
 * it is not out. Past its end it is overdue: nobody has given it back.
 *
 * @param {import('../services/api.js').Vehicle} vehicle - as listed, with `out_with`
 * @return {{words: string, overdue: boolean}|null} the line, and whether it is a warning
 */
export function holderWords(vehicle) {
	const out = vehicle.out_with
	if (!out) {
		return null
	}
	const when = formatWhen(out.ends_at, out.ends_at_off)
	const name = out.user_name
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
	const span = formatSpan(next.starts_at, next.starts_at_off, next.ends_at, next.ends_at_off)

	return t('nextfleet', 'Your booking: {span}', { span })
}

/** The span's rules, worded once for the sheets' own check and for the server's answer. */
const AFTER_START = () => t('nextfleet', 'The end of a booking comes after its start.')
const STILL_TO_COME = () => t('nextfleet', 'A booking ends in the future. A drive that is over is logged as a trip.')
const LONGEST = () => t('nextfleet', 'A booking lasts 90 days at most.')
const AHEAD = () => t('nextfleet', 'A booking ends no more than a year from now.')

const DAY = 86400 * 1000

/**
 * What is wrong with a span before it is sent, as BookingService::apply() would refuse it.
 *
 * @param {Date} start - when it starts
 * @param {Date} end - when it ends
 * @param {{starts_at: number, ends_at: number}|null} [was] - the booking an edit started from
 * @return {string|null} why it cannot be booked, or null
 */
export function spanFault(start, end, was = null) {
	// An end gone by first: taking the car now, the start is the sheet's, not the reader's.
	if (end.getTime() <= Date.now()) {
		return STILL_TO_COME()
	}

	if (end.getTime() <= start.getTime()) {
		return AFTER_START()
	}
	// A span kept as it was is not measured against bounds it predates, as on the server.
	if (was !== null && start.getTime() === was.starts_at * 1000 && end.getTime() === was.ends_at * 1000) {
		return null
	}
	// BookingService::LONGEST and ::AHEAD.
	if (end.getTime() - start.getTime() > 90 * DAY) {
		return LONGEST()
	}

	// A start gone by is left to the server: it lets a booking keep the start it was made with.
	return end.getTime() > Date.now() + 365 * DAY ? AHEAD() : null
}

/**
 * A booking refusal in the reader's words. BookingService answers in English that names columns;
 * the refusals a person can run into are matched by those words. The caller shows anything else as
 * it came, which beats nothing.
 *
 * @param {string} message - the server's words
 * @return {string|null} a sentence, or null for a refusal this bundle does not know
 */
export function refusalWords(message) {
	/** @type {Record<string, () => string>} */
	const words = {
		'ends_at is after starts_at': AFTER_START,
		'ends_at is still to come': STILL_TO_COME,
		'a booking spans 90 days at most': LONGEST,
		'ends_at is a year ahead at most': AHEAD,
		'starts_at is in the past': () => t('nextfleet', 'A booking does not start in the past.'),
		'the vehicle is not in service, so it takes no booking': () => t('nextfleet', 'The vehicle is not in service, so it takes no booking.'),
		'the booking is over, so the car is no longer taken under it': () => t('nextfleet', 'The booking is over, so the car is no longer taken under it.'),
		'only a booking not yet taken changes, is cancelled or is checked out': () => t('nextfleet', 'This booking was taken, returned or cancelled meanwhile.'),
		'only a booking whose car is out is checked in': () => t('nextfleet', 'The car is not out under this booking, so it is not given back under it.'),
		'notes is longer than 10000 characters': () => t('nextfleet', 'The note is longer than {max} characters.', { max: formatCount(10000) }),
		'level is 100 at most': () => t('nextfleet', 'The tank or battery is a percentage, 0 to 100.'),
		'purpose is longer than 255 characters': () => t('nextfleet', 'The purpose is longer than 255 characters.'),
	}

	return words[message]?.() ?? null
}
