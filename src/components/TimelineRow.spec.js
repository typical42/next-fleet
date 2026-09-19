/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import TimelineRow from './TimelineRow.vue'

// The locale decides the separators and the date order, and it is the reader's rather than the
// language's (docs/ui.md#languages) - so it is pinned here, or every assertion below would depend
// on the machine the suite runs on.
// `t` stays the real one: with no catalogue loaded it answers in English, placeholders filled.
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(/** @type {object} */ (await importOriginal())),
	getCanonicalLocale: () => 'en-GB',
}))

const VEHICLE = { uuid: 'v-1', odo_unit: 'km' }

/** Half past midnight on the 3rd in Berlin, which is the evening of the 2nd in UTC. */
const NIGHT = { occurred_at: 1788391800, occurred_at_off: 120 }

/**
 * @param {object} entry - the row as the timeline hands it over
 * @param {object} [vehicle] - the vehicle it happened to
 * @return {import('@vue/test-utils').VueWrapper} the mounted row
 */
function row(entry, vehicle = VEHICLE) {
	return mount(TimelineRow, { props: { entry, vehicle } })
}

/**
 * @param {object} trip - the journey's own fields, over the ordinary ones
 * @param {object} [reading] - the Reading it left on the counter, or null for none
 * @return {object} the row a timeline page carries for it
 */
function journey(trip, reading = { value: 148402, origin: 'observed', flagged: false }) {
	return {
		...NIGHT,
		type: 'trip',
		trip: { uuid: 't-1', category: 'business', distance: null, end_odo: null, ...trip },
		reading,
	}
}

/**
 * @param {object} odometer - the Reading's own fields, over the ordinary ones
 * @return {object} the row a timeline page carries for it
 */
function counter(odometer) {
	return {
		...NIGHT,
		type: 'odometer',
		odometer: { uuid: 'r-1', origin: 'observed', flagged: false, ...odometer },
	}
}

/**
 * @param {string} type - energy, maintenance or expense
 * @param {object} fields - the Entry's own fields
 * @param {object} [extra] - what the row carries beside the Entry: readings, flags
 * @return {object} the row a timeline page carries for it
 */
function cost(type, fields, extra = {}) {
	return { ...NIGHT, type, [type]: { uuid: 'c-1', ...fields }, ...extra }
}

describe('a cost row', () => {
	const CAR = { ...VEHICLE, currency: 'EUR' }

	/** One figure the person gave (docs/ui.md): the amount, in its energy's unit. */
	it('names a fill-up by its energy and states the amount', () => {
		const wrapper = row(cost('energy', { energy: 'diesel', amount: 48200, total: 8210, full_tank: true, station: 'Aral Nord' }, { flags: [], readings: [] }), CAR)

		expect(wrapper.text()).toContain('Diesel')
		expect(wrapper.text()).toContain('48.2 l')
		expect(wrapper.text()).toContain('Aral Nord')
		expect(wrapper.text()).not.toContain('82.10')
		expect(wrapper.text()).not.toContain('Partial')
	})

	it('says a fill-up was partial or missed the one before', () => {
		const text = row(cost('energy', { energy: 'electric', amount: 13000, full_tank: false, missed_previous: true }, { flags: [], readings: [] }), CAR).text()

		expect(text).toContain('13 kWh')
		expect(text).toContain('Partial')
		expect(text).toContain('Previous fill-up not recorded')
	})

	/** A fill-up closing a segment also shows its consumption (docs/ui.md); one that closes none, nothing. */
	it('states the consumption of the segment a fill-up closes', () => {
		const closing = row(cost('energy', { energy: 'diesel', amount: 30000, full_tank: true }, {
			flags: [], readings: [], consumption: { value: 6.04, per: 'km' },
		}), CAR).text()
		const opening = row(cost('energy', { energy: 'diesel', amount: 30000, full_tank: true }, {
			flags: [], readings: [], consumption: null,
		}), CAR).text()

		expect(closing).toContain('6.0 l/100 km')
		expect(opening).not.toContain('/100 km')
	})

	/** Flags are computed on read and said as words, never as a colour alone (docs/ui.md). */
	it('says what a fill-up is flagged for, in words', () => {
		const text = row(cost('energy', { energy: 'diesel', amount: 90000, full_tank: true }, {
			flags: ['foreign_energy', 'no_price', 'overfilled'],
			readings: [{ value: 120000, counter: 'main', flagged: true }],
		}), CAR).text()

		expect(text).toContain('Not an energy this vehicle takes')
		expect(text).toContain('No price')
		expect(text).toContain('More than the vehicle holds')
		expect(text).toContain('In question')
	})

	it('names maintenance by its title and states its cost', () => {
		const text = row(cost('maintenance', { title: 'Brake pads', type: 'repair', vendor: 'Werkstatt Huber', cost: 31200 }, { readings: [] }), CAR).text()

		expect(text).toContain('Brake pads')
		expect(text).toContain('€312.00')
		expect(text).toContain('Repair')
		expect(text).toContain('Werkstatt Huber')
	})

	it('states nothing for maintenance nobody priced', () => {
		const wrapper = row(cost('maintenance', { title: 'Wipers', type: null, vendor: null, cost: null }, { readings: [] }), CAR)

		expect(wrapper.get('.row__figure').text()).toBe('')
	})

	it('names an expense by its category and states the amount', () => {
		expect(row(cost('expense', { category: 'parking', amount: 1200 }), CAR).text()).toContain('Parking')
		expect(row(cost('expense', { category: 'parking', amount: 1200 }), CAR).text()).toContain('€12.00')
		expect(row(cost('expense', { category: null, amount: 1200 }), { ...VEHICLE, currency: null }).text()).toContain('Expense')
	})
})

describe('a timeline row', () => {
	/**
	 * The day, the route and what the journey covered - that is the line in the sketch
	 * (docs/ui.md). The date is the one the offset puts it on, because a Fahrtenbuch is judged on
	 * local calendar dates (docs/architecture.md#time).
	 */
	it('says where a journey went and what it left on the counter', () => {
		const wrapper = row(journey({ from_label: 'München', to_label: 'Augsburg', end_odo: 148402 }))

		expect(wrapper.text()).toContain('03/09')
		// And the same day to anything reading the markup rather than the rendered text.
		expect(wrapper.find('time').attributes('datetime')).toBe('2026-09-03T01:30:00+02:00')
		expect(wrapper.text()).toContain('München → Augsburg')
		expect(wrapper.text()).toContain('148,402 km')
		expect(wrapper.text()).toContain('Business')
	})

	/**
	 * The kilometres and the counter are two facts and are never computed into one another
	 * (docs/architecture.md#odometer-rules), so the row states the one the driver gave. The Reading
	 * the server counted from it is on the row as well and is not a second figure to show.
	 */
	it('says the kilometres when that is what the driver gave', () => {
		const wrapper = row(journey(
			{ from_label: 'München', to_label: 'Augsburg', distance: 82 },
			{ value: 148402, origin: 'derived', flagged: false },
		))

		expect(wrapper.text()).toContain('82 km')
		expect(wrapper.text()).not.toContain('148,402')
	})

	/** A journey nobody labelled is still a journey, and the timeline still has to name it. */
	it('falls back to what the journey was for, and then to the word', () => {
		expect(row(journey({ purpose: 'Kundentermin', distance: 82 })).text()).toContain('Kundentermin')
		expect(row(journey({ distance: 82 })).text()).toContain('Trip')
	})

	/** The escape hatch is one number at one moment (docs/ui.md), and reads as one. */
	it('says what the counter was read at', () => {
		const wrapper = row(counter({ value: 148320 }))

		expect(wrapper.text()).toContain('148,320 km')
		expect(wrapper.text()).toContain('Counter reading')
	})

	/** An hour Reading is on the second chain (rule 4), and says it in that chain's unit. */
	it('says engine hours in hours', () => {
		const truck = { ...VEHICLE, second_unit: 'h' }

		expect(row(counter({ value: 5004, counter: 'second' }), truck).text()).toContain('5,004 h')
		expect(row(counter({ value: 148320, counter: 'main' }), truck).text()).toContain('148,320 km')
		// Switching the hours off keeps their Readings, and they still read in hours.
		expect(row(counter({ value: 5004, counter: 'second' }), { ...VEHICLE, second_unit: null }).text())
			.toContain('5,004 h')
	})

	/**
	 * A flagged Reading is a question the timeline is where people answer (docs/ui.md), so the row
	 * it sits on has to carry it - and carry it as a word, because status is never colour alone.
	 * A trip's question is its Reading's: the journey and the counter it moved are one row.
	 */
	it('carries the question a flagged reading asks, whichever row it sits on', () => {
		expect(row(counter({ value: 148320, flagged: true })).text()).toContain('In question')
		expect(row(journey({ distance: 82 }, { value: 148402, origin: 'derived', flagged: true })).text())
			.toContain('In question')
		expect(row(counter({ value: 148320 })).text()).not.toContain('In question')
	})

	/** The row asks for what the trip lacks in the words the entry sheet asked by. */
	it('asks an incomplete trip for what it lacks under Logbook Mode', () => {
		const wrapper = row(
			{ ...journey({ distance: 82 }), missing: ['start_odo', 'end_odo', 'partner'] },
			{ ...VEHICLE, logbook_mode: true },
		)

		expect(wrapper.text()).toContain('Incomplete')
		expect(wrapper.text()).toContain('Still missing: Start counter, End counter, Business partner')
	})

	/** docs/features.md#logbook-mode */
	it('says nothing about completeness off the mode, or of a complete trip', () => {
		const incomplete = { ...journey({ distance: 82 }), missing: ['partner'] }

		expect(row(incomplete).text()).not.toContain('Incomplete')
		expect(row(incomplete, { ...VEHICLE, logbook_mode: null }).text()).not.toContain('Still missing')
		expect(row({ ...journey({ distance: 82 }), missing: [] }, { ...VEHICLE, logbook_mode: true }).text())
			.not.toContain('Incomplete')
	})

	/**
	 * A Gap is closed one at a time (CONTEXT.md), so the offer sits on the trip whose claim opened it
	 * rather than on the month's total. The row only offers; the confirmation is the timeline's.
	 */
	it('offers to close the Gap before the trip that opened it', async () => {
		const gap = { trip: 't-1', distance: 1250, from_at: 1788220000, from_at_off: 120, to_at: 1788391800, to_at_off: 120 }
		const wrapper = mount(TimelineRow, {
			props: { entry: journey({ start_odo: 149652, end_odo: 149734 }), vehicle: { ...VEHICLE, logbook_mode: true }, gap },
		})

		expect(wrapper.text()).toContain('1,250 km unaccounted before this trip')
		await wrapper.get('.row__gap button').trigger('click')

		expect(wrapper.emitted('closeGap')).toEqual([[gap]])
	})

	/** The trip that closed a Gap is the app's arithmetic, and the row says so rather than pass it off as a journey. */
	it('says a trip was written to close a Gap', () => {
		expect(row(journey({ distance: 1250, category: 'private', reconciled: true })).text()).toContain('Reconciled')
		expect(row(journey({ distance: 82, reconciled: false })).text()).not.toContain('Reconciled')
	})

	it('offers nothing for a trip that opened no Gap', () => {
		const wrapper = row(journey({ distance: 82 }), { ...VEHICLE, logbook_mode: true })

		expect(wrapper.text()).not.toContain('Close gap')
		expect(wrapper.text()).not.toContain('unaccounted')
	})

	/**
	 * Every row opens its Entry for editing (docs/ui.md). The row's name is the button, so a
	 * keyboard and a screen reader reach it by what the row is called; the whole row is its target.
	 */
	it.each([
		['trip', journey({ distance: 82, from_label: 'Munich', to_label: 'Augsburg' }), 'Munich → Augsburg'],
		['odometer', counter({ value: 148320 }), 'Counter reading'],
		['expense', cost('expense', { amount: 1200, category: 'parking' }), 'Parking'],
	])('opens its %s when it is tapped', async (kind, entry, name) => {
		const wrapper = row(entry)

		const open = wrapper.find('.row__open')
		expect(open.element.tagName).toBe('BUTTON')
		expect(open.text()).toBe(name)
		await open.trigger('click')

		expect(wrapper.emitted('open')).toEqual([[entry]])
	})

	/** The Gap's own button is a second target on the same row, and it opens nothing else. */
	it('closes a Gap without opening the trip', async () => {
		const wrapper = row(journey({ distance: 82 }), { ...VEHICLE, logbook_mode: true })
		await wrapper.setProps({ gap: { trip: 't-1', distance: 40, from_at: 1, from_at_off: 0, to_at: 2, to_at_off: 0 } })

		await wrapper.get('.row__gap button').trigger('click')

		expect(wrapper.emitted('closeGap')).toHaveLength(1)
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	/**
	 * The unit is the vehicle's own, never a global switch (docs/ui.md): a generator is counted in
	 * hours, and the kilometres a car covered are hours on it.
	 */
	it('counts in the vehicle it happened to', () => {
		const wrapper = row(journey({ distance: 12 }), { uuid: 'v-2', odo_unit: 'h' })

		expect(wrapper.text()).toContain('12 h')
	})
})
