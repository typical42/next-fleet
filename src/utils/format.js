/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

import { t } from './l10n.js'

/**
 * A grouped whole number, in any locale: at most three digits, then groups of exactly three. `\s`
 * also matches the no-break and narrow no-break spaces Intl groups with.
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
 * The line under the name, saying only what the name did not. A lifecycle other than `active`
 * appears there: it explains a vehicle sinking down the overview (docs/ui.md).
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
 * The roles a grant gives, weakest first (CONTEXT.md, Vehicle Access).
 */
export const ROLES = ['viewer', 'driver', 'manager']

/**
 * @param {string} role - a grant's role, or `owner`, which no grant carries
 * @return {string} its word; the German nouns match the grant notification's
 */
export function roleWord(role) {
	/** @type {Record<string, string>} */
	const words = {
		owner: t('nextfleet', 'Owner'),
		viewer: t('nextfleet', 'Viewer'),
		driver: t('nextfleet', 'Driver'),
		manager: t('nextfleet', 'Manager'),
	}

	return words[role] ?? role
}

/**
 * What a trip may be driven for, in the order it is offered (CONTEXT.md).
 *
 * @type {string[]}
 */
export const CATEGORIES = ['business', 'private', 'commute']

/**
 * A category's word on screen, looked up on call like lifecycleWord().
 *
 * @param {string} category - `business`, `private` or `commute` (CONTEXT.md)
 * @return {string} the word for it, or the code where there is none
 */
export function categoryWord(category) {
	/** @type {Record<string, string>} */
	const words = {
		business: t('nextfleet', 'Business'),
		private: t('nextfleet', 'Private'),
		commute: t('nextfleet', 'Commute'),
	}

	return words[category] ?? category
}

/** What an Expense can be (CONTEXT.md), in the order the sheet offers them. */
export const EXPENSE_CATEGORIES = ['insurance', 'tax', 'toll', 'parking', 'fine', 'lease', 'other']

/**
 * An expense category is a code in the database and a word on screen (docs/ui.md#languages).
 *
 * @param {string} category - one of EXPENSE_CATEGORIES
 * @return {string} the word for it, or the code where there is none
 */
export function expenseWord(category) {
	/** @type {Record<string, string>} */
	const words = {
		insurance: t('nextfleet', 'Insurance'),
		tax: t('nextfleet', 'Vehicle tax'),
		toll: t('nextfleet', 'Toll'),
		parking: t('nextfleet', 'Parking'),
		fine: t('nextfleet', 'Fine'),
		lease: t('nextfleet', 'Lease'),
		other: t('nextfleet', 'Other'),
	}

	return words[category] ?? category
}

/** The kinds of work a Maintenance Record can be (CONTEXT.md), in the order the sheet offers them. */
export const MAINTENANCE_TYPES = ['service', 'repair', 'inspection', 'tyres', 'upgrade']

/**
 * A maintenance type is a code in the database and a word on screen (docs/ui.md#languages).
 *
 * @param {string} type - one of MAINTENANCE_TYPES
 * @return {string} the word for it, or the code where there is none
 */
export function maintenanceWord(type) {
	/** @type {Record<string, string>} */
	const words = {
		service: t('nextfleet', 'Service'),
		repair: t('nextfleet', 'Repair'),
		inspection: t('nextfleet', 'Inspection'),
		tyres: t('nextfleet', 'Tyres'),
		upgrade: t('nextfleet', 'Upgrade'),
	}

	return words[type] ?? type
}

/**
 * An engine or an energy is a code in the database and a word on screen (docs/ui.md#languages).
 * One list for both: an energy is an engine's code minus `hybrid` (CONTEXT.md).
 *
 * @param {string} code - petrol, diesel, lpg, cng, electric or hybrid
 * @return {string} the word for it, or the code where there is none
 */
export function energyWord(code) {
	/** @type {Record<string, string>} */
	const words = {
		petrol: t('nextfleet', 'Petrol'),
		diesel: t('nextfleet', 'Diesel'),
		lpg: t('nextfleet', 'LPG'),
		cng: t('nextfleet', 'CNG'),
		electric: t('nextfleet', 'Electric'),
		hybrid: t('nextfleet', 'Hybrid'),
	}

	return words[code] ?? code
}

/**
 * What a timeline row is called. A trip is named by its route, which is what a driver recognises
 * it by, else by its purpose.
 *
 * @param {import('../services/api.js').Entry} entry - the row
 * @return {string} its name
 */
export function entryName(entry) {
	if (entry.energy !== undefined) {
		return energyWord(entry.energy.energy)
	}
	if (entry.maintenance !== undefined) {
		return entry.maintenance.title
	}
	if (entry.expense !== undefined) {
		return entry.expense.category ? expenseWord(entry.expense.category) : t('nextfleet', 'Expense')
	}
	if (entry.trip === undefined) {
		return t('nextfleet', 'Counter reading')
	}

	const route = [entry.trip.from_label, entry.trip.to_label].filter(Boolean)

	return route.join(' → ') || entry.trip.purpose || t('nextfleet', 'Trip')
}

/** What a Document can be (CONTEXT.md), in the order its section lists them. */
export const DOCUMENT_KINDS = ['registration', 'insurance', 'manual', 'receipt', 'photo']

/**
 * A document kind is a code in the database and a word on screen (docs/ui.md#languages).
 *
 * @param {string} kind - one of DOCUMENT_KINDS
 * @return {string} the word for it, or the code where there is none
 */
export function documentKindWord(kind) {
	/** @type {Record<string, string>} */
	const words = {
		registration: t('nextfleet', 'Registration'),
		insurance: t('nextfleet', 'Insurance policy'),
		manual: t('nextfleet', 'Manual'),
		receipt: t('nextfleet', 'Receipt'),
		photo: t('nextfleet', 'Photo'),
	}

	return words[kind] ?? kind
}

/**
 * A country's word on screen, looked up on call like lifecycleWord(). The words live here rather
 * than in lib/Jurisdiction/, because the catalogues are the frontend's.
 *
 * @param {string} key - the registered key, as lib/Jurisdiction/ spells it
 * @param {string} [name] - what the server calls it: English, and the fallback for a country this
 *   bundle has no word for, because untranslated beats missing
 * @return {string} the word for it
 */
export function jurisdictionWord(key, name = key) {
	/** @type {Record<string, string>} */
	const words = {
		de: t('nextfleet', 'Germany'),
		generic: t('nextfleet', 'Generic'),
	}

	return words[key] ?? name
}

/**
 * A column's word on screen, looked up on call like lifecycleWord(). These are the labels the
 * sheets ask by, so a hint names the field the reader will look for.
 *
 * @param {string} column - the column, as the API spells it
 * @param {string} [odoUnit] - the vehicle's `odo_unit`, which names a trip's counters
 * @return {string} the word for it, or the column where there is none
 */
export function fieldWord(column, odoUnit) {
	/** @type {Record<string, string>} */
	const words = {
		vin: t('nextfleet', 'VIN'),
		first_reg: t('nextfleet', 'First registration'),
		tank_ml: t('nextfleet', 'Tank size (l)'),
		battery_wh: t('nextfleet', 'Battery capacity (kWh)'),
		currency: t('nextfleet', 'Currency'),
		// What a logbook ruleset may require of a trip (lib/Jurisdiction/ILogbookRules.php).
		plate: t('nextfleet', 'Registration plate'),
		started_at: t('nextfleet', 'Departure'),
		...tripCounterWords(odoUnit),
		to_label: t('nextfleet', 'Destination'),
		purpose: t('nextfleet', 'Purpose'),
		partner: t('nextfleet', 'Business partner'),
	}

	return words[column] ?? column
}

/**
 * @param {string[]} columns - the fields a hint or a row asks for, in the order it asks
 * @param {string} [odoUnit] - the vehicle's `odo_unit`
 * @return {string} their words, as one list for a sentence to carry
 */
export function fieldWords(columns, odoUnit) {
	return columns.map((column) => fieldWord(column, odoUnit)).join(', ')
}

/**
 * The labels of a trip's two counters. A counter of hours is no odometer, so only a kilometre
 * vehicle's trip says "odometer".
 *
 * @param {string} [odoUnit] - the vehicle's `odo_unit`
 * @return {{start_odo: string, end_odo: string}} the label of each
 */
export function tripCounterWords(odoUnit) {
	return odoUnit === 'h'
		? { start_odo: t('nextfleet', 'Start counter'), end_odo: t('nextfleet', 'End counter') }
		: { start_odo: t('nextfleet', 'Odometer at departure'), end_odo: t('nextfleet', 'Odometer at arrival') }
}

/**
 * @param {Partial<import('../services/api.js').Vehicle>} vehicle - the vehicle as it was read
 * @return {string} manufacturer and model, as much of the two as the vehicle carries
 */
function madeOf(vehicle) {
	return [vehicle.manufacturer, vehicle.model].filter(Boolean).join(' ')
}

/**
 * A count as the reader's locale writes it: separators, grouping and digits belong to the locale,
 * not the language (docs/ui.md#languages).
 *
 * @param {number} value - the whole number to write out
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the number, grouped
 */
export function formatCount(value, locale = getCanonicalLocale()) {
	return new Intl.NumberFormat(locale).format(value)
}

/**
 * A fill-up's amount as the timeline states it. `amount` counts millilitres or watt-hours by
 * `energy` (docs/architecture.md#data-model), so a thousand of it is a litre or a kilowatt-hour.
 *
 * @param {number} amount - millilitres or watt-hours
 * @param {string} energy - the energy it is an amount of
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the amount with its unit
 */
export function formatEnergyAmount(amount, energy, locale = getCanonicalLocale()) {
	const unit = energy === 'electric' ? 'kWh' : 'l'

	return `${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(amount / 1000)} ${unit}`
}

/**
 * A segment's consumption (ConsumptionService). The units stay metric in every language
 * (docs/ui.md), and one decimal is as precise as a pump and an odometer are.
 *
 * @param {{value: number, per: string}} consumption - litres or kWh per 100 km, or per hour
 * @param {string} energy - which of the two the amount was in
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} e.g. `6,1 l/100 km` or `4,5 l/h`
 */
export function formatConsumption(consumption, energy, locale = getCanonicalLocale()) {
	const number = new Intl.NumberFormat(locale, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(consumption.value)
	const unit = energy === 'electric' ? 'kWh' : 'l'

	return `${number} ${unit}/${consumption.per === 'h' ? 'h' : '100 km'}`
}

/**
 * The rolling wall-side figure (ConsumptionService::wallSide()) beside the segment one. It counts
 * what the charger delivered, not what the battery kept, so it says so rather than pass for the
 * segment figure (docs/architecture.md#numbers-consumption-cost-emissions).
 *
 * @param {{value: number, per: string}} rolling - kWh per 100 km, or per hour
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {{figure: string, note: string}} e.g. `≈ 19,0 kWh/100 km` and the line under it
 */
export function formatWallSide(rolling, locale = getCanonicalLocale()) {
	return {
		figure: `≈ ${formatConsumption(rolling, 'electric', locale)}`,
		note: t('nextfleet', 'Approximate: counted at the charger, so charging losses are included'),
	}
}

/**
 * Gross cents as the reader's locale writes money. A vehicle without a currency still saves its
 * costs, so a row still states one: as a bare amount.
 *
 * @param {number} cents - the column's integer
 * @param {string|null|undefined} currency - the vehicle's code, as typed
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the amount, with its currency where there is one
 */
export function formatMoney(cents, currency, locale = getCanonicalLocale()) {
	const plain = { minimumFractionDigits: 2, maximumFractionDigits: 2 }
	if (currency) {
		try {
			return new Intl.NumberFormat(locale, { ...plain, style: 'currency', currency }).format(cents / 100)
		} catch {
			// The column is free text, and Intl refuses a code it does not know.
			return `${new Intl.NumberFormat(locale, plain).format(cents / 100)} ${currency}`
		}
	}

	return new Intl.NumberFormat(locale, plain).format(cents / 100)
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
 * A column's integer as a decimal field shows it, in what parseDecimal() reads back: Latin digits,
 * no grouping, and the locale's mark where that is a comma, a point everywhere else.
 *
 * @param {number} value - the integer the column holds: basis points, tenths of a cent
 * @param {number} places - how many of its digits are decimals
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the decimal, without trailing zeros
 */
export function formatDecimal(value, places, locale = getCanonicalLocale()) {
	const decimal = new Intl.NumberFormat(locale).formatToParts(0.5).find((part) => part.type === 'decimal')

	return String(value / 10 ** places).replace('.', decimal?.value === ',' ? ',' : '.')
}

/**
 * A counter as typed into the sheet. Counters are whole (docs/architecture.md#data-model), so a
 * dot, comma or space groups thousands, as the app itself writes them: `148.320` in German,
 * `148,320` in English (docs/ui.md#languages). Grouping separates exactly three digits, so `7,2`
 * is a question for the driver, not a number to round. Decimals go through parseDecimal().
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

/**
 * A decimal as somebody typed it, as the integer its column holds (docs/architecture.md#data-model):
 * litres as millilitres, euros as cents. A comma and a point are both the decimal mark
 * (docs/ui.md#languages). A point also groups thousands, as a German receipt prints them, where it
 * cannot be the mark: before a comma, more than once, or with three digits after it in a field
 * that keeps two. A comma never groups: `1,799` is a German price per litre, and reading it as
 * 1 799 would put a thousand euros on the bill.
 *
 * @param {string} input - what the field holds
 * @param {number} places - how many decimals the column keeps: 3 for thousandths, 2 for cents
 * @return {number|null} the scaled whole number, or null when the field says nothing usable
 */
export function parseDecimal(input, places) {
	const typed = String(input).trim()
	const grouped = /^([1-9]\d{0,2}(?:\.\d{3})+)(?:,(\d*))?$/.exec(typed)
	const groups = grouped === null ? 0 : grouped[1].split('.').length - 1
	const parts = grouped !== null && (grouped[2] !== undefined || groups > 1 || places < 3)
		? [typed, grouped[1].replaceAll('.', ''), grouped[2]]
		: /^(\d*)(?:[.,](\d*))?$/.exec(typed)
	if (parts === null || (parts[1] + (parts[2] ?? '')) === '' || (parts[2] ?? '').length > places) {
		return null
	}

	return Number(parts[1] + (parts[2] ?? '').padEnd(places, '0'))
}

/**
 * What a decimal field says when parseDecimal() could not read it: two marks in it are a grouping
 * it would have had to guess at, which the driver can type away; anything else is the field's own
 * complaint.
 *
 * @param {string} input - what the field holds
 * @param {string} complaint - the field's words for a value that is not a number
 * @return {string} what to say
 */
export function decimalComplaint(input, complaint) {
	return /[.,].*[.,]/.test(String(input))
		? t('nextfleet', 'Type the amount without a thousands separator, e.g. 1234,56.')
		: complaint
}

/**
 * The day a row belongs to, short, as the reader's locale writes it. The month is the sticky header
 * above it (docs/ui.md), so the row itself says only the day and the month it repeats.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the day, `03.09.` in German and `03/09` in English
 */
export function shortDate(instant, offset, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', timeZone: 'UTC' })
		.format(local(instant, offset))
}

/**
 * A day named on its own, where no month header says the year: in a list that spans years.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the day, `03.09.2026` in German and `09/03/2026` in American English
 */
export function formatDate(instant, offset, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'UTC' })
		.format(local(instant, offset))
}

/**
 * A moment named on its own, where no month header says which month and year it is in - a question
 * put to the driver about it, say.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the date and the time, `03.09.2026, 01:30` in German
 */
export function fullMoment(instant, offset, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeStyle: 'short', timeZone: 'UTC' })
		.format(local(instant, offset))
}

/**
 * A booking's span. It is looked at within days of it, so the weekday stands in for the year, and a
 * span within one day names the day once.
 *
 * @param {number} from - its first second
 * @param {number} fromOff - the UTC offset that end was planned at, minutes
 * @param {number} to - the second after its last
 * @param {number} toOff - the UTC offset that end was planned at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the span, `Fri 02/10, 14:00–18:00` in British English
 */
export function formatSpan(from, fromOff, to, toOff, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
		.formatRange(local(from, fromOff), local(to, toOff))
}

/**
 * One end of a booking, named the way formatSpan() names a span's.
 *
 * @param {number} instant - the moment, seconds
 * @param {number} offset - the UTC offset it was planned at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the moment, `Fri 02/10, 18:00` in British English
 */
export function formatWhen(instant, offset, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
		.format(local(instant, offset))
}

/**
 * The month a row belongs to, as the sticky header names it.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @param {string} [locale] - defaults to the one Nextcloud resolved for this session
 * @return {string} the month and its year
 */
export function formatMonth(instant, offset, locale = getCanonicalLocale()) {
	return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' })
		.format(local(instant, offset))
}

/**
 * The moment as the markup states it: the wall clock the entry was made against, with the offset it
 * was made at. Anything reading the markup rather than the rendered text - a screen reader, a
 * scraper - gets the day the row is filed under, which the plain UTC instant would contradict
 * across midnight (docs/architecture.md#time).
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @return {string} the moment, `2026-09-03T01:30:00+02:00`
 */
export function isoInstant(instant, offset) {
	const size = Math.abs(offset)
	const zone = [Math.floor(size / 60), size % 60].map((part) => String(part).padStart(2, '0')).join(':')

	return `${local(instant, offset).toISOString().slice(0, 19)}${offset < 0 ? '-' : '+'}${zone}`
}

/**
 * What groups rows under one header. A key rather than the header's own words, because the words
 * are the locale's and two months of two years could otherwise be written alike.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @return {string} the month, `YYYY-MM`
 */
export function monthKey(instant, offset) {
	return local(instant, offset).toISOString().slice(0, 7)
}

/**
 * An instant as the clock the entry was made against read it (docs/architecture.md#time). A
 * Fahrtenbuch is judged on local dates, so a trip ending 00:30 in Berlin belongs to that day, not
 * the one UTC is still on. Read back in UTC, so the browser's own zone never enters.
 *
 * @param {number} instant - when it happened, seconds
 * @param {number} offset - the UTC offset it happened at, minutes
 * @return {Date} that wall clock, to be read with UTC getters and formatters
 */
function local(instant, offset) {
	return new Date((instant + offset * 60) * 1000)
}

/**
 * A calendar day as the date picker wants it. The day is one fact and carries no time of day
 * (docs/architecture.md#time), so it is built at local midnight: `new Date('2019-03-07')` is UTC
 * midnight, which every local getter west of Greenwich reads back as the 6th.
 *
 * @param {string|null|undefined} day - the day as the API states it, `YYYY-MM-DD`
 * @return {Date|null} that day, or null when there is none
 */
export function parseDay(day) {
	const parts = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(day ?? '').trim())

	return parts === null ? null : new Date(Number(parts[1]), Number(parts[2]) - 1, Number(parts[3]))
}

/**
 * The inverse, with the same local getters the picker itself formats with.
 *
 * @param {Date|null|undefined} date - what the picker holds
 * @return {string} the day as the API states it, or the empty string for no day
 */
export function formatDay(date) {
	if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
		return ''
	}

	return [
		String(date.getFullYear()).padStart(4, '0'),
		String(date.getMonth() + 1).padStart(2, '0'),
		String(date.getDate()).padStart(2, '0'),
	].join('-')
}
