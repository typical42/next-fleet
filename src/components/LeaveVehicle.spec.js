/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { flushPromises, mount, shallowMount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { NotFoundError, leaveVehicle, readHeld } from '../services/api.js'
import { useVehiclesStore } from '../store/index.js'
import LeaveVehicle from './LeaveVehicle.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	getVehicle: vi.fn(),
	leaveVehicle: vi.fn(),
	readHeld: vi.fn(),
}))

const DRIVER = { uuid: 'v-1', updated_at: 1700000000, plate: 'B-XY 123', may: ['view', 'log'] }
const CREW = { grantee: 'crew', display_name: 'R&D', role: 'viewer' }

/**
 * @param {object} vehicle - the vehicle the screen shows
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once it read what is held
 */
async function section(vehicle = DRIVER) {
	const wrapper = shallowMount(LeaveVehicle, {
		props: { vehicle },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} text - what the button says
 * @return {any} the button, or undefined when there is none saying that
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @return {(string|undefined)[]} what its note cards say
 */
function notes(wrapper) {
	return wrapper.findAllComponents(NcNoteCard).map((one) => one.props('text'))
}

beforeEach(() => {
	setActivePinia(createPinia())
	vi.resetAllMocks()
	vi.mocked(readHeld).mockResolvedValue({ role: 'driver', groups: [] })
})

describe('leaving a vehicle', () => {
	it('offers the owner nothing', async () => {
		const wrapper = await section({ ...DRIVER, may: ['view', 'log', 'edit', 'delete', 'own'] })

		expect(readHeld).not.toHaveBeenCalled()
		expect(wrapper.text()).toBe('')
	})

	it('asks before a direct grantee leaves, and leaves on yes', async () => {
		const store = useVehiclesStore()
		store.upsert(DRIVER)
		vi.mocked(leaveVehicle).mockResolvedValue({ role: null, groups: [] })
		const wrapper = await section()

		await button(wrapper, 'Leave vehicle').trigger('click')
		expect(leaveVehicle).not.toHaveBeenCalled()
		expect(notes(wrapper)).toHaveLength(1)
		await button(wrapper, 'Leave').trigger('click')
		await flushPromises()

		expect(readHeld).toHaveBeenCalledWith('v-1')
		expect(leaveVehicle).toHaveBeenCalledWith('v-1')
		expect(store.byUuid.has('v-1')).toBe(false)
		expect(wrapper.emitted('kept')).toBeUndefined()
	})

	it('stays when the answer is no', async () => {
		const wrapper = await section()

		await button(wrapper, 'Leave vehicle').trigger('click')
		await button(wrapper, 'Stay').trigger('click')

		expect(leaveVehicle).not.toHaveBeenCalled()
		expect(notes(wrapper)).toEqual([])
		expect(button(wrapper, 'Leave vehicle')).toBeDefined()
	})

	it('names the group a grantee reaches the vehicle through, with no button', async () => {
		vi.mocked(readHeld).mockResolvedValue({ role: null, groups: [CREW] })
		const wrapper = await section()

		expect(wrapper.text()).toContain('R&D')
		expect(wrapper.text()).toContain('Only the owner can change that.')
		expect(button(wrapper, 'Leave vehicle')).toBeUndefined()
	})

	it('names the group that still reaches the vehicle after leaving', async () => {
		vi.mocked(readHeld).mockResolvedValue({ role: 'manager', groups: [CREW] })
		vi.mocked(leaveVehicle).mockResolvedValue({ role: null, groups: [CREW] })
		const wrapper = await section()

		await button(wrapper, 'Leave vehicle').trigger('click')
		await button(wrapper, 'Leave').trigger('click')
		await flushPromises()

		expect(wrapper.text()).toContain('R&D')
		expect(button(wrapper, 'Leave vehicle')).toBeUndefined()
		expect(wrapper.emitted('kept')).toHaveLength(1)
	})

	it('moves focus to the group that still reaches the vehicle after leaving', async () => {
		vi.mocked(readHeld).mockResolvedValue({ role: 'manager', groups: [CREW] })
		vi.mocked(leaveVehicle).mockResolvedValue({ role: null, groups: [CREW] })
		const wrapper = mount(LeaveVehicle, { props: { vehicle: DRIVER }, attachTo: document.body })
		await flushPromises()

		await wrapper.get('.leave__trigger').trigger('click')
		await flushPromises()
		await /** @type {HTMLElement} */ (document.querySelectorAll('.leave__answers button')[1]).click()
		await flushPromises()

		expect(document.activeElement).toBe(wrapper.get('.leave__group').element)
		wrapper.unmount()
	})

	it('moves focus to the question and back to the button that asked it', async () => {
		const wrapper = mount(LeaveVehicle, { props: { vehicle: DRIVER }, attachTo: document.body })
		await flushPromises()

		await wrapper.get('.leave__trigger').trigger('click')
		await flushPromises()
		expect(document.activeElement?.textContent?.trim()).toBe('Stay')

		await /** @type {HTMLElement} */ (document.activeElement).click()
		await flushPromises()
		expect(document.activeElement?.textContent?.trim()).toBe('Leave vehicle')
		wrapper.unmount()
	})

	/** The question stays up, so trying again is the one click it was. */
	it('says why a leave was refused and keeps the question', async () => {
		vi.mocked(leaveVehicle).mockRejectedValue(new Error('The vehicle\'s owner said no'))
		const wrapper = await section()

		await button(wrapper, 'Leave vehicle').trigger('click')
		await button(wrapper, 'Leave').trigger('click')
		await flushPromises()

		expect(notes(wrapper)).toContain('You could not leave: The vehicle\'s owner said no')
		expect(button(wrapper, 'Leave')).toBeDefined()
	})

	/** Revoked meanwhile: 403 without another way in, 404 with a group or with the vehicle gone. */
	it.each([
		['Not yours', new Error('Not yours')],
		['No such vehicle', new NotFoundError('No such vehicle')],
	])('says so when there is no grant left to give back (%s)', async (message, error) => {
		vi.mocked(leaveVehicle).mockRejectedValue(error)
		const wrapper = await section()

		await button(wrapper, 'Leave vehicle').trigger('click')
		await button(wrapper, 'Leave').trigger('click')
		await flushPromises()

		expect(notes(wrapper)).toContain('You have no access of your own to give back any more.')
	})

	it('reads again for another vehicle, and offers the owner nothing there', async () => {
		const wrapper = await section()
		await button(wrapper, 'Leave vehicle').trigger('click')
		vi.mocked(readHeld).mockResolvedValue({ role: null, groups: [CREW] })

		await wrapper.setProps({ vehicle: { ...DRIVER, uuid: 'v-2' } })
		await flushPromises()
		expect(readHeld).toHaveBeenLastCalledWith('v-2')
		expect(wrapper.text()).toContain('R&D')

		await wrapper.setProps({ vehicle: { ...DRIVER, uuid: 'v-3', may: ['view', 'log', 'edit', 'delete', 'own'] } })
		await flushPromises()
		expect(readHeld).toHaveBeenCalledTimes(2)
		expect(wrapper.text()).toBe('')
	})

	/** Whoever they hand a trip to is named, read-only: no uid and no button for it. */
	it('lists who can see the vehicle by name and role', async () => {
		vi.mocked(readHeld).mockResolvedValue({
			role: 'driver',
			groups: [],
			holders: [
				{ display_name: 'Anna A.', grantee_type: 'user', role: 'owner' },
				{ display_name: 'Ben B.', grantee_type: 'user', role: 'driver' },
				{ display_name: 'R&D', grantee_type: 'group', role: 'viewer' },
			],
		})
		const wrapper = mount(LeaveVehicle, { props: { vehicle: DRIVER } })
		await flushPromises()

		expect(wrapper.get('.leave__holders summary').text()).toBe('Who can see this vehicle')
		expect(wrapper.findAll('.leave__holders li').map((one) => one.text())).toEqual([
			'Anna A. · Owner',
			'Ben B. · Driver',
			'Group R&D · Viewer',
		])
	})

	it('shows nothing when what is held could not be read', async () => {
		vi.mocked(readHeld).mockRejectedValue(new Error('The server answered 503'))
		const wrapper = await section()

		expect(wrapper.text()).toBe('')
	})
})
