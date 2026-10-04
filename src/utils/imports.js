/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { formatCount } from './format.js'
import { t } from './l10n.js'

/**
 * One kind of export file: an importer and one of its record types (lib/Import/IImporter.php).
 * `counter` says whether the file has a counter column, which is when the server asks its unit.
 *
 * @typedef {object} Format
 * @property {string} importer - the importer's key
 * @property {string} recordType - one of its record types
 * @property {boolean} counter - whether the file carries a counter
 */

/**
 * Both importers' record types, in their own order. Kept here rather than read from the server,
 * which has no route for it: the list changes only with an importer, and an importer is a release.
 *
 * @type {Format[]}
 */
export const FORMATS = [
	{ importer: 'lubelogger', recordType: 'fuel', counter: true },
	{ importer: 'lubelogger', recordType: 'service', counter: true },
	{ importer: 'lubelogger', recordType: 'repair', counter: true },
	{ importer: 'lubelogger', recordType: 'upgrade', counter: true },
	{ importer: 'lubelogger', recordType: 'tax', counter: false },
	{ importer: 'lubelogger', recordType: 'supplies', counter: false },
	{ importer: 'lubelogger', recordType: 'odometer', counter: true },
	{ importer: 'spritmonitor', recordType: 'fuel', counter: true },
	{ importer: 'spritmonitor', recordType: 'costs', counter: true },
]

/**
 * A format as the picker names it. Nominative only (docs/legal.md): the label says whose file
 * layout it reads, and the server's importers carry the same words.
 *
 * @param {Format} format - one of FORMATS
 * @return {string} its name
 */
export function formatWord(format) {
	/** @type {Record<string, string>} */
	const files = {
		lubelogger: t('nextfleet', 'CSV (LubeLogger format)'),
		spritmonitor: t('nextfleet', 'CSV (Spritmonitor format)'),
	}
	/** @type {Record<string, string>} */
	const types = {
		fuel: t('nextfleet', 'fuel'),
		service: t('nextfleet', 'service'),
		repair: t('nextfleet', 'repair'),
		upgrade: t('nextfleet', 'upgrade'),
		tax: t('nextfleet', 'tax'),
		supplies: t('nextfleet', 'supplies'),
		odometer: t('nextfleet', 'odometer'),
		costs: t('nextfleet', 'costs'),
	}

	return t('nextfleet', '{file} — {type}', { file: files[format.importer], type: types[format.recordType] })
}

/**
 * Why one row is not created, in words. The server sends a word and the header it blames
 * (lib/Import/Cells.php, lib/Import/Duplicates.php); a word this bundle does not know
 * yet is shown as it came, which beats nothing.
 *
 * @param {string} reason - the reason word, or `duplicate`
 * @param {string|null} column - the header blamed, null when none is
 * @return {string} a sentence
 */
export function reasonWord(reason, column) {
	const named = { column: column ?? '' }
	/** @type {Record<string, () => string>} */
	const words = {
		number: () => t('nextfleet', '{column} is not a number.', named),
		ambiguous_number: () => t('nextfleet', '{column} could be read two ways, as 1,234 is, and nothing else in the column tells which.', named),
		too_large: () => t('nextfleet', '{column} is too large.', named),
		negative: () => t('nextfleet', '{column} is below zero.', named),
		date: () => t('nextfleet', '{column} is not a date this import reads.', named),
		flag: () => t('nextfleet', '{column} is neither yes nor no.', named),
		// No column: the vehicle's own currency is no code (lib/Import/Cells.php money()).
		currency: () => column === null
			? t('nextfleet', 'The currency of the vehicle is not a three-letter code such as EUR. Correct it in the edit sheet of the vehicle, then preview again.')
			: t('nextfleet', '{column} names a currency the vehicle is not kept in.', named),
		missing: () => t('nextfleet', '{column} is empty.', named),
		too_long: () => t('nextfleet', '{column} is too long.', named),
		code: () => t('nextfleet', '{column} holds a code this import does not know.', named),
		category: () => t('nextfleet', 'Its category is still to be chosen.'),
		// The user's own answer, not a fault of the file: the outcome set has no fourth kind.
		skipped: () => t('nextfleet', 'Skipped, as you chose for its category.'),
		adblue: () => t('nextfleet', '{column} says AdBlue, which is not a fuel.', named),
		hydrogen: () => t('nextfleet', '{column} says hydrogen, which this app does not record.', named),
		purchase: () => t('nextfleet', 'A purchase price is not a running cost.'),
		refund: () => t('nextfleet', 'A refund would be a negative cost.'),
		duplicate: () => t('nextfleet', 'Already there.'),
		cells: () => t('nextfleet', 'The row has more cells than the file has columns.'),
	}

	return words[reason]?.() ?? (column === null ? reason : `${column}: ${reason}`)
}

/**
 * Why the whole file is not read: a 422's reason and the row reading stopped at
 * (lib/Import/CsvReader.php). The caps are docs/security.md's.
 *
 * @param {string} reason - the reason word
 * @param {number|null} row - the spreadsheet row, null when none is to blame
 * @return {string} a sentence
 */
export function refusalWord(reason, row) {
	const at = { row: row ?? '' }
	/** @type {Record<string, () => string>} */
	const words = {
		empty: () => t('nextfleet', 'The file is empty.'),
		too_large: () => t('nextfleet', 'The file is larger than 5 MB.'),
		too_many_rows: () => t('nextfleet', 'The file has more than {max} rows.', { max: formatCount(20000) }),
		line_too_long: () => t('nextfleet', 'Row {row} is longer than 64 KiB, so the file is not read.', at),
		// Without a row it is the file's cap (CsvReader::MAX_FILE_CELLS).
		too_many_cells: () => row === null
			? t('nextfleet', 'The file has more than {max} cells.', { max: formatCount(500000) })
			: t('nextfleet', 'Row {row} has more than {max} cells, so the file is not read.', { ...at, max: formatCount(256) }),
		binary: () => t('nextfleet', 'The file is not text.'),
		encoding: () => t('nextfleet', 'Row {row} is neither UTF-8 nor Windows-1252 text, or the file mixes the two.', at),
		unclosed_quote: () => t('nextfleet', 'A quote opened in row {row} is never closed.', at),
		line_breaks: () => t('nextfleet', 'The file ends its lines in a way this import does not read. Save it again with ordinary line breaks.'),
	}

	return words[reason]?.() ?? t('nextfleet', 'The file is not read: {reason}', { reason })
}

/**
 * What a column became, named as the entry sheet asks for it. Spritmonitor's costs report also
 * names what is no entry field (`date`, `category`, `currency`).
 *
 * @param {string} field - the field, as the column report names it
 * @param {string} recordType - the file's record type: a fill-up's `amount` is litres or kWh
 * @return {string} its word, or the field where there is none
 */
export function placedWord(field, recordType) {
	if (field === 'amount' && recordType === 'fuel') {
		return t('nextfleet', 'Quantity')
	}

	/** @type {Record<string, () => string>} */
	const words = {
		filled_at: () => t('nextfleet', 'Date'),
		done_at: () => t('nextfleet', 'Date'),
		spent_at: () => t('nextfleet', 'Date'),
		read_at: () => t('nextfleet', 'Date'),
		date: () => t('nextfleet', 'Date'),
		odo: () => t('nextfleet', 'Counter'),
		value: () => t('nextfleet', 'Counter'),
		amount: () => t('nextfleet', 'Amount'),
		total: () => t('nextfleet', 'Total price'),
		cost: () => t('nextfleet', 'Cost'),
		full_tank: () => t('nextfleet', 'Full tank'),
		missed_previous: () => t('nextfleet', 'Missed the previous one'),
		title: () => t('nextfleet', 'Title'),
		notes: () => t('nextfleet', 'Notes'),
		category: () => t('nextfleet', 'Category'),
		energy: () => t('nextfleet', 'Energy'),
		currency: () => t('nextfleet', 'Currency (checked, not imported)'),
	}

	return words[field]?.() ?? field
}

/**
 * Which units the file does not say and the user must: the distance of a counter column, and the
 * volume of a fill-up unless every one is a charge, which is in kWh whatever the tool.
 *
 * @param {Format} format - one of FORMATS
 * @param {{energy_types?: string[]|null}} vehicle - what it takes
 * @return {string[]} `distance`, `volume`, or neither
 */
export function unitsAsked(format, vehicle) {
	const fuels = (vehicle.energy_types ?? []).some((one) => one !== 'electric')

	return [
		...(format.counter ? ['distance'] : []),
		...(format.recordType === 'fuel' && fuels ? ['volume'] : []),
	]
}
