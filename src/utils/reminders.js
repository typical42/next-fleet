/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale, t } from '@nextcloud/l10n'

import { formatCount, formatDay, nameOf, parseDay } from './format.js'

/** @typedef {import('../services/api.js').Reminder} Reminder */

/**
 * The states a reminder is still open in, most urgent first. A snooze silences it, so it sinks
 * below one that is merely planned. Done and dismissed are over and not listed.
 */
const URGENCY = ['overdue', 'due', 'warned', 'planned', 'snoozed']

/**
 * A template's title translates; a title the user typed is theirs and does not
 * (docs/architecture.md#reminder-engine).
 *
 * @param {{template_key: string|null, title?: string|null}} reminder - a reminder or a template's key
 * @return {string} what to call it
 */
export function reminderTitle(reminder) {
	return reminder.template_key === null
		? reminder.title ?? ''
		: templateWord(reminder.template_key)
}

/**
 * @param {string} key - a template key
 * @return {string} its title, or the key where the app knows no word for it
 */
export function templateWord(key) {
	/** @type {Record<string, string>} */
	const words = {
		oil_change: t('nextfleet', 'Oil change'),
		brake_fluid: t('nextfleet', 'Brake fluid'),
		tyre_swap: t('nextfleet', 'Tyre swap'),
		// "HU/AU" has no English equivalent, and never "TÜV" (docs/ui.md#languages).
		hu_au: t('nextfleet', 'Technical inspection (HU/AU)'),
	}

	return words[key] ?? key
}

/**
 * @param {string} state - one of the engine's states (docs/architecture.md#reminder-engine)
 * @return {string} the word for it; the banner states it beside the colour, never by colour alone
 */
export function stateWord(state) {
	/** @type {Record<string, string>} */
	const words = {
		planned: t('nextfleet', 'Planned'),
		warned: t('nextfleet', 'Coming up'),
		due: t('nextfleet', 'Due'),
		overdue: t('nextfleet', 'Overdue'),
		snoozed: t('nextfleet', 'Snoozed'),
		done: t('nextfleet', 'Done'),
		dismissed: t('nextfleet', 'Skipped'),
	}

	return words[state] ?? state
}

/**
 * The open reminders, most urgent first: by state, then by the sooner of the due date and the
 * estimate, and a reminder with neither last in its state. A copy; the list stays as it came.
 *
 * @param {Reminder[]} reminders - as the list answered
 * @return {Reminder[]} the open ones, sorted
 */
export function openByUrgency(reminders) {
	return reminders
		.filter((one) => URGENCY.includes(one.state))
		.sort((a, b) => URGENCY.indexOf(a.state) - URGENCY.indexOf(b.state) || compareDays(soonest(a), soonest(b)))
}

/**
 * The overview's order (docs/ui.md): a laid-up vehicle below the ones still driven, then by its most
 * urgent open reminder as `openByUrgency` ranks one, a vehicle with none after every state, then
 * by the name it is shown under, the plate. A copy; neither list is reordered.
 *
 * @param {import('../services/api.js').Vehicle[]} vehicles - as the overview lists them
 * @param {Array<Reminder & {vehicle: string}>} reminders - the fleet's, as the list answered
 * @return {Array<{vehicle: import('../services/api.js').Vehicle, next: Reminder|null}>} each
 *   vehicle beside its most urgent open reminder
 */
export function fleetByUrgency(vehicles, reminders) {
	const rows = vehicles.map((vehicle) => ({
		vehicle,
		next: openByUrgency(reminders.filter((one) => one.vehicle === vehicle.uuid))[0] ?? null,
	}))
	/** @param {Reminder|null} next - a vehicle's most urgent reminder */
	const rank = (next) => next === null ? URGENCY.length : URGENCY.indexOf(next.state)
	/** @param {import('../services/api.js').Vehicle} vehicle - one listed */
	const parked = (vehicle) => vehicle.lifecycle === 'laid_up' ? 1 : 0

	return rows.sort((a, b) => parked(a.vehicle) - parked(b.vehicle)
		|| rank(a.next) - rank(b.next)
		|| compareDays(a.next && soonest(a.next), b.next && soonest(b.next))
		|| nameOf(a.vehicle).localeCompare(nameOf(b.vehicle)))
}

/**
 * @param {string} day - a plain day, `YYYY-MM-DD`
 * @return {string} it as the reader's locale writes a date
 */
export function dayWords(day) {
	return new Intl.DateTimeFormat(getCanonicalLocale(), { dateStyle: 'medium' }).format(/** @type {Date} */ (parseDay(day)))
}

/**
 * @param {Reminder} reminder - one open reminder
 * @param {string|undefined} unit - its vehicle's `odo_unit`
 * @return {string} when it comes due, by date, by counter or by both
 */
export function dueWords(reminder, unit) {
	const distance = reminder.due_odo === null ? '' : `${formatCount(reminder.due_odo)} ${unit}`
	if (reminder.state === 'snoozed' && reminder.snoozed_until !== null) {
		return t('nextfleet', 'Snoozed until {date}', { date: dayWords(reminder.snoozed_until) })
	}
	if (reminder.mode === 'date') {
		return t('nextfleet', 'Due {date}', { date: dayWords(/** @type {string} */ (reminder.due_date)) })
	}
	if (reminder.mode === 'odo') {
		return t('nextfleet', 'Due at {distance}', { distance })
	}

	return t('nextfleet', 'Due {date} or at {distance}, whichever comes first', { date: dayWords(/** @type {string} */ (reminder.due_date)), distance })
}

/**
 * @param {Reminder|null} next - a vehicle's most urgent open reminder
 * @return {'red'|'amber'|'green'} its traffic light; the overview states the word beside it
 */
export function light(next) {
	if (next?.state === 'due' || next?.state === 'overdue') {
		return 'red'
	}

	return next?.state === 'warned' ? 'amber' : 'green'
}

/**
 * The key the jurisdiction's inspection template carries (lib/Jurisdiction/IInspectionScheme.php).
 */
export const INSPECTION = 'hu_au'

/**
 * @param {Reminder[]} reminders - as the list answered
 * @return {Reminder|null} the open HU/AU reminder; one that is over asks the sticker question again
 */
export function inspectionOf(reminders) {
	return openByUrgency(reminders).find((one) => one.template_key === INSPECTION) ?? null
}

/**
 * The kind of work each template is (MAINTENANCE_TYPES, src/utils/format.js). A reminder under the
 * user's own title is no kind the sheet can tell.
 *
 * @type {Record<string, string>}
 */
const WORK = { oil_change: 'service', brake_fluid: 'service', tyre_swap: 'tyres', [INSPECTION]: 'inspection' }

/**
 * What a new maintenance record closes before anyone picks: the most urgent open reminder, and
 * only when the work is its kind. A guess past it would close a reminder nobody meant.
 *
 * @param {Reminder[]} reminders - as the list answered
 * @param {string|null} type - the kind of work the sheet is on
 * @return {string|null} that reminder's uuid, or none
 */
export function closedByDefault(reminders, type) {
	const first = openByUrgency(reminders)[0]

	return first !== undefined && type !== null && WORK[first.template_key ?? ''] === type ? first.uuid : null
}

/**
 * @param {Reminder} reminder - one listed reminder
 * @return {string|null} the day it comes due, or is expected to, whichever is first
 */
function soonest(reminder) {
	const days = [reminder.due_date, reminder.estimate].filter((day) => typeof day === 'string')

	return days.length === 0 ? null : days.sort()[0]
}

/**
 * Plain `YYYY-MM-DD` days sort as strings. No day sorts last.
 *
 * @param {string|null} a - a day
 * @param {string|null} b - another
 * @return {number} their order
 */
function compareDays(a, b) {
	if (a === b) {
		return 0
	}
	if (a === null || b === null) {
		return a === null ? 1 : -1
	}

	return a < b ? -1 : 1
}

/**
 * @param {string} day - a plain day, `YYYY-MM-DD`
 * @param {number} days - how many to count on
 * @return {string} the day that many later
 */
export function addDays(day, days) {
	const date = /** @type {Date} */ (parseDay(day))
	date.setDate(date.getDate() + days)

	return formatDay(date)
}

/**
 * Clamped to the month's last day, as the engine counts months (docs/architecture.md#reminder-engine):
 * a month after 31 January is the last of February, not a day in March.
 *
 * @param {string} day - a plain day, `YYYY-MM-DD`
 * @param {number} months - how many to count on
 * @return {string} the day that many months later
 */
export function addMonths(day, months) {
	const date = /** @type {Date} */ (parseDay(day))
	const target = new Date(date.getFullYear(), date.getMonth() + months, 1)
	const last = new Date(target.getFullYear(), target.getMonth() + 1, 0).getDate()
	target.setDate(Math.min(date.getDate(), last))

	return formatDay(target)
}

/**
 * An edit is a full replace (docs/architecture.md#reminder-engine), so a change to one field
 * travels with every other one as it stands. Only the fields the mode reads, as the reminder
 * sheet sends them.
 *
 * @param {Reminder} reminder - as the list answered
 * @return {Record<string, unknown>} what an edit that changes nothing sends
 */
export function rewrite(reminder) {
	/** @type {Record<string, unknown>} */
	const written = reminder.template_key === null ? { title: reminder.title } : {}
	written.mode = reminder.mode
	if (reminder.mode !== 'odo') {
		written.due_date = reminder.due_date
		written.recur_months = reminder.recur_months
		written.warn_month_before = reminder.warn_month_before
		written.warn_month_start = reminder.warn_month_start
		written.warn_due_date = reminder.warn_due_date
	}
	if (reminder.mode !== 'date') {
		written.due_odo = reminder.due_odo
		written.lead_odo = reminder.lead_odo
		written.recur_odo = reminder.recur_odo
	}

	return written
}

/**
 * The sticker names a month, and the inspection is due by that month's end.
 *
 * @param {number} year - as on the sticker
 * @param {number} month - 1 to 12
 * @return {string} its last day, `YYYY-MM-DD`
 */
export function monthEnd(year, month) {
	return formatDay(new Date(year, month, 0))
}

/**
 * @param {string|null|undefined} firstReg - the vehicle's first registration, `YYYY-MM-DD`
 * @param {number} months - how long after it the first inspection falls
 * @param {string} today - a plain day
 * @return {{year: number, month: number}|null} that inspection's month, while it is still to come
 */
export function firstInspection(firstReg, months, today) {
	if (!firstReg) {
		return null
	}
	const due = addMonths(firstReg, months)
	if (due.slice(0, 7) < today.slice(0, 7)) {
		return null
	}

	return { year: Number(due.slice(0, 4)), month: Number(due.slice(5, 7)) }
}
