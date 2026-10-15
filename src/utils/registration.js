/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { version } from '../../package.json'
import { t } from './l10n.js'

/**
 * Field J of the registration certificate, the EU vehicle category, grouped as the sheet offers it.
 * The codes are lib/Service/Registration.php's, which validates them; the spec holds the two equal.
 */
const GROUPS = [
	{ id: 'cars', codes: ['M1', 'M2', 'M3'] },
	{ id: 'goods', codes: ['N1', 'N2', 'N3'] },
	{ id: 'trailers', codes: ['O1', 'O2', 'O3', 'O4'] },
	{
		id: 'light',
		codes: [
			'L1e', 'L1e-A', 'L1e-B',
			'L2e', 'L2e-P', 'L2e-U',
			'L3e', 'L3e-A1', 'L3e-A1E', 'L3e-A1T', 'L3e-A2', 'L3e-A2E', 'L3e-A2T', 'L3e-A3', 'L3e-A3E', 'L3e-A3T',
			'L4e',
			'L5e', 'L5e-A', 'L5e-B',
			'L6e', 'L6e-A', 'L6e-B', 'L6e-BU', 'L6e-BP',
			'L7e', 'L7e-A', 'L7e-A1', 'L7e-A2', 'L7e-B', 'L7e-B1', 'L7e-B2', 'L7e-C', 'L7e-CU', 'L7e-CP',
		],
	},
	{
		id: 'agricultural',
		codes: [
			'T1a', 'T1b', 'T2a', 'T2b', 'T3a', 'T3b', 'T4.1a', 'T4.1b', 'T4.2a', 'T4.2b', 'T4.3a', 'T4.3b',
			'C1a', 'C1b', 'C2a', 'C2b', 'C3a', 'C3b', 'C4.1a', 'C4.1b', 'C4.2a', 'C4.2b', 'C4.3a', 'C4.3b',
			'R1a', 'R1b', 'R2a', 'R2b', 'R3a', 'R3b', 'R4a', 'R4b',
			'S1a', 'S1b', 'S2a', 'S2b',
		],
	},
]

export const CATEGORIES = GROUPS.flatMap((group) => group.codes)

/** The categories an off-road vehicle may be, stored with `G` appended (`N3G`). */
export const OFF_ROAD = ['M1', 'M2', 'M3', 'N1', 'N2', 'N3']

/**
 * Field 4, the EU body code, with the categories each belongs to. A category listed bare takes its
 * off-road variant too; SM is for off-road vehicles only. lib/Service/Registration.php holds the
 * same, and refuses a pair that does not fit.
 *
 * @type {Record<string, string[]>}
 */
export const BODY_TYPES = {
	AA: ['M1'],
	AB: ['M1'],
	AC: ['M1'],
	AD: ['M1'],
	AE: ['M1'],
	AF: ['M1'],
	AG: ['M1'],
	CA: ['M2', 'M3'],
	CB: ['M2', 'M3'],
	CC: ['M2', 'M3'],
	CD: ['M2', 'M3'],
	CE: ['M2', 'M3'],
	CF: ['M2', 'M3'],
	CG: ['M2', 'M3'],
	CH: ['M2', 'M3'],
	CI: ['M2', 'M3'],
	CJ: ['M2', 'M3'],
	CX: ['M2', 'M3'],
	BA: ['N1', 'N2', 'N3'],
	BB: ['N1', 'N2', 'N3'],
	BC: ['N1', 'N2', 'N3'],
	BD: ['N1', 'N2', 'N3'],
	BE: ['N1'],
	BX: ['N1', 'N2', 'N3'],
	DA: ['O1', 'O2', 'O3', 'O4'],
	DB: ['O1', 'O2', 'O3', 'O4'],
	DC: ['O1', 'O2', 'O3', 'O4'],
	DE: ['O1', 'O2', 'O3', 'O4'],
	DF: ['O1', 'O2', 'O3', 'O4'],
	DG: ['O1', 'O2', 'O3', 'O4'],
	SA: ['M1', 'M2', 'M3'],
	SB: ['M1', 'M2', 'M3', 'N1', 'N2', 'N3', 'O1', 'O2', 'O3', 'O4'],
	SC: ['M1', 'M2', 'M3'],
	SD: ['M1', 'M2', 'M3'],
	SE: ['O1', 'O2', 'O3', 'O4'],
	SF: ['N3'],
	SG: ['M1', 'M2', 'M3', 'N1', 'N2', 'N3', 'O1', 'O2', 'O3', 'O4'],
	SH: ['M1'],
	SJ: ['O1', 'O2', 'O3', 'O4'],
	SK: ['O4'],
	SL: ['N3'],
	SM: ['N1G', 'N2G', 'N3G'],
}

/** The body codes a two-digit add-on may follow. */
export const WITH_ADD_ON = ['BA', 'BX', 'BC', 'BD', 'DA', 'DB', 'DC', 'DE', 'DF', 'DG']

export const ADD_ONS = [
	'01', '02', '03', '04', '05', '06', '07', '08', '09', '10', '11', '12', '13', '14', '15', '16',
	'17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32',
	'99',
]

/** The body groups, by the code's first letter, in Table B's order. */
const BODY_GROUPS = ['A', 'C', 'B', 'D', 'S']

/**
 * The user docs on the registration codes, behind the help line under each field. They are read
 * at the installed release's tag, so they describe the codes this build offers.
 */
export const REGISTRATION_HELP = `https://github.com/typical42/next-fleet/blob/v${version}/docs/user/vehicles.md#registration-type`

/** Which categories a vehicle type is offered first: what a person keeping one most likely has. */
const FIRST = {
	car: /^M1$/,
	van: /^N1$/,
	truck: /^N[23]$/,
	trailer: /^O/,
	motorcycle: /^L[1-4]e/,
	tractor: /^[TC]/,
}

/**
 * Which vehicle type a category makes plain. Narrower than FIRST: a three-wheel moped (L2e) is
 * offered to a motorcycle first, but is not plainly one.
 *
 * @type {[RegExp, string][]}
 */
const TYPES = [
	[/^M1$/, 'car'],
	[/^N1$/, 'van'],
	[/^N[23]$/, 'truck'],
	[/^O/, 'trailer'],
	[/^L[134]e/, 'motorcycle'],
	[/^[TC]/, 'tractor'],
]

/**
 * @typedef {object} GroupedOption
 * @property {string} id - the code, or a group's id on a header
 * @property {string} label - what the dropdown shows
 * @property {boolean} [header] - a group's title, not a choice
 * @property {string} [group] - the header an option sits under
 */

/**
 * Every category as a dropdown offers it, grouped under headers. What the vehicle type most likely
 * is comes first; nothing flows back from it.
 *
 * @param {string|undefined} vehicleType - the coarse class the sheet holds
 * @return {GroupedOption[]} headers and options, in order
 */
export function categoryOptions(vehicleType) {
	const first = FIRST[/** @type {keyof FIRST} */ (vehicleType)] ?? null
	const fits = (/** @type {string} */ code) => first !== null && first.test(code)
	const ahead = (/** @type {boolean} */ a, /** @type {boolean} */ b) => Number(b) - Number(a)
	const names = categoryWords()
	const titles = groupWords()

	return [...GROUPS]
		.sort((a, b) => ahead(a.codes.some(fits), b.codes.some(fits)))
		.flatMap((group) => [
			{ id: group.id, label: titles[group.id], header: true },
			...[...group.codes]
				.sort((a, b) => ahead(fits(a), fits(b)))
				.map((code) => ({ id: code, label: `${names[code]} (${code})`, group: group.id })),
		])
}

/**
 * What the dropdown shows for a search: the options whose label or code holds it, each under its
 * header. Case does not count: nobody types `L3e` exactly. Against the code, spaces, hyphens and
 * dots do not count either, since certificates print `L3e-A2` as `L3e A2`. The words keep theirs,
 * or `o4` would find "up to 40 km/h". A code that takes the off-road suffix is found with it too:
 * field J reads `N3G`, the list offers `N3` and the switch.
 *
 * @param {GroupedOption[]} options - categoryOptions(), bodyOptions() or addOnOptions()
 * @param {string} search - what was typed
 * @return {GroupedOption[]} the matches with their headers
 */
export function filterOptions(options, search) {
	const term = search.trim().toLowerCase()
	if (term === '') {
		return options
	}
	const code = squash(term)

	const hits = options.filter((one) => !one.header
		&& (one.label.toLowerCase().includes(term) || (code !== '' && squash(joinCategory(one.id, true)).includes(code))))

	return options.filter((one) => one.header ? hits.some((hit) => hit.group === one.id) : hits.includes(one))
}

/**
 * @param {string} text - a code or a search
 * @return {string} it lower-cased, without the marks a code may or may not be written with
 */
function squash(text) {
	// Pasted text may carry a typographic hyphen or dash (U+2010-2015) or a minus sign (U+2212).
	return text.toLowerCase().replace(/[\s.\-‐-―−]/g, '')
}

/**
 * @param {string|null|undefined} code - a category, off-road suffix included
 * @return {string|null} the vehicle type it makes plain, or null where it leaves the choice open
 */
export function typeOfCategory(code) {
	const base = splitCategory(code).code

	return base === null ? null : TYPES.find(([pattern]) => pattern.test(base))?.[1] ?? null
}

/**
 * @param {string|null|undefined} stored - the category as the vehicle carries it
 * @return {{ code: string|null, offRoad: boolean }} the code the dropdown offers, and the suffix
 */
export function splitCategory(stored) {
	if (!stored) {
		return { code: null, offRoad: false }
	}

	const base = stored.slice(0, -1)

	return stored.endsWith('G') && OFF_ROAD.includes(base)
		? { code: base, offRoad: true }
		: { code: stored, offRoad: false }
}

/**
 * @param {string|null} code - the category chosen
 * @param {boolean} offRoad - the switch beside it, which counts only where the suffix exists
 * @return {string} the category as the server stores it; empty clears it
 */
export function joinCategory(code, offRoad) {
	if (code === null) {
		return ''
	}

	return offRoad && OFF_ROAD.includes(code) ? `${code}G` : code
}

/**
 * The body codes a category takes, as a dropdown offers them, grouped under headers. None for an
 * L, T, C, R or S category, which has no body code, nor without a category.
 *
 * @param {string|null|undefined} category - the category as stored, off-road suffix included
 * @return {GroupedOption[]} headers and options, in order
 */
export function bodyOptions(category) {
	const names = bodyWords()
	const titles = bodyGroupWords()
	const fitting = Object.keys(BODY_TYPES).filter((code) => bodyFits(category, code))

	return BODY_GROUPS.flatMap((group) => {
		const codes = fitting.filter((code) => code.startsWith(group))

		return codes.length === 0
			? []
			: [
				{ id: group, label: titles[group], header: true },
				...codes.map((code) => ({ id: code, label: `${names[code]} (${code})`, group })),
			]
	})
}

/**
 * Whether a body code belongs to a category, as lib/Service/Registration.php judges it.
 *
 * @param {string|null|undefined} category - the category as stored, off-road suffix included
 * @param {string} body - a body code, add-on or not
 * @return {boolean} true where the pair may be saved
 */
export function bodyFits(category, body) {
	if (!category) {
		return false
	}
	const takes = BODY_TYPES[body.slice(0, 2)] ?? []
	const { code, offRoad } = splitCategory(category)

	return takes.includes(category) || (offRoad && takes.includes(code ?? ''))
}

/**
 * @param {string|null|undefined} code - a body code without its add-on
 * @return {boolean} true where an add-on may follow it
 */
export function takesAddOn(code) {
	return WITH_ADD_ON.includes(code ?? '')
}

/**
 * Every add-on as a dropdown offers it, label first.
 *
 * @return {{ id: string, label: string }[]} the options, in Table C's order
 */
export function addOnOptions() {
	const names = addOnWords()

	return ADD_ONS.map((code) => ({ id: code, label: `${names[code]} (${code})` }))
}

/**
 * @param {string|null|undefined} stored - the body type as the vehicle carries it
 * @return {{ code: string|null, addOn: string|null }} the code and its add-on, each offered apart
 */
export function splitBody(stored) {
	return stored
		? { code: stored.slice(0, 2), addOn: stored.slice(2) || null }
		: { code: null, addOn: null }
}

/**
 * @param {string|null} code - the body code chosen
 * @param {string|null} addOn - the add-on, which counts only after a code that takes one
 * @return {string} the body type as the server stores it; empty clears it
 */
export function joinBody(code, addOn) {
	if (code === null) {
		return ''
	}

	return addOn !== null && takesAddOn(code) ? `${code}${addOn}` : code
}

/**
 * @param {string|null|undefined} stored - the body type as the vehicle carries it
 * @return {string} its words, the add-on left out, or nothing for none
 */
export function bodyWord(stored) {
	const code = splitBody(stored).code

	return code === null ? '' : bodyWords()[code] ?? ''
}

/**
 * The groups' titles. Translated on call, like lifecycleWord() (src/utils/format.js).
 *
 * @return {Record<string, string>} each title by group id
 */
function groupWords() {
	return {
		cars: t('nextfleet', 'Cars and buses'),
		goods: t('nextfleet', 'Goods vehicles'),
		trailers: t('nextfleet', 'Trailers'),
		light: t('nextfleet', 'Two- and three-wheelers and quads'),
		agricultural: t('nextfleet', 'Agricultural and forestry'),
	}
}

/**
 * Each category in words, shortened from the regulations' definitions; the L names are theirs.
 * One literal per code, so the catalogue check finds every one (tests/Unit/TranslationsTest.php).
 *
 * @return {Record<string, string>} each category's words by code
 */
function categoryWords() {
	return {
		M1: t('nextfleet', 'Passenger car, up to 8 seats besides the driver'),
		M2: t('nextfleet', 'Bus, more than 8 seats besides the driver, up to 5 t'),
		M3: t('nextfleet', 'Bus, more than 8 seats besides the driver, over 5 t'),
		N1: t('nextfleet', 'Light goods vehicle, up to 3.5 t'),
		N2: t('nextfleet', 'Goods vehicle, over 3.5 t up to 12 t'),
		N3: t('nextfleet', 'Goods vehicle, over 12 t'),
		O1: t('nextfleet', 'Trailer, up to 0.75 t'),
		O2: t('nextfleet', 'Trailer, over 0.75 t up to 3.5 t'),
		O3: t('nextfleet', 'Trailer, over 3.5 t up to 10 t'),
		O4: t('nextfleet', 'Trailer, over 10 t'),
		L1e: t('nextfleet', 'Light two-wheel powered vehicle'),
		'L1e-A': t('nextfleet', 'Powered cycle'),
		'L1e-B': t('nextfleet', 'Two-wheel moped'),
		L2e: t('nextfleet', 'Three-wheel moped'),
		'L2e-P': t('nextfleet', 'Three-wheel moped for passenger transport'),
		'L2e-U': t('nextfleet', 'Three-wheel moped for utility purposes'),
		L3e: t('nextfleet', 'Two-wheel motorcycle'),
		'L3e-A1': t('nextfleet', 'Low-performance motorcycle'),
		'L3e-A1E': t('nextfleet', 'Low-performance enduro motorcycle'),
		'L3e-A1T': t('nextfleet', 'Low-performance trial motorcycle'),
		'L3e-A2': t('nextfleet', 'Medium-performance motorcycle'),
		'L3e-A2E': t('nextfleet', 'Medium-performance enduro motorcycle'),
		'L3e-A2T': t('nextfleet', 'Medium-performance trial motorcycle'),
		'L3e-A3': t('nextfleet', 'High-performance motorcycle'),
		'L3e-A3E': t('nextfleet', 'High-performance enduro motorcycle'),
		'L3e-A3T': t('nextfleet', 'High-performance trial motorcycle'),
		L4e: t('nextfleet', 'Two-wheel motorcycle with side-car'),
		L5e: t('nextfleet', 'Powered tricycle'),
		'L5e-A': t('nextfleet', 'Tricycle'),
		'L5e-B': t('nextfleet', 'Commercial tricycle'),
		L6e: t('nextfleet', 'Light quadricycle'),
		'L6e-A': t('nextfleet', 'Light on-road quad'),
		'L6e-B': t('nextfleet', 'Light quadri-mobile'),
		'L6e-BU': t('nextfleet', 'Light quadri-mobile for utility purposes'),
		'L6e-BP': t('nextfleet', 'Light quadri-mobile for passenger transport'),
		L7e: t('nextfleet', 'Heavy quadricycle'),
		'L7e-A': t('nextfleet', 'Heavy on-road quad'),
		'L7e-A1': t('nextfleet', 'A1 on-road quad'),
		'L7e-A2': t('nextfleet', 'A2 on-road quad'),
		'L7e-B': t('nextfleet', 'Heavy all-terrain quad'),
		'L7e-B1': t('nextfleet', 'All-terrain quad'),
		'L7e-B2': t('nextfleet', 'Side-by-side buggy'),
		'L7e-C': t('nextfleet', 'Heavy quadri-mobile'),
		'L7e-CU': t('nextfleet', 'Heavy quadri-mobile for utility purposes'),
		'L7e-CP': t('nextfleet', 'Heavy quadri-mobile for passenger transport'),
		// a: at most 40 km/h, b: more than that (Reg. (EU) 167/2013, Art. 4).
		T1a: t('nextfleet', 'Wheeled tractor, track width at least 1150 mm, up to 40 km/h'),
		T1b: t('nextfleet', 'Wheeled tractor, track width at least 1150 mm, over 40 km/h'),
		T2a: t('nextfleet', 'Wheeled tractor, track width under 1150 mm, up to 40 km/h'),
		T2b: t('nextfleet', 'Wheeled tractor, track width under 1150 mm, over 40 km/h'),
		T3a: t('nextfleet', 'Wheeled tractor, up to 600 kg unladen, up to 40 km/h'),
		T3b: t('nextfleet', 'Wheeled tractor, up to 600 kg unladen, over 40 km/h'),
		'T4.1a': t('nextfleet', 'High-clearance tractor, up to 40 km/h'),
		'T4.1b': t('nextfleet', 'High-clearance tractor, over 40 km/h'),
		'T4.2a': t('nextfleet', 'Extra-wide tractor, up to 40 km/h'),
		'T4.2b': t('nextfleet', 'Extra-wide tractor, over 40 km/h'),
		'T4.3a': t('nextfleet', 'Low-clearance tractor, up to 40 km/h'),
		'T4.3b': t('nextfleet', 'Low-clearance tractor, over 40 km/h'),
		C1a: t('nextfleet', 'Track-laying tractor, track width at least 1150 mm, up to 40 km/h'),
		C1b: t('nextfleet', 'Track-laying tractor, track width at least 1150 mm, over 40 km/h'),
		C2a: t('nextfleet', 'Track-laying tractor, track width under 1150 mm, up to 40 km/h'),
		C2b: t('nextfleet', 'Track-laying tractor, track width under 1150 mm, over 40 km/h'),
		C3a: t('nextfleet', 'Track-laying tractor, up to 600 kg unladen, up to 40 km/h'),
		C3b: t('nextfleet', 'Track-laying tractor, up to 600 kg unladen, over 40 km/h'),
		'C4.1a': t('nextfleet', 'High-clearance track-laying tractor, up to 40 km/h'),
		'C4.1b': t('nextfleet', 'High-clearance track-laying tractor, over 40 km/h'),
		'C4.2a': t('nextfleet', 'Extra-wide track-laying tractor, up to 40 km/h'),
		'C4.2b': t('nextfleet', 'Extra-wide track-laying tractor, over 40 km/h'),
		'C4.3a': t('nextfleet', 'Low-clearance track-laying tractor, up to 40 km/h'),
		'C4.3b': t('nextfleet', 'Low-clearance track-laying tractor, over 40 km/h'),
		R1a: t('nextfleet', 'Agricultural trailer, axle masses up to 1500 kg, up to 40 km/h'),
		R1b: t('nextfleet', 'Agricultural trailer, axle masses up to 1500 kg, over 40 km/h'),
		R2a: t('nextfleet', 'Agricultural trailer, over 1500 kg up to 3500 kg, up to 40 km/h'),
		R2b: t('nextfleet', 'Agricultural trailer, over 1500 kg up to 3500 kg, over 40 km/h'),
		R3a: t('nextfleet', 'Agricultural trailer, over 3500 kg up to 21000 kg, up to 40 km/h'),
		R3b: t('nextfleet', 'Agricultural trailer, over 3500 kg up to 21000 kg, over 40 km/h'),
		R4a: t('nextfleet', 'Agricultural trailer, over 21000 kg, up to 40 km/h'),
		R4b: t('nextfleet', 'Agricultural trailer, over 21000 kg, over 40 km/h'),
		S1a: t('nextfleet', 'Interchangeable towed equipment, up to 3500 kg, up to 40 km/h'),
		S1b: t('nextfleet', 'Interchangeable towed equipment, up to 3500 kg, over 40 km/h'),
		S2a: t('nextfleet', 'Interchangeable towed equipment, over 3500 kg, up to 40 km/h'),
		S2b: t('nextfleet', 'Interchangeable towed equipment, over 3500 kg, over 40 km/h'),
	}
}

/**
 * The body groups' titles, by the codes' first letter.
 *
 * @return {Record<string, string>} each title by group
 */
function bodyGroupWords() {
	return {
		A: t('nextfleet', 'Passenger cars'),
		C: t('nextfleet', 'Buses'),
		B: t('nextfleet', 'Goods vehicles'),
		D: t('nextfleet', 'Trailers'),
		S: t('nextfleet', 'Special purpose vehicles'),
	}
}

/**
 * Each body code in words, as Reg. (EU) 2018/858 Annex I Part C names it.
 *
 * @return {Record<string, string>} each body's words by code
 */
function bodyWords() {
	return {
		AA: t('nextfleet', 'Saloon'),
		AB: t('nextfleet', 'Hatchback'),
		AC: t('nextfleet', 'Station wagon'),
		AD: t('nextfleet', 'Coupé'),
		AE: t('nextfleet', 'Convertible'),
		AF: t('nextfleet', 'Multi-purpose vehicle'),
		AG: t('nextfleet', 'Truck station wagon'),
		CA: t('nextfleet', 'Single-deck vehicle'),
		CB: t('nextfleet', 'Double-deck vehicle'),
		CC: t('nextfleet', 'Single-deck articulated vehicle'),
		CD: t('nextfleet', 'Double-deck articulated vehicle'),
		CE: t('nextfleet', 'Low-floor single-deck vehicle'),
		CF: t('nextfleet', 'Low-floor double-deck vehicle'),
		CG: t('nextfleet', 'Articulated low-floor single-deck vehicle'),
		CH: t('nextfleet', 'Articulated low-floor double-deck vehicle'),
		CI: t('nextfleet', 'Open-top single-deck vehicle'),
		CJ: t('nextfleet', 'Open-top double-deck vehicle'),
		CX: t('nextfleet', 'Bus chassis'),
		BA: t('nextfleet', 'Lorry'),
		// Not the regulation's "Van": that key is the vehicle type's, translated differently.
		BB: t('nextfleet', 'Panel van'),
		BC: t('nextfleet', 'Tractor unit for semi-trailer'),
		BD: t('nextfleet', 'Road tractor'),
		BE: t('nextfleet', 'Pick-up truck'),
		BX: t('nextfleet', 'Chassis-cab or chassis-cowl'),
		DA: t('nextfleet', 'Semi-trailer'),
		DB: t('nextfleet', 'Drawbar trailer'),
		DC: t('nextfleet', 'Centre-axle trailer'),
		DE: t('nextfleet', 'Rigid drawbar trailer'),
		DF: t('nextfleet', 'Link semi-trailer'),
		DG: t('nextfleet', 'Link drawbar trailer'),
		SA: t('nextfleet', 'Motor caravan'),
		SB: t('nextfleet', 'Armoured vehicle'),
		SC: t('nextfleet', 'Ambulance'),
		SD: t('nextfleet', 'Hearse'),
		SE: t('nextfleet', 'Trailer caravan'),
		SF: t('nextfleet', 'Mobile crane'),
		SG: t('nextfleet', 'Special group'),
		SH: t('nextfleet', 'Wheelchair-accessible vehicle'),
		SJ: t('nextfleet', 'Converter dolly'),
		SK: t('nextfleet', 'Exceptional load transport trailer'),
		SL: t('nextfleet', 'Exceptional load transport motor vehicle'),
		SM: t('nextfleet', 'Multi-equipment carrier'),
	}
}

/**
 * Each add-on in words, as Appendix 2 names it.
 *
 * @return {Record<string, string>} each add-on's words by number
 */
function addOnWords() {
	return {
		'01': t('nextfleet', 'Flat bed'),
		'02': t('nextfleet', 'Drop-side'),
		'03': t('nextfleet', 'Box body'),
		'04': t('nextfleet', 'Conditioned body with insulated walls and equipment to maintain the interior temperature'),
		'05': t('nextfleet', 'Conditioned body with insulated walls but without equipment to maintain the interior temperature'),
		'06': t('nextfleet', 'Curtain-sided'),
		'07': t('nextfleet', 'Swap body (interchangeable superstructure)'),
		'08': t('nextfleet', 'Container carrier'),
		'09': t('nextfleet', 'Vehicle fitted with hook lift'),
		10: t('nextfleet', 'Tipper'),
		11: t('nextfleet', 'Tank'),
		12: t('nextfleet', 'Tank intended for transport of dangerous goods'),
		13: t('nextfleet', 'Livestock carrier'),
		14: t('nextfleet', 'Vehicle transporter'),
		15: t('nextfleet', 'Concrete mixer'),
		16: t('nextfleet', 'Concrete pump vehicle'),
		17: t('nextfleet', 'Timber'),
		18: t('nextfleet', 'Refuse collection vehicle'),
		19: t('nextfleet', 'Street sweeper, cleansing and drain clearing'),
		20: t('nextfleet', 'Compressor'),
		21: t('nextfleet', 'Boat carrier'),
		22: t('nextfleet', 'Glider carrier'),
		23: t('nextfleet', 'Vehicle for retail or display purposes'),
		24: t('nextfleet', 'Recovery vehicle'),
		25: t('nextfleet', 'Ladder vehicle'),
		26: t('nextfleet', 'Crane lorry (other than a mobile crane)'),
		27: t('nextfleet', 'Aerial work platform vehicle'),
		28: t('nextfleet', 'Digger derrick vehicle'),
		29: t('nextfleet', 'Low-floor trailer'),
		30: t('nextfleet', 'Glazing transporter'),
		31: t('nextfleet', 'Fire engine'),
		32: t('nextfleet', 'Drop-side tarpaulin'),
		99: t('nextfleet', 'Bodywork not included in this list'),
	}
}
