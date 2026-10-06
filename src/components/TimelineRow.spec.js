/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

import { savePaper } from '../utils/papers.js'
import TimelineRow from './TimelineRow.vue'

vi.mock('../utils/papers.js', () => ({ savePaper: vi.fn(async () => '') }))

// The locale decides separators and date order (docs/ui.md#languages); pinned, or every assertion
// would depend on the machine. `t` stays real: with no catalogue it answers in English.
vi.mock('@nextcloud/l10n', async (importOriginal) => ({
	...(/** @type {object} */ (await importOriginal())),
	getCanonicalLocale: () => 'en-GB',
}))

const VEHICLE = { uuid: 'v-1', odo_unit: 'km', may: ['view', 'log', 'edit', 'delete', 'own'] }

/** Half past midnight on the 3rd in Berlin, which is the evening of the 2nd in UTC. */
const NIGHT = { occurred_at: 1788391800, occurred_at_off: 120 }
/** What the owner may do to every row (TimelineService::withMay()). */
const CHANGEABLE = { may: ['edit', 'delete'] }

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
		...CHANGEABLE,
		type: 'trip',
		trip: { uuid: 't-1', category: 'business', distance: null, end_odo: null, ...trip },
		reading,
	}
}

/**
 * @param {object} odometer - the Reading's own fields, over the ordinary ones
 * @return {{odometer: object} & Record<string, unknown>} the row a timeline page carries for it
 */
function counter(odometer) {
	return {
		...NIGHT,
		...CHANGEABLE,
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
	return { ...NIGHT, ...CHANGEABLE, type, [type]: { uuid: 'c-1', ...fields }, ...extra }
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
	 * The date is the one the offset puts it on: a Fahrtenbuch is judged on local calendar dates
	 * (docs/architecture.md#time).
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

	/** The Reading the server counted from the kilometres is not a second figure to show. */
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

	/** A trip's question is its Reading's: the journey and the counter it moved are one row. */
	it('carries the question a flagged reading asks, whichever row it sits on', () => {
		expect(row(counter({ value: 148320, flagged: true })).text()).toContain('In question')
		expect(row(journey({ distance: 82 }, { value: 148402, origin: 'derived', flagged: true })).text())
			.toContain('In question')
		expect(row(counter({ value: 148320 })).text()).not.toContain('In question')
	})

	/**
	 * Rule 3 (docs/architecture.md#odometer-rules): a typo is fixed in the sheet, so it opens it.
	 */
	it('asks whether a lower reading is a counter replaced or a typo', async () => {
		const entry = counter({ value: 30, flagged: true, updated_at: 1788391900 })
		const wrapper = row(entry)

		expect(wrapper.text()).toContain('Was the counter replaced, or is this a typo?')
		const [replaced, typo] = wrapper.findAll('.row__question button')
		expect(replaced.text()).toBe('Counter replaced')
		expect(typo.text()).toBe('Typo')

		await replaced.trigger('click')
		await typo.trigger('click')

		expect(wrapper.emitted('reset')).toEqual([[entry.odometer]])
		expect(wrapper.emitted('open')).toEqual([[entry]])
	})

	/** A fill-up's question is the Reading it left on the counter that is in question. */
	it('answers for the reading in question on a fill-up', async () => {
		const flagged = { uuid: 'r-2', value: 30, counter: 'main', origin: 'observed', flagged: true, updated_at: 1788391900 }
		const wrapper = row(cost('energy', { amount: 40000, energy: 'diesel' }, { readings: [flagged] }))

		await wrapper.get('.row__question button').trigger('click')

		expect(wrapper.emitted('reset')).toEqual([[flagged]])
	})

	/** Only an Observed Reading asks it, and only of whoever may change the Entry (OdometerService::reset()). */
	it('asks no such question of a derived reading, or of a reader who may not change the entry', () => {
		expect(row(journey({ distance: 82 }, { value: 148402, origin: 'derived', flagged: true })).find('.row__question').exists())
			.toBe(false)
		expect(row({ ...counter({ value: 30, flagged: true }), may: [] }).find('.row__question').exists()).toBe(false)
	})

	/** The row asks for what the trip lacks in the words the entry sheet asked by. */
	it('asks an incomplete trip for what it lacks under Logbook Mode', () => {
		const wrapper = row(
			{ ...journey({ distance: 82 }), missing: ['start_odo', 'end_odo', 'partner'] },
			{ ...VEHICLE, logbook_mode: true },
		)

		expect(wrapper.text()).toContain('Incomplete')
		expect(wrapper.text()).toContain('Still missing: Odometer at departure, Odometer at arrival, Business partner')
		expect(row(
			{ ...journey({ distance: 82 }), missing: ['start_odo'] },
			{ ...VEHICLE, odo_unit: 'h', logbook_mode: true },
		).text()).toContain('Still missing: Start counter')
	})

	/** docs/features.md#logbook-mode */
	it('says nothing about completeness off the mode, or of a complete trip', () => {
		const incomplete = { ...journey({ distance: 82 }), missing: ['partner'] }

		expect(row(incomplete).text()).not.toContain('Incomplete')
		expect(row(incomplete, { ...VEHICLE, logbook_mode: null }).text()).not.toContain('Still missing')
		expect(row({ ...journey({ distance: 82 }), missing: [] }, { ...VEHICLE, logbook_mode: true }).text())
			.not.toContain('Incomplete')
	})

	/** The row only offers; the confirmation is the timeline's. */
	it('offers to close the Gap before the trip that opened it', async () => {
		const gap = { trip: 't-1', distance: 1250, from_at: 1788220000, from_at_off: 120, to_at: 1788391800, to_at_off: 120 }
		const wrapper = mount(TimelineRow, {
			props: { entry: journey({ start_odo: 149652, end_odo: 149734 }), vehicle: { ...VEHICLE, logbook_mode: true }, gap },
		})

		expect(wrapper.text()).toContain('1,250 km unaccounted for before this trip')
		await wrapper.get('.row__gap button').trigger('click')

		expect(wrapper.emitted('closeGap')).toEqual([[gap]])
	})

	/** The trip that closed a Gap is the app's arithmetic, not a journey, and the row says so. */
	it('says a trip was written to close a Gap', () => {
		expect(row(journey({ distance: 1250, category: 'private', reconciled: true })).text()).toContain('Reconciled')
		expect(row(journey({ distance: 82, reconciled: false })).text()).not.toContain('Reconciled')
	})

	/** One vehicle, one journey at a time: two trips sharing a span are a mistake in one of them. */
	it('says a trip overlaps another', () => {
		const text = row({ ...journey({ distance: 82 }), flags: ['overlap'] }).text()

		expect(text).toContain('Overlaps another trip')
		expect(text).not.toContain('Reconciliation overtaken')
	})

	it('offers to void a reconciliation a later trip overtook', async () => {
		const entry = { ...journey({ distance: 1250, category: 'private', reconciled: true }), flags: ['overlap', 'overtaken'] }
		const wrapper = row(entry)

		expect(wrapper.text()).toContain('Reconciliation overtaken')
		expect(wrapper.text()).not.toContain('Overlaps another trip')
		await wrapper.get('.row__gap button').trigger('click')

		expect(wrapper.emitted('void')).toEqual([[entry]])
	})

	it('offers no void to a reader who may not delete the reconciliation', () => {
		const entry = { ...journey({ distance: 1250, reconciled: true }), flags: ['overlap', 'overtaken'], may: [] }

		expect(row(entry).find('.row__gap button').exists()).toBe(false)
	})

	it('offers nothing for a trip that opened no Gap', () => {
		const wrapper = row(journey({ distance: 82 }), { ...VEHICLE, logbook_mode: true })

		expect(wrapper.text()).not.toContain('Close gap')
		expect(wrapper.text()).not.toContain('unaccounted')
	})

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

	/** It still says everything it says to anyone. */
	it('opens nothing the reader may not change', async () => {
		const wrapper = row({ ...journey({ distance: 82, from_label: 'Munich', to_label: 'Augsburg' }), may: [] })

		expect(wrapper.find('button').exists()).toBe(false)
		expect(wrapper.find('.row__open').text()).toBe('Munich → Augsburg')
		await wrapper.find('.row').trigger('click')
		expect(wrapper.emitted('open')).toBeUndefined()
	})

	it('says who entered it when the server names them', () => {
		expect(row({ ...counter({ value: 120500 }), entered_by: 'Ben Fahrer' }).text()).toContain('Entered by Ben Fahrer')
		expect(row(cost('maintenance', { title: 'Wipers', cost: null }, { readings: [], entered_by: 'erased:k3x9' })).text()).toContain('Entered by erased:k3x9')
	})

	it('names nobody when the server names nobody', () => {
		expect(row({ ...counter({ value: 120500 }), entered_by: null }).text()).not.toContain('Entered by')
	})

	/** Closing a Gap writes a trip, which takes `log`; the Gap is said to anyone. */
	it('offers closing a Gap only to someone who logs', () => {
		const gap = { trip: 't-1', distance: 40, from_at: 1, from_at_off: 0, to_at: 2, to_at_off: 0 }
		const wrapper = mount(TimelineRow, {
			props: { entry: journey({ distance: 82 }), vehicle: { ...VEHICLE, logbook_mode: true, may: ['view'] }, gap },
		})

		expect(wrapper.text()).toContain('40 km unaccounted for before this trip')
		expect(wrapper.find('.row__gap button').exists()).toBe(false)
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

describe('the papers on a row', () => {
	const invoice = { uuid: 'd-1', kind: 'receipt', file_id: 42, name: 'invoice.pdf', mime: 'application/pdf', linked_type: 'maintenance', linked_uuid: 'c-1' }
	const work = cost('maintenance', { title: 'Inspection', cost: 18000 })

	/** A linked document opens from its entry's row, through our download and not the file's share. */
	it('opens each paper linked to the entry from a paperclip', async () => {
		const wrapper = mount(TimelineRow, { props: { entry: work, vehicle: VEHICLE, papers: [invoice] } })

		const clip = wrapper.get('.row__papers a')
		expect(clip.attributes('href')).toContain('/apps/nextfleet/vehicles/v-1/documents/d-1')
		expect(clip.attributes('aria-label')).toBe('Open invoice.pdf')
		await clip.trigger('click')
		expect(wrapper.emitted('open')).toBeUndefined()
		expect(savePaper).toHaveBeenCalledWith('v-1', invoice)
	})

	/** Followed, a refusal would replace the app with a page of JSON (src/utils/papers.js). */
	it('says on the row why a paper was not saved', async () => {
		vi.mocked(savePaper).mockResolvedValueOnce('This document is gone: it was removed, or its file is no longer in the Files of whoever attached it.')
		const wrapper = mount(TimelineRow, { props: { entry: work, vehicle: VEHICLE, papers: [invoice] } })

		await wrapper.get('.row__papers a').trigger('click')
		await flushPromises()

		expect(wrapper.get('.row__papers').text()).toContain('This document is gone')
	})

	/** A file deleted or moved out of its attacher's own Files has no link to follow, and the row says so. */
	it('says a linked file is gone', () => {
		const wrapper = mount(TimelineRow, { props: { entry: work, vehicle: VEHICLE, papers: [{ ...invoice, name: null, mime: null }] } })

		expect(wrapper.find('.row__papers a').exists()).toBe(false)
		expect(wrapper.get('.row__papers').text()).toContain('The file is no longer in the Files of whoever attached it')
	})

	it('shows no paperclip on a row without papers', () => {
		expect(row(work).find('.row__papers').exists()).toBe(false)
	})
})
