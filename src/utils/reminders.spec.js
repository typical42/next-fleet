/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'

import { addDays, addMonths, closedByDefault, firstInspection, fleetByUrgency, inspectionOf, light, monthEnd, openByUrgency, reminderTitle, rewrite } from './reminders.js'

/**
 * @param {any} fields - what differs from a planned reminder by date with no estimate
 * @return {any} a reminder as the list hands it over
 */
function reminder(fields) {
	return { uuid: fields.title, template_key: null, mode: 'date', due_date: null, due_odo: null, estimate: null, state: 'planned', ...fields }
}

describe('reminderTitle', () => {
	it('names a template in words and never as TÜV', () => {
		expect(reminderTitle(reminder({ template_key: 'hu_au', title: null }))).toBe('Technical inspection (HU/AU)')
		expect(reminderTitle(reminder({ template_key: 'oil_change', title: null }))).toBe('Oil change')
	})

	it('keeps a title the user typed as it is', () => {
		expect(reminderTitle(reminder({ title: 'Insurance renewal' }))).toBe('Insurance renewal')
	})
})

describe('openByUrgency', () => {
	it('puts overdue before due before warned before planned before snoozed', () => {
		const listed = ['snoozed', 'planned', 'warned', 'due', 'overdue']
			.map((state) => reminder({ title: state, state }))

		expect(openByUrgency(listed).map((one) => one.state)).toEqual(['overdue', 'due', 'warned', 'planned', 'snoozed'])
	})

	it('leaves out what is done or dismissed', () => {
		const listed = [reminder({ title: 'a', state: 'done' }), reminder({ title: 'b', state: 'dismissed' }), reminder({ title: 'c' })]

		expect(openByUrgency(listed).map((one) => one.title)).toEqual(['c'])
	})

	it('orders one state by the sooner day, a date or an estimate, and a reminder with neither last', () => {
		const listed = [
			reminder({ title: 'no day', mode: 'odo', due_odo: 150000 }),
			reminder({ title: 'december', due_date: '2026-12-31' }),
			reminder({ title: 'estimated november', mode: 'odo', due_odo: 150000, estimate: '2026-11-02' }),
			reminder({ title: 'either, estimate first', mode: 'either', due_date: '2027-06-30', due_odo: 150000, estimate: '2026-10-01' }),
		]

		expect(openByUrgency(listed).map((one) => one.title)).toEqual(['either, estimate first', 'estimated november', 'december', 'no day'])
	})

	it('does not reorder the list it was given', () => {
		const listed = [reminder({ title: 'b' }), reminder({ title: 'a', state: 'due' })]

		openByUrgency(listed)

		expect(listed.map((one) => one.title)).toEqual(['b', 'a'])
	})
})

describe('addDays and addMonths', () => {
	it('count on from a plain day', () => {
		expect(addDays('2026-09-28', 7)).toBe('2026-10-05')
		expect(addMonths('2026-09-22', 1)).toBe('2026-10-22')
	})

	it('clamp to the last day of a shorter month', () => {
		expect(addMonths('2027-01-31', 1)).toBe('2027-02-28')
		expect(addMonths('2028-01-31', 1)).toBe('2028-02-29')
	})
})

describe('inspectionOf', () => {
	it('is the open HU/AU reminder, and none once it is over', () => {
		const hu = reminder({ title: null, template_key: 'hu_au', due_date: '2027-05-31' })

		expect(inspectionOf([reminder({ title: 'Chain' }), hu])).toBe(hu)
		expect(inspectionOf([{ ...hu, state: 'dismissed' }])).toBeNull()
		expect(inspectionOf([])).toBeNull()
	})
})

describe('rewrite', () => {
	it('restates what an edit replaces, and only what the mode reads', () => {
		const hu = reminder({ template_key: 'hu_au', title: null, due_date: '2027-05-31', recur_months: 24, warn_month_before: true, warn_month_start: false, warn_due_date: true, state: 'warned' })

		expect(rewrite(hu)).toEqual({ mode: 'date', due_date: '2027-05-31', recur_months: 24, warn_month_before: true, warn_month_start: false, warn_due_date: true })
	})

	it('keeps a title the user typed, and the counter fields of a reminder by either', () => {
		const chain = reminder({ title: 'Chain', mode: 'either', due_date: '2027-01-31', due_odo: 160000, lead_odo: 500, recur_months: 12, recur_odo: 10000, warn_month_before: false, warn_month_start: true, warn_due_date: false })

		expect(rewrite(chain)).toEqual({ title: 'Chain', mode: 'either', due_date: '2027-01-31', recur_months: 12, due_odo: 160000, lead_odo: 500, recur_odo: 10000, warn_month_before: false, warn_month_start: true, warn_due_date: false })
	})
})

describe('monthEnd', () => {
	it('is the last day of the month on the sticker, leap years included', () => {
		expect(monthEnd(2027, 5)).toBe('2027-05-31')
		expect(monthEnd(2027, 4)).toBe('2027-04-30')
		expect(monthEnd(2028, 2)).toBe('2028-02-29')
		expect(monthEnd(2027, 12)).toBe('2027-12-31')
	})
})

describe('firstInspection', () => {
	it('is the month the first HU/AU falls in while it is still to come', () => {
		expect(firstInspection('2024-03-15', 36, '2026-09-22')).toEqual({ year: 2027, month: 3 })
	})

	it('still counts in its own month', () => {
		expect(firstInspection('2023-09-02', 36, '2026-09-22')).toEqual({ year: 2026, month: 9 })
	})

	it('is nothing once that month has passed, or without a first registration', () => {
		expect(firstInspection('2023-08-31', 36, '2026-09-22')).toBeNull()
		expect(firstInspection(null, 36, '2026-09-22')).toBeNull()
	})
})

describe('closedByDefault', () => {
	const oil = reminder({ title: 'oil', template_key: 'oil_change', state: 'due' })
	const tyres = reminder({ title: 'tyres', template_key: 'tyre_swap', state: 'warned' })
	const huAu = reminder({ title: 'hu', template_key: 'hu_au', state: 'planned' })

	it('is the most urgent open reminder when its template is that kind of work', () => {
		expect(closedByDefault([huAu, tyres, oil], 'service')).toBe('oil')
		expect(closedByDefault([huAu, oil], 'inspection')).toBeNull()
		expect(closedByDefault([huAu], 'inspection')).toBe('hu')
		expect(closedByDefault([tyres], 'tyres')).toBe('tyres')
	})

	it('is none without a type, for a reminder of the user\'s own title, or with nothing open', () => {
		expect(closedByDefault([oil], null)).toBeNull()
		expect(closedByDefault([reminder({ title: 'Towbar', state: 'due' })], 'upgrade')).toBeNull()
		expect(closedByDefault([reminder({ title: 'x', template_key: 'oil_change', state: 'done' })], 'service')).toBeNull()
	})
})

describe('fleetByUrgency', () => {
	/**
	 * @param {string} plate - what tells it apart
	 * @param {any} [fields] - what differs from an active vehicle
	 * @return {any} a vehicle as the store holds it
	 */
	function vehicle(plate, fields = {}) {
		return { uuid: plate, plate, lifecycle: 'active', ...fields }
	}

	/**
	 * @param {string} on - the vehicle's uuid
	 * @param {any} fields - as for `reminder()`
	 * @return {any} a reminder as the fleet's list hands it over
	 */
	function on(on, fields) {
		return { ...reminder(fields), vehicle: on }
	}

	it('puts each vehicle beside its most urgent open reminder, the most urgent vehicle first', () => {
		const vehicles = [vehicle('A'), vehicle('B'), vehicle('C')]
		const reminders = [
			on('A', { title: 'a planned', state: 'planned' }),
			on('B', { title: 'b warned', state: 'warned' }),
			on('B', { title: 'b overdue', state: 'overdue' }),
			on('C', { title: 'c done', state: 'done' }),
		]

		expect(fleetByUrgency(vehicles, reminders).map(({ vehicle, next }) => [vehicle.plate, next?.title ?? null]))
			.toEqual([['B', 'b overdue'], ['A', 'a planned'], ['C', null]])
	})

	it('orders vehicles that stand alike by the sooner day, then by plate', () => {
		const vehicles = [vehicle('M-Z 1'), vehicle('M-B 2'), vehicle('M-A 3'), vehicle('B-A 4')]
		const reminders = [
			on('M-Z 1', { title: 'z', state: 'warned', due_date: '2026-12-31' }),
			on('M-B 2', { title: 'b', state: 'warned', due_date: '2026-11-30' }),
		]

		expect(fleetByUrgency(vehicles, reminders).map(({ vehicle }) => vehicle.plate))
			.toEqual(['M-B 2', 'M-Z 1', 'B-A 4', 'M-A 3'])
	})

	it('keeps a laid-up vehicle below the ones still driven, however urgent', () => {
		const vehicles = [vehicle('A', { lifecycle: 'laid_up' }), vehicle('B')]
		const reminders = [on('A', { title: 'a', state: 'overdue' })]

		expect(fleetByUrgency(vehicles, reminders).map(({ vehicle }) => vehicle.plate)).toEqual(['B', 'A'])
	})
})

describe('light', () => {
	it('is red for due and overdue, amber for coming up, green for everything else', () => {
		expect(['overdue', 'due', 'warned', 'planned', 'snoozed'].map((state) => light(reminder({ state }))))
			.toEqual(['red', 'red', 'amber', 'green', 'green'])
		expect(light(null)).toBe('green')
	})
})
