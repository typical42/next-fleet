/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// @vitest-environment node
// It reads the server's code list, which node:fs cannot do from happy-dom's http `import.meta.url`.
import { readFileSync } from 'node:fs'
import { describe, expect, it, vi } from 'vitest'
import FITS from '../../tests/Fixture/registration-fits.json'
import { ADD_ONS, BODY_TYPES, CATEGORIES, OFF_ROAD, REGISTRATION_HELP, WITH_ADD_ON, addOnOptions, bodyFits, bodyOptions, bodyWord, categoryOptions, filterOptions, joinBody, joinCategory, splitBody, splitCategory, takesAddOn, typeOfCategory } from './registration.js'

/** The catalogue the labels come from; empty is English. */
const language = vi.hoisted(() => ({ translations: /** @type {Record<string, string>} */ ({}) }))

vi.mock('@nextcloud/l10n', () => ({
	t: (/** @type {string} */ app, /** @type {string} */ text) => language.translations[text] ?? text,
}))

/**
 * The codes a PHP constant lists, read from its source: the server validates against them.
 *
 * @param {string} name - the constant in lib/Service/Registration.php
 * @return {string[]} its codes, in order
 */
function server(name) {
	const source = readFileSync(new URL('../../lib/Service/Registration.php', import.meta.url), 'utf8')
	const body = source.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`))?.[1] ?? ''

	return [...body.matchAll(/'([^']+)'/g)].map((match) => match[1])
}

/**
 * @param {{ id: string, header?: boolean }[]} options - what the dropdown offers
 * @return {string[]} the codes alone, headers left out
 */
function codes(options) {
	return options.filter((one) => !one.header).map((one) => one.id)
}

describe('the EU categories', () => {
	it('are the codes the server takes', () => {
		expect(CATEGORIES).toEqual(server('CATEGORIES'))
		expect(OFF_ROAD).toEqual(server('OFF_ROAD'))
	})

	it('read label first, code second', () => {
		const options = categoryOptions('car')

		expect(options.find((one) => one.id === 'M1')?.label).toBe('Passenger car, up to 8 seats besides the driver (M1)')
		expect(options.find((one) => one.id === 'L3e-A2E')?.label).toBe('Medium-performance enduro motorcycle (L3e-A2E)')
		expect(options.find((one) => one.id === 'T4.1b')?.label).toBe('High-clearance tractor, over 40 km/h (T4.1b)')
		expect(options.find((one) => one.id === 'C1a')?.label).toBe('Track-laying tractor, track width at least 1150 mm, up to 40 km/h (C1a)')
	})

	it('offer every code once, each under its group', () => {
		const options = categoryOptions('generator')

		expect([...codes(options)].sort()).toEqual([...CATEGORIES].sort())
		expect(options.filter((one) => one.header).map((one) => one.label)).toEqual([
			'Cars and buses', 'Goods vehicles', 'Trailers', 'Two- and three-wheelers and quads', 'Agricultural and forestry',
		])
		expect(options.at(0)?.header).toBe(true)
	})

	it.each([
		['car', 'Cars and buses', ['M1']],
		['van', 'Goods vehicles', ['N1']],
		['truck', 'Goods vehicles', ['N2', 'N3']],
		['trailer', 'Trailers', ['O1', 'O2', 'O3', 'O4']],
		['motorcycle', 'Two- and three-wheelers and quads', ['L1e', 'L1e-A', 'L1e-B', 'L2e', 'L2e-P', 'L2e-U', 'L3e']],
		['tractor', 'Agricultural and forestry', ['T1a', 'T1b']],
	])('put what a %s is first', (type, group, first) => {
		const options = categoryOptions(type)

		expect(options.at(0)?.label).toBe(group)
		expect(codes(options).slice(0, first.length)).toEqual(first)
	})

	it('keep their order for a generator', () => {
		expect(codes(categoryOptions('generator')).slice(0, 4)).toEqual(['M1', 'M2', 'M3', 'N1'])
	})
})

describe('filterOptions', () => {
	it('finds a category by its words or its code, under its group', () => {
		const options = categoryOptions('car')

		expect(filterOptions(options, 'side-car').map((one) => one.label)).toEqual([
			'Two- and three-wheelers and quads', 'Two-wheel motorcycle with side-car (L4e)',
		])
		expect(codes(filterOptions(options, 'n3'))).toEqual(['N3'])
		expect(codes(filterOptions(options, 'L7E-C'))).toEqual(['L7e-C', 'L7e-CU', 'L7e-CP'])
	})

	it('shows everything when nothing is typed, and no group when nothing matches', () => {
		const options = categoryOptions('car')

		expect(filterOptions(options, ' ')).toEqual(options)
		expect(filterOptions(options, 'hovercraft')).toEqual([])
	})

	it.each(['L3e A2', 'l3ea2', 'L3E-A2', 'l3e.a2'])('finds a code typed as %s, spaces, hyphens and dots aside', (search) => {
		expect(codes(filterOptions(categoryOptions('car'), search))).toEqual(['L3e-A2', 'L3e-A2E', 'L3e-A2T'])
	})

	it('finds a code pasted with a typographic dash', () => {
		expect(codes(filterOptions(categoryOptions('car'), 'L3e‑A2E'))).toEqual(['L3e-A2E'])
		expect(codes(filterOptions(categoryOptions('car'), 'L3e–A2E'))).toEqual(['L3e-A2E'])
	})

	it('finds an off-road category typed with its suffix', () => {
		expect(codes(filterOptions(categoryOptions('truck'), 'N3G'))).toEqual(['N3'])
		expect(codes(filterOptions(categoryOptions('car'), 'm1 g'))).toEqual(['M1'])
	})

	it('finds a tractor code typed without its dot', () => {
		expect(codes(filterOptions(categoryOptions('tractor'), 'T41a'))).toEqual(['T4.1a'])
	})

	it('does not join words to find a code', () => {
		expect(codes(filterOptions(categoryOptions('car'), 'o4'))).toEqual(['O4'])
	})

	it('finds an add-on by its words or its number', () => {
		expect(filterOptions(addOnOptions(), 'curtain').map((one) => one.id)).toEqual(['06'])
		expect(filterOptions(addOnOptions(), '0 3').map((one) => one.id)).toEqual(['03'])
	})

	it('finds a body by its German words', () => {
		language.translations = JSON.parse(readFileSync(new URL('../../l10n/de.json', import.meta.url), 'utf8')).translations
		try {
			expect(codes(filterOptions(bodyOptions('M1'), 'kombi'))).toEqual(['AC'])
		} finally {
			language.translations = {}
		}
	})
})

describe('typeOfCategory', () => {
	it.each([
		['M1', 'car'],
		['M1G', 'car'],
		['N1', 'van'],
		['N2', 'truck'],
		['N3G', 'truck'],
		['O1', 'trailer'],
		['O4', 'trailer'],
		['L1e-B', 'motorcycle'],
		['L3e', 'motorcycle'],
		['L4e', 'motorcycle'],
		['T4.2a', 'tractor'],
		['C1b', 'tractor'],
	])('takes %s for a %s', (code, type) => {
		expect(typeOfCategory(code)).toBe(type)
	})

	it.each(['M2', 'M3', 'L2e', 'L5e-A', 'L7e-B2', 'R1a', 'S2b', null])('decides nothing for %s', (code) => {
		expect(typeOfCategory(code)).toBeNull()
	})
})

describe('the off-road suffix', () => {
	it('splits from the code and joins back on', () => {
		expect(splitCategory('N3G')).toEqual({ code: 'N3', offRoad: true })
		expect(splitCategory('M1')).toEqual({ code: 'M1', offRoad: false })
		expect(splitCategory(null)).toEqual({ code: null, offRoad: false })
		expect(joinCategory('N3', true)).toBe('N3G')
		expect(joinCategory('N3', false)).toBe('N3')
		expect(joinCategory(null, true)).toBe('')
	})

	it('is no part of a category that has none', () => {
		expect(joinCategory('O2', true)).toBe('O2')
		expect(joinCategory('L3e', true)).toBe('L3e')
	})
})

/**
 * A PHP constant that maps each code to a string, read from its source like server().
 *
 * @param {string} name - the constant in lib/Service/Registration.php
 * @return {string[][]} its pairs, in order
 */
function serverPairs(name) {
	const source = readFileSync(new URL('../../lib/Service/Registration.php', import.meta.url), 'utf8')
	const body = source.match(new RegExp(`const ${name} = \\[([^\\]]*)\\]`))?.[1] ?? ''

	return [...body.matchAll(/'([^']+)' => '([^']+)'/g)].map((match) => [match[1], match[2]])
}

describe('the EU body types', () => {
	it('are the codes, categories and add-ons the server takes', () => {
		expect(Object.entries(BODY_TYPES).map(([code, categories]) => [code, categories.join(' ')]))
			.toEqual(serverPairs('BODY_TYPES'))
		expect(WITH_ADD_ON).toEqual(server('WITH_ADD_ON'))
		expect(ADD_ONS).toEqual(server('ADD_ONS'))
	})

	it('offer what fits a passenger car, label first, under their groups', () => {
		expect(bodyOptions('M1').map((one) => one.label)).toEqual([
			'Passenger cars',
			'Saloon (AA)', 'Hatchback (AB)', 'Station wagon (AC)', 'Coupé (AD)', 'Convertible (AE)',
			'Multi-purpose vehicle (AF)', 'Truck station wagon (AG)',
			'Special purpose vehicles',
			'Motor caravan (SA)', 'Armoured vehicle (SB)', 'Ambulance (SC)', 'Hearse (SD)', 'Special group (SG)',
			'Wheelchair-accessible vehicle (SH)',
		])
	})

	it.each([
		['N1', ['BA', 'BB', 'BC', 'BD', 'BE', 'BX', 'SB', 'SG']],
		['N3', ['BA', 'BB', 'BC', 'BD', 'BX', 'SB', 'SF', 'SG', 'SL']],
		['N3G', ['BA', 'BB', 'BC', 'BD', 'BX', 'SB', 'SF', 'SG', 'SL', 'SM']],
		['M2G', ['CA', 'CB', 'CC', 'CD', 'CE', 'CF', 'CG', 'CH', 'CI', 'CJ', 'CX', 'SA', 'SB', 'SC', 'SD', 'SG']],
		['O4', ['DA', 'DB', 'DC', 'DE', 'DF', 'DG', 'SB', 'SE', 'SG', 'SJ', 'SK']],
	])('offer for %s only what fits it', (category, fits) => {
		expect(codes(bodyOptions(category))).toEqual(fits)
	})

	it.each(['L3e', 'T1a', 'C4.3b', 'R2b', 'S1a', null])('offer nothing for %s', (category) => {
		expect(bodyOptions(category)).toEqual([])
	})

	it('are found by their words or their code', () => {
		expect(codes(filterOptions(bodyOptions('M1'), 'wagon'))).toEqual(['AC', 'AG'])
		expect(codes(filterOptions(bodyOptions('M1'), '(ac)'))).toEqual(['AC'])
	})
})

// The pairs tests/Unit/Service/VehicleServiceTest.php judges too, so the sheet offers what the server takes.
describe('bodyFits', () => {
	it.each(FITS)('judges $case ($category with $body)', ({ category, body, fits }) => {
		expect(bodyFits(category, body)).toBe(fits)
	})
})

describe('the add-on', () => {
	it('follows only the codes Table C names', () => {
		expect(['BA', 'BX', 'BC', 'BD', 'DA', 'DB', 'DC', 'DE', 'DF', 'DG'].every(takesAddOn)).toBe(true)
		expect(['BB', 'BE', 'AC', 'SG', null].some(takesAddOn)).toBe(false)
	})

	it('reads label first, code second', () => {
		const options = addOnOptions()

		expect(options.at(0)).toEqual({ id: '01', label: 'Flat bed (01)' })
		expect(options.find((one) => one.id === '32')?.label).toBe('Drop-side tarpaulin (32)')
		expect(options.at(-1)).toEqual({ id: '99', label: 'Bodywork not included in this list (99)' })
		expect(options.map((one) => one.id)).toEqual(ADD_ONS)
	})

	it('splits from the code and joins back on', () => {
		expect(splitBody('BA03')).toEqual({ code: 'BA', addOn: '03' })
		expect(splitBody('SL')).toEqual({ code: 'SL', addOn: null })
		expect(splitBody(null)).toEqual({ code: null, addOn: null })
		expect(joinBody('BA', '03')).toBe('BA03')
		expect(joinBody('BA', null)).toBe('BA')
		expect(joinBody(null, '03')).toBe('')
	})

	it('is no part of a code that takes none', () => {
		expect(joinBody('BB', '03')).toBe('BB')
	})
})

describe('bodyWord', () => {
	it('names the body, not its add-on', () => {
		expect(bodyWord('AC')).toBe('Station wagon')
		expect(bodyWord('BA03')).toBe('Lorry')
		expect(bodyWord('BB')).toBe('Panel van')
		expect(bodyWord(null)).toBe('')
		expect(bodyWord('ZZ')).toBe('')
	})
})

/**
 * The rows of one table in docs/user/vehicles.md#registration-type: the codes of the first cell,
 * and the cells after it. The help line under each field links there.
 *
 * @param {string} heading - the table's heading, below "Registration type"
 * @return {{ codes: string[], cells: string[] }[]} its rows, in order
 */
function documented(heading) {
	const doc = readFileSync(new URL('../../docs/user/vehicles.md', import.meta.url), 'utf8')
	const section = doc.split(`#### ${heading}\n`)[1]?.split(/\n#{2,4} /)[0] ?? ''

	return section.split('\n')
		.filter((line) => line.startsWith('| `'))
		.map((line) => line.trim().replace(/^\|\s*|\s*\|$/g, '').split(/\s*\|\s*/))
		.map(([first, ...cells]) => ({ codes: [...first.matchAll(/`([^`]+)`/g)].map((match) => match[1]), cells }))
}

/**
 * The docs say each a/b pair once; the sheet names the speed in each label.
 *
 * @param {string} code - a category code
 * @return {string} what the sheet's label adds for the speed letter, if the code has one
 */
function speedOf(code) {
	if (!/^[TCRS]/.test(code)) {
		return ''
	}

	return code.endsWith('a') ? ', up to 40 km/h' : ', over 40 km/h'
}

describe('the user docs', () => {
	it('list every category with the words the sheet offers', () => {
		const rows = documented('Vehicle categories')
		const labels = Object.fromEntries(categoryOptions('generator').map((one) => [one.id, one.label]))

		expect(rows.flatMap((row) => row.codes)).toEqual(CATEGORIES)
		for (const row of rows) {
			for (const code of row.codes) {
				expect(`${row.cells[0]}${speedOf(code)} (${code})`).toBe(labels[code])
			}
		}
	})

	it('list every body type with its words and the categories it fits', () => {
		const rows = documented('Body types')

		expect(rows.flatMap((row) => row.codes)).toEqual(Object.keys(BODY_TYPES))
		for (const { codes: [code], cells: [words, categories] } of rows) {
			expect(words).toBe(bodyWord(code))
			expect(categories.split(', ')).toEqual(BODY_TYPES[code])
		}
	})

	it('list every add-on with its words', () => {
		const rows = documented('Body add-ons')

		expect(rows.map((row) => `${row.cells[0]} (${row.codes[0]})`)).toEqual(addOnOptions().map((one) => one.label))
	})

	it('are linked as tagged for the version installed', () => {
		const { version } = JSON.parse(readFileSync(new URL('../../package.json', import.meta.url), 'utf8'))

		expect(REGISTRATION_HELP).toBe(`https://github.com/typical42/next-fleet/blob/v${version}/docs/user/vehicles.md#registration-type`)
	})
})
