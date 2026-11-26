/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { ConflictError, deletePartner, mergePartner, NotFoundError, readPartnerTrips, RefusedError, updatePartner } from '../services/api.js'
import PartnerDetail from './PartnerDetail.vue'

// The CSV's address stays real: which file the button saves is the point.
vi.mock('../services/api.js', async (original) => ({
	...await original(),
	deletePartner: vi.fn(),
	mergePartner: vi.fn(),
	readPartnerTrips: vi.fn(),
	updatePartner: vi.fn(),
}))
vi.mock('@nextcloud/router', () => ({
	generateUrl: (/** @type {string} */ path) => `/index.php${path}`,
}))

const MUELLER = { uuid: 'p-1', user_id: 'owner', name: 'Müller GmbH', address: 'Hauptstraße 1\nStuttgart', note: null, created_at: 1, updated_at: 10 }
const VARIANT = { uuid: 'p-2', user_id: 'owner', name: 'Mueller GmbH', address: null, note: null, created_at: 2, updated_at: 20 }
const WEBER = { uuid: 'p-3', user_id: 'owner', name: 'Weber AG', address: null, note: null, created_at: 3, updated_at: 30 }
const PASSAT = { uuid: 'v-1', updated_at: 1, plate: 'B-XY 123', odo_unit: 'km', may: ['view', 'own'] }
const TRACTOR = { uuid: 'v-2', updated_at: 1, plate: 'B-TR 1', odo_unit: 'h', may: ['view', 'own'] }

/** 2026-03-02 09:00 in Berlin, 120 km by the counter; the driver typed a variant. */
const BY_COUNTER = { uuid: 't-1', started_at: 1772438400, started_at_off: 60, ended_at: 1772445600, ended_at_off: 60, start_odo: 1000, end_odo: 1120, distance: null, from_label: 'Stuttgart', to_label: 'Pforzheim', purpose: 'Delivery', partner: 'Mueller GmbH', partner_uuid: 'p-1', category: 'business', reconciled: false }
/** 2026-05-04, 3 hours by distance on the tractor. */
const BY_DISTANCE = { uuid: 't-2', started_at: 1777888800, started_at_off: 120, ended_at: 1777899600, ended_at_off: 120, start_odo: null, end_odo: null, distance: 3, from_label: null, to_label: 'Field 4', purpose: null, partner: 'Müller GmbH', partner_uuid: 'p-1', category: 'business', reconciled: false }

/** @type {import('../services/api.js').PartnerTrips} */
const TRIPS = {
	partner: MUELLER,
	trips: [{ vehicle_uuid: 'v-1', distance: 120, trip: BY_COUNTER }, { vehicle_uuid: 'v-2', distance: 3, trip: BY_DISTANCE }],
	distance: [{ per: 'km', trips: 1, distance: 120 }, { per: 'h', trips: 1, distance: 3 }],
}

/**
 * One partner opened, past its first read of the trips.
 *
 * @param {object} [props] - instead of the defaults
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper
 */
async function opened(props = {}) {
	const wrapper = shallowMount(PartnerDetail, {
		props: { partner: MUELLER, partners: [VARIANT, MUELLER, WEBER], vehicles: [PASSAT, TRACTOR], ...props },
		global: {
			renderStubDefaultSlot: true,
			stubs: { NcDialog: { props: ['name'], template: '<div class="dialog"><h3>{{ name }}</h3><slot /><slot name="actions" /></div>' } },
		},
	})
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted detail
 * @param {string} label - what the button says
 * @return {any} the button
 */
function button(wrapper, label) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted detail
 * @param {string} label - the field's label
 * @return {any} the field
 */
function field(wrapper, label) {
	/** @type {any[]} */
	const fields = [...wrapper.findAllComponents(NcTextField), ...wrapper.findAllComponents(NcTextArea)]
	return fields.find((one) => one.props('label') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted detail
 * @param {string} label - the select's label
 * @return {any} the select
 */
function select(wrapper, label) {
	/** @type {any[]} */
	const selects = wrapper.findAllComponents(NcSelect)
	return selects.find((one) => one.props('inputLabel') === label)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.useFakeTimers({ toFake: ['Date'] })
	vi.setSystemTime(new Date(2026, 5, 15, 12))
	vi.mocked(readPartnerTrips).mockResolvedValue(TRIPS)
})

afterEach(() => {
	vi.useRealTimers()
})

describe('a partner, opened', () => {
	it('shows the record in fields to change', async () => {
		const wrapper = await opened()

		expect(wrapper.get('h2').text()).toBe('Müller GmbH')
		expect(field(wrapper, 'Name').props('modelValue')).toBe('Müller GmbH')
		expect(field(wrapper, 'Address').props('modelValue')).toBe('Hauptstraße 1\nStuttgart')
		expect(field(wrapper, 'Note').props('modelValue')).toBe('')
	})

	it('writes the whole record, an empty field as none, and hands the answer up', async () => {
		const saved = { ...MUELLER, name: 'Müller Maschinenbau GmbH', address: null, updated_at: 11 }
		vi.mocked(updatePartner).mockResolvedValue(saved)
		const wrapper = await opened()

		await field(wrapper, 'Name').vm.$emit('update:modelValue', ' Müller Maschinenbau GmbH ')
		await field(wrapper, 'Address').vm.$emit('update:modelValue', '  ')
		await field(wrapper, 'Note').vm.$emit('update:modelValue', 'Gate 3')
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(updatePartner).toHaveBeenCalledWith(MUELLER, { name: 'Müller Maschinenbau GmbH', address: null, note: 'Gate 3' })
		expect(wrapper.emitted('saved')).toEqual([[saved]])
	})

	it('does not save a partner without a name', async () => {
		const wrapper = await opened()

		await field(wrapper, 'Name').vm.$emit('update:modelValue', '   ')

		expect(button(wrapper, 'Save').props('disabled')).toBe(true)
		expect(field(wrapper, 'Name').props('error')).toBe(true)
		expect(field(wrapper, 'Name').props('helperText')).toBe('A partner needs a name.')
	})

	/** Two records of one name would be one partner twice: merging is how they become one. */
	it('says to merge when the new name is taken', async () => {
		vi.mocked(updatePartner).mockRejectedValue(new RefusedError('Another partner has this name', 'name_taken'))
		const wrapper = await opened()

		await field(wrapper, 'Name').vm.$emit('update:modelValue', 'Mueller GmbH')
		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('Another partner in your list has this name. To make them one, merge them.')
		expect(wrapper.emitted('saved')).toBeUndefined()
	})

	/** What the server holds now comes back with a new read of the list. */
	it('offers to load the partner again when it changed since it was read', async () => {
		vi.mocked(updatePartner).mockRejectedValue(new ConflictError('Changed since you read it'))
		const wrapper = await opened()

		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This partner was changed after you opened it.')

		await button(wrapper, 'Load again').vm.$emit('click')

		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('shows the record again when it comes anew', async () => {
		const wrapper = await opened()
		await field(wrapper, 'Note').vm.$emit('update:modelValue', 'typed')

		await wrapper.setProps({ partner: { ...MUELLER, note: 'Gate 3', updated_at: 12 } })

		expect(field(wrapper, 'Note').props('modelValue')).toBe('Gate 3')
	})

	/** Two records written in one second share a token; after a merge the other one is shown. */
	it('shows another record in full, whatever its token', async () => {
		const wrapper = await opened()

		await wrapper.setProps({ partner: { ...VARIANT, updated_at: MUELLER.updated_at } })

		expect(field(wrapper, 'Name').props('modelValue')).toBe('Mueller GmbH')
		expect(field(wrapper, 'Address').props('modelValue')).toBe('')
	})

	/** The same token would be refused again; a new read brings the next one. */
	it('saves nothing more until the partner is loaded again', async () => {
		vi.mocked(updatePartner).mockRejectedValue(new ConflictError('Changed since you read it'))
		const wrapper = await opened()

		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()

		expect(button(wrapper, 'Save').props('disabled')).toBe(true)
	})

	it('goes back to the list', async () => {
		const wrapper = await opened()

		await button(wrapper, 'Back to the partners').vm.$emit('click')

		expect(wrapper.emitted('back')).toHaveLength(1)
	})
})

describe('merging a partner', () => {
	it('offers the other partners of the list', async () => {
		const wrapper = await opened()

		expect(select(wrapper, 'Merge into').props('options').map((/** @type {{label: string}} */ one) => one.label))
			.toEqual(['Mueller GmbH', 'Weber AG'])
		expect(button(wrapper, 'Merge…').props('disabled')).toBe(true)
	})

	/** There is no undo, so the owner confirms what moves and what stays. */
	it('asks first, then merges and hands up what is left', async () => {
		vi.mocked(mergePartner).mockResolvedValue({ ...VARIANT, updated_at: 21 })
		const wrapper = await opened()

		await select(wrapper, 'Merge into').vm.$emit('update:modelValue', { id: 'p-2', label: 'Mueller GmbH' })
		await button(wrapper, 'Merge…').vm.$emit('click')

		const dialog = wrapper.get('.dialog')
		expect(dialog.get('h3').text()).toBe('Merge partners')
		expect(dialog.text()).toContain('The trips of "Müller GmbH" will then belong to "Mueller GmbH". "Müller GmbH" is removed from the list.')
		expect(dialog.text()).toContain('What the trips say stays as it was typed. You cannot undo this.')
		expect(mergePartner).not.toHaveBeenCalled()

		await button(wrapper, 'Merge').vm.$emit('click')
		await flushPromises()

		expect(mergePartner).toHaveBeenCalledWith(MUELLER, 'p-2')
		expect(wrapper.emitted('merged')).toEqual([[{ gone: 'p-1', into: { ...VARIANT, updated_at: 21 } }]])
	})

	/** The screen goes on to the record it went into, which cannot be merged into itself. */
	it('picks nothing to merge into once another partner is shown', async () => {
		const wrapper = await opened()
		await select(wrapper, 'Merge into').vm.$emit('update:modelValue', { id: 'p-2', label: 'Mueller GmbH' })

		await wrapper.setProps({ partner: VARIANT })

		expect(select(wrapper, 'Merge into').props('modelValue')).toBeNull()
		expect(button(wrapper, 'Merge…').props('disabled')).toBe(true)
	})

	it('merges nothing when the owner cancels', async () => {
		const wrapper = await opened()

		await select(wrapper, 'Merge into').vm.$emit('update:modelValue', { id: 'p-2', label: 'Mueller GmbH' })
		await button(wrapper, 'Merge…').vm.$emit('click')
		await button(wrapper, 'Cancel').vm.$emit('click')

		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(mergePartner).not.toHaveBeenCalled()
	})
})

describe('deleting a partner', () => {
	/** A delete is how a private person's erasure is met; the logbook keeps its text (§ 147 AO). */
	it('asks first, then deletes and hands up which one went', async () => {
		vi.mocked(deletePartner).mockResolvedValue({ uuid: 'p-1', deleted_at: 40 })
		const wrapper = await opened()

		await button(wrapper, 'Delete…').vm.$emit('click')

		const dialog = wrapper.get('.dialog')
		expect(dialog.get('h3').text()).toBe('Delete partner')
		expect(dialog.text()).toContain('The name, the address and the note of "Müller GmbH" are deleted.')
		expect(dialog.text()).toContain('What the trips say stays, because the logbook must be kept. You cannot undo this.')

		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(deletePartner).toHaveBeenCalledWith(MUELLER)
		expect(wrapper.emitted('deleted')).toEqual([['p-1']])
	})

	/** The form behind the question holds the way out, so the question offers it too. */
	it('offers to load the partner again from the question when it changed since it was read', async () => {
		vi.mocked(deletePartner).mockRejectedValue(new ConflictError('Changed since you read it'))
		const wrapper = await opened()

		await button(wrapper, 'Delete…').vm.$emit('click')
		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()
		const offered = wrapper.get('.dialog').findAllComponents(NcButton).map((/** @type {any} */ one) => one.text())
		expect(offered).toEqual(['Cancel', 'Load again'])
		await wrapper.get('.dialog').findAllComponents(NcButton)[1].vm.$emit('click')

		expect(wrapper.emitted('reload')).toHaveLength(1)
		expect(wrapper.find('.dialog').exists()).toBe(false)
	})

	/** Merged or deleted in another tab: a new read of the list takes it off the screen. */
	it('offers to load the list again when the partner is gone', async () => {
		vi.mocked(updatePartner).mockRejectedValue(new NotFoundError('No such partner'))
		const wrapper = await opened()

		await button(wrapper, 'Save').vm.$emit('click')
		await flushPromises()
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('This partner no longer exists.')
		expect(button(wrapper, 'Save').props('disabled')).toBe(true)

		await button(wrapper, 'Load again').vm.$emit('click')

		expect(wrapper.emitted('reload')).toHaveLength(1)
	})

	it('offers to load the list again when the partner to merge into is gone', async () => {
		vi.mocked(mergePartner).mockRejectedValue(new RefusedError('into is not on the list', 'target_gone'))
		const wrapper = await opened()

		await select(wrapper, 'Merge into').vm.$emit('update:modelValue', { id: 'p-2', label: 'Mueller GmbH' })
		await button(wrapper, 'Merge…').vm.$emit('click')
		await button(wrapper, 'Merge').vm.$emit('click')
		await flushPromises()

		const dialog = wrapper.get('.dialog')
		expect(dialog.findComponent(NcNoteCard).props('text')).toBe('The partner to merge into no longer exists.')
		expect(dialog.findAllComponents(NcButton).map((/** @type {any} */ one) => one.text())).toEqual(['Cancel', 'Load again'])
		await dialog.findAllComponents(NcButton)[1].vm.$emit('click')

		// The open partner itself is as it was: its form stays usable, and the gone target is no choice.
		expect(wrapper.emitted('reload')).toHaveLength(1)
		expect(wrapper.find('.dialog').exists()).toBe(false)
		expect(button(wrapper, 'Save').props('disabled')).toBe(false)
		expect(button(wrapper, 'Load again')).toBeUndefined()
		expect(button(wrapper, 'Merge…').props('disabled')).toBe(true)
	})

	it('says why the delete failed, in the question', async () => {
		vi.mocked(deletePartner).mockRejectedValue(new Error('The server answered 500'))
		const wrapper = await opened()

		await button(wrapper, 'Delete…').vm.$emit('click')
		await button(wrapper, 'Delete').vm.$emit('click')
		await flushPromises()

		expect(wrapper.get('.dialog').findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(wrapper.emitted('deleted')).toBeUndefined()
	})
})

describe('the trips of a partner', () => {
	it('reads this calendar year', async () => {
		await opened()

		expect(readPartnerTrips).toHaveBeenCalledWith('p-1', { from: '2026-01-01', to: '2027-01-01' })
	})

	/** What the trip says is what the logbook prints, a variant spelling included. */
	it('lists each trip with its vehicle, its route, what it says and how far it went', async () => {
		const wrapper = await opened()

		const rows = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
		expect(rows).toEqual([
			['03/02/2026', 'B-XY 123', 'Stuttgart → Pforzheim', 'Mueller GmbH', '120 km'],
			['05/04/2026', 'B-TR 1', 'Field 4', 'Müller GmbH', '3 h'],
		])
	})

	/** At 320 px the table scrolls on its own, and a keyboard must reach what scrolls. */
	it('puts the table in a named region a keyboard can scroll', async () => {
		const wrapper = await opened()

		const region = wrapper.get('table').element.parentElement
		expect([region?.getAttribute('role'), region?.getAttribute('aria-label'), region?.getAttribute('tabindex')])
			.toEqual(['region', 'Trips', '0'])
	})

	/** A counter read backwards has no distance; the server says so, and the sum counts it as none. */
	it('shows the distance the server counts for each trip', async () => {
		const backwards = { ...BY_COUNTER, start_odo: 1120, end_odo: 1000 }
		vi.mocked(readPartnerTrips).mockResolvedValue({ ...TRIPS, trips: [{ vehicle_uuid: 'v-1', distance: null, trip: backwards }] })

		const wrapper = await opened()

		expect(wrapper.findAll('tbody td').at(4)?.text()).toBe('')
	})

	/** A late refusal for a period no longer picked must not hide the trips of the one that is. */
	it('drops the answer to a period no longer picked, a failure too', async () => {
		/** @type {(reason: Error) => void} */
		let fail = () => {}
		vi.mocked(readPartnerTrips).mockReturnValueOnce(new Promise((resolve, reject) => {
			fail = reject
		}))
		const wrapper = await opened()

		await select(wrapper, 'Period').vm.$emit('update:modelValue', { id: 'all', label: 'All time' })
		await flushPromises()
		fail(new Error('The server answered 500'))
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		expect(wrapper.findAll('tbody tr')).toHaveLength(2)
	})

	it('sums the trips per unit', async () => {
		const wrapper = await opened()

		expect(wrapper.findAll('.partner__total').map((one) => one.text())).toEqual(['Trips: 1, distance: 120 km', 'Trips: 1, distance: 3 h'])
	})

	it('reads the period picked and saves its CSV', async () => {
		const wrapper = await opened()

		await select(wrapper, 'Period').vm.$emit('update:modelValue', { id: 'all', label: 'All time' })
		await flushPromises()

		expect(readPartnerTrips).toHaveBeenLastCalledWith('p-1', {})
		expect(button(wrapper, 'Save as CSV').props('href')).toBe('/index.php/apps/nextfleet/partners/p-1/csv')
	})

	it('says when the period holds no trip', async () => {
		vi.mocked(readPartnerTrips).mockResolvedValue({ partner: MUELLER, trips: [], distance: [] })

		const wrapper = await opened()

		expect(wrapper.find('table').exists()).toBe(false)
		expect(wrapper.text()).toContain('No trips with this partner in this period.')
	})

	it('says why the trips did not come', async () => {
		vi.mocked(readPartnerTrips).mockRejectedValue(new Error('The server answered 500'))

		const wrapper = await opened()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
	})
})
