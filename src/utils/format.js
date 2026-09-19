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
 * What a trip may be driven for, in the order it is offered (CONTEXT.md). The order is one fact and
 * the words below are another: the words change with the language, this does not.
 *
 * @type {string[]}
 */
export const CATEGORIES = ['business', 'private', 'commute']

/**
 * A category is a code in the database and a word on screen (docs/ui.md#languages), looked up on
 * call for the same reason as the lifecycle above.
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
 * One list for both, because an energy is an engine's code minus `hybrid` (CONTEXT.md) and the
 * vehicle sheet and the entry sheet name them alike.
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
 * A country is a code in the config and a word on screen (docs/ui.md#languages). The words live
 * here rather than in lib/Jurisdiction/, because the catalogues are the frontend's, and they are
 * looked up on call because the catalogue is registered by the page and not by this module.
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
 * A column is a name in the database and a word on screen (docs/ui.md#languages). These are the
 * labels the sheets ask by, so a hint naming a field names the one the reader will look for
 * (src/components/VehicleSheet.vue, src/components/EntrySheet.vue), and they are looked up on call
 * for the same reason as above.
 *
 * @param {string} column - the column, as the API spells it
 * @return {string} the word for it, or the column where there is none
 */
export function fieldWord(column) {
	/** @type {Record<string, string>} */
	const words = {
		vin: t('nextfleet', 'VIN'),
		first_reg: t('nextfleet', 'First registration'),
		tank_ml: t('nextfleet', 'Tank size (ml)'),
		battery_wh: t('nextfleet', 'Battery capacity (Wh)'),
		// What a logbook ruleset may require of a trip (lib/Jurisdiction/ILogbookRules.php).
		plate: t('nextfleet', 'Registration plate'),
		started_at: t('nextfleet', 'Departure'),
		start_odo: t('nextfleet', 'Start counter'),
		end_odo: t('nextfleet', 'End counter'),
		to_label: t('nextfleet', 'Destination'),
		purpose: t('nextfleet', 'Purpose'),
		partner: t('nextfleet', 'Business partner'),
	}

	return words[column] ?? column
}

/**
 * @param {string[]} columns - the fields a hint or a row asks for, in the order it asks
 * @return {string} their words, as one list for a sentence to carry
 */
export function fieldWords(columns) {
	return columns.map(fieldWord).join(', ')
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
 * A counter as somebody typed it into the sheet. It reads in whole kilometres or whole hours
 * (docs/architecture.md#data-model), so a dot, comma or space in it groups thousands - which is
 * what the app itself wrote out a moment earlier, `148.320` in German and `148,320` in English
 * (docs/ui.md#languages). Grouping is what separates three digits and nothing else, so `7,2` is
 * not a counter: it is a question for the driver rather than a number to round.
 *
 * A litre or a euro is read by parseDecimal() instead.
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
 * (docs/ui.md#languages), so a field carries at most one, and no grouping - `1.234,5` is read two
 * ways by two locales, and a fill-up is never a thousand litres often enough to guess.
 *
 * @param {string} input - what the field holds
 * @param {number} places - how many decimals the column keeps: 3 for thousandths, 2 for cents
 * @return {number|null} the scaled whole number, or null when the field says nothing usable
 */
export function parseDecimal(input, places) {
	const parts = /^(\d*)(?:[.,](\d*))?$/.exec(String(input).trim())
	if (parts === null || (parts[1] + (parts[2] ?? '')) === '' || (parts[2] ?? '').length > places) {
		return null
	}

	return Number(parts[1] + (parts[2] ?? '').padEnd(places, '0'))
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
 * An instant as the clock the entry was made against read it. A user-facing instant is two facts -
 * the UTC second and the offset it was entered at (docs/architecture.md#time) - and a Fahrtenbuch is
 * judged on local calendar dates, so a trip ending 00:30 in Berlin belongs to that day and not to
 * the one UTC is still on.
 *
 * The offset is added and the result read back in UTC, which is what keeps the answer off the
 * machine the browser happens to be running on.
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
