/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { computed, ref } from 'vue'

import { listBookings, readTimeline } from '../services/api.js'
import { entryName, formatDate, formatSpan, isoInstant } from './format.js'
import { t } from './l10n.js'

/**
 * A row a paper may belong to, as *Belongs to* offers it.
 *
 * @typedef {object} Owner
 * @property {string} id - the entry's or the booking's uuid
 * @property {'energy'|'maintenance'|'expense'|'booking'} type - what `linked_type` becomes
 * @property {string} label - named as its timeline row or booking row is, with the year
 * @property {string} words - what a search is matched against, lower case
 */

/** The kinds of Entry a paper may belong to. */
const KINDS = ['energy', 'maintenance', 'expense']

/** How many pages of one kind are read looking for an entry the session may file a paper on. */
const PAGES = 4

/**
 * How many pages of one kind a search reads at most: 2000 rows, decades of fill-ups. A bound,
 * so a broken cursor cannot read forever.
 */
const HISTORY = 40

/**
 * Entries of one kind the session may file a paper on, by each row's own `may`, newest first:
 * pages are read until `enough` says so, the history ends, or `pages` were read.
 *
 * @param {string} uuid - the vehicle
 * @param {string} type - the kind of Entry
 * @param {number} pages - how many pages at most
 * @param {(rows: import('../services/api.js').Entry[]) => boolean} enough - whether to stop
 * @return {Promise<import('../services/api.js').Entry[]>} those rows, newest first
 */
async function linkable(uuid, type, pages, enough) {
	const rows = []
	let cursor = null
	for (let read = 0; read < pages; read++) {
		const page = await readTimeline(uuid, { type, cursor })
		rows.push(...page.rows.filter((entry) => entry.may?.includes('edit')))
		if (enough(rows) || page.next === null) {
			break
		}
		cursor = page.next
	}

	return rows
}

/**
 * @param {string} label - what the option says
 * @param {number} at - when its row happened, seconds
 * @param {number} off - the UTC offset it happened at, minutes
 * @param {(string|null|undefined)[]} more - further words a search finds it by
 * @return {string} the label, the ISO day and those words, lower case
 */
function words(label, at, off, more = []) {
	return [label, isoInstant(at, off).slice(0, 10), ...more].filter(Boolean).join(' ').toLocaleLowerCase()
}

/**
 * The options, newest first: entries and the bookings handed over that the session may file on.
 *
 * @param {import('../services/api.js').Entry[]} entries - of the linkable kinds
 * @param {import('../services/api.js').Booking[]} bookings - as listed
 * @return {Owner[]} the options
 */
function owners(entries, bookings) {
	const rows = entries.map((entry) => {
		const label = `${formatDate(entry.occurred_at, entry.occurred_at_off)} · ${entryName(entry)}`
		return {
			id: /** @type {{uuid: string}} */ (entry[entry.type]).uuid,
			type: entry.type,
			at: entry.occurred_at,
			label,
			words: words(label, entry.occurred_at, entry.occurred_at_off),
		}
	})
	const handovers = bookings
		.filter((booking) => booking.may.includes('attach'))
		.map((booking) => {
			const label = t('nextfleet', 'Booking, {span}', {
				span: formatSpan(booking.starts_at, booking.starts_at_off, booking.ends_at, booking.ends_at_off),
			})
			return {
				id: booking.uuid,
				type: 'booking',
				at: booking.starts_at,
				label,
				// The span leaves the year out, so the day is matched as an entry's label writes it.
				words: words(label, booking.starts_at, booking.starts_at_off, [formatDate(booking.starts_at, booking.starts_at_off), booking.purpose]),
			}
		})

	return /** @type {Owner[]} */ ([...rows, ...handovers]
		.sort((a, b) => b.at - a.at)
		.map(({ id, type, label, words }) => ({ id, type, label, words })))
}

/**
 * The rows a paper is likely to belong to: the newest entries of each linkable kind, and the
 * bookings handed over. A receipt is filed soon after the fill-up or the invoice it is for, a photo
 * soon after the handover. A manager may file on any row, so the newest page serves; on a busy
 * pool a driver's own may be further back, so pages are read on until one holds any.
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<Owner[]>} the options; a refusal rejects
 */
export async function readOwners(uuid) {
	const [kinds, bookings] = await Promise.all([
		Promise.all(KINDS.map((type) => linkable(uuid, type, PAGES, (rows) => rows.length > 0))),
		listBookings(uuid),
	])

	return owners(kinds.flat(), bookings)
}

/**
 * Every row a paper may belong to, for a search: an invoice filed a year late belongs to a record
 * far behind the newest page.
 *
 * @param {string} uuid - the vehicle
 * @return {Promise<Owner[]>} the options; a refusal rejects
 */
export async function readHistory(uuid) {
	const [kinds, bookings] = await Promise.all([
		Promise.all(KINDS.map((type) => linkable(uuid, type, HISTORY, () => false))),
		listBookings(uuid, { from: 0 }),
	])

	return owners(kinds.flat(), bookings)
}

/**
 * The options a search finds: those holding every word of it, by title, purpose or date - as
 * the label writes it, or as `YYYY-MM-DD`.
 *
 * @param {Owner[]} options - as read
 * @param {string} search - what was typed
 * @return {Owner[]} those that match, in their order
 */
export function matching(options, search) {
	const wanted = search.toLocaleLowerCase().split(/\s+/).filter(Boolean)

	return options.filter((one) => wanted.every((word) => one.words.includes(word)))
}

/**
 * The search of "Belongs to", as the documents section and the inbox sheet both offer it: the
 * newest rows until something is typed, then the whole history, read on the first keystroke after
 * each `reset()` and only then, since it takes a page per fifty rows of each kind.
 *
 * @param {() => string} vehicle - the vehicle searched, as it is now
 * @param {import('vue').Ref<Owner[]>} newest - what `readOwners()` gave
 * @return {{offered: import('vue').ComputedRef<Owner[]>, searching: import('vue').ComputedRef<boolean>, lookFor: (typed: string) => void, reset: () => void}}
 * what the select offers, whether the history is still on the way, the select's `search`
 * handler, and the start of a new dialog or vehicle
 */
export function ownerSearch(vehicle, newest) {
	const search = ref('')
	/** @type {import('vue').Ref<Owner[]|null>} */
	const history = ref(null)
	/** @type {Promise<void>|null} */
	let reading = null

	/** @param {string} typed - what is in the search field now */
	function lookFor(typed) {
		search.value = typed
		if (typed.trim() === '' || reading !== null) {
			return
		}
		history.value = null
		const asked = reading = readHistory(vehicle())
			.then((all) => {
				// A dialog or vehicle picked since has its own history to read.
				if (reading === asked) {
					history.value = all
				}
			}, () => {
				// Nothing found this time, and the next keystroke asks again.
				if (reading === asked) {
					history.value = []
					reading = null
				}
			})
	}

	/** A new dialog, or a new vehicle in it: its history is its own. */
	function reset() {
		search.value = ''
		history.value = null
		reading = null
	}

	return {
		offered: computed(() => search.value.trim() === '' ? newest.value : matching(history.value ?? [], search.value)),
		searching: computed(() => search.value.trim() !== '' && history.value === null),
		lookFor,
		reset,
	}
}
