/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcSelectUsers from '@nextcloud/vue/components/NcSelectUsers'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { addGrant, changeGrant, listGrants, revokeGrant, searchGrantees } from '../services/api.js'
import VehicleGrants from './VehicleGrants.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	addGrant: vi.fn(),
	changeGrant: vi.fn(),
	listGrants: vi.fn(),
	revokeGrant: vi.fn(),
	searchGrantees: vi.fn(),
}))

const OWNED = { uuid: 'v-1', may: ['view', 'log', 'edit', 'delete', 'own'] }

/** @type {import('../services/api.js').Grant} */
const ANNA = { uuid: 'g-1', grantee: 'anna', grantee_type: 'user', display_name: 'Anna O\'Brien', role: 'driver' }
/** @type {import('../services/api.js').Grant} */
const CREW = { uuid: 'g-2', grantee: 'crew', grantee_type: 'group', display_name: 'R&D', role: 'viewer' }

/**
 * @param {object} [vehicle] - the vehicle the sheet edits
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the section, once its list was read
 */
async function section(vehicle = OWNED) {
	const wrapper = shallowMount(VehicleGrants, {
		props: { vehicle },
		global: { renderStubDefaultSlot: true },
	})
	await flushPromises()
	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} label - the select's label, visible or not
 * @return {any} the select
 */
function select(wrapper, label) {
	return wrapper.findAllComponents(NcSelect)
		.find((/** @type {any} */ one) => one.props('inputLabel') === label || one.props('ariaLabelCombobox') === label)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @param {string} text - the button's words, or its label where it shows fewer
 * @return {any} the button
 */
function button(wrapper, text) {
	return wrapper.findAllComponents(NcButton).find((one) => one.text() === text || one.props('ariaLabel') === text)
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the section
 * @return {string[]} each grant's line, as shown
 */
function rows(wrapper) {
	return wrapper.findAll('.grants__name').map((one) => one.text())
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listGrants).mockResolvedValue([ANNA, CREW])
	vi.mocked(searchGrantees).mockResolvedValue([])
})

describe('the Access section', () => {
	it('lists each grant with its name, its type and its role', async () => {
		const wrapper = await section()

		expect(listGrants).toHaveBeenCalledWith('v-1')
		expect(rows(wrapper)).toEqual(['Anna O\'Brien', 'R&D'])
		expect(wrapper.findAll('.grants__type').map((one) => one.text())).toEqual(['Account', 'Group'])
		expect(select(wrapper, 'Role of Anna O\'Brien').props('modelValue').label).toBe('Driver')
		expect(select(wrapper, 'Role of R&D').props('modelValue').label).toBe('Viewer')
	})

	it('shows nothing and asks nothing of somebody who does not own the vehicle', async () => {
		const wrapper = await section({ uuid: 'v-1', may: ['view', 'log', 'edit', 'delete'] })

		expect(listGrants).not.toHaveBeenCalled()
		expect(wrapper.html()).toBe('<!--v-if-->')
	})

	it('says when nobody else has access', async () => {
		vi.mocked(listGrants).mockResolvedValue([])

		const wrapper = await section()

		expect(wrapper.text()).toContain('Only you have access to this vehicle.')
	})

	it('offers the accounts and groups core finds, telling them apart', async () => {
		vi.mocked(searchGrantees).mockResolvedValue([
			{ grantee: 'ben', grantee_type: 'user', display_name: 'Ben' },
			{ grantee: 'r&d', grantee_type: 'group', display_name: 'Ben\'s team' },
		])
		const wrapper = await section()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'ben')
		await flushPromises()

		expect(searchGrantees).toHaveBeenCalledWith('ben')
		const options = wrapper.findComponent(NcSelectUsers).props('options')
		expect(options.map((/** @type {any} */ one) => one.id)).toEqual(['user:ben', 'group:r&d'])
		expect(options[0]).toMatchObject({ displayName: 'Ben', user: 'ben' })
		expect(options[1]).toMatchObject({ displayName: 'Ben\'s team', subname: 'Group r&d', isNoUser: true })
	})

	it('keeps the matches for what was typed last, whichever answer comes last', async () => {
		/** @type {(found: any[]) => void} */
		let answerFirst = () => {}
		vi.mocked(searchGrantees)
			.mockReturnValueOnce(new Promise((resolve) => { answerFirst = resolve }))
			.mockResolvedValueOnce([{ grantee: 'anna', grantee_type: 'user', display_name: 'Anna' }])
		const wrapper = await section()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'an')
		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'anna')
		await flushPromises()
		answerFirst([{ grantee: 'andy', grantee_type: 'user', display_name: 'Andy' }])
		await flushPromises()

		expect(wrapper.findComponent(NcSelectUsers).props('options').map((/** @type {any} */ one) => one.id)).toEqual(['user:anna'])
	})

	it('gives the one picked access with the role chosen, and starts over', async () => {
		vi.mocked(searchGrantees).mockResolvedValue([{ grantee: 'crew', grantee_type: 'group', display_name: 'R&D' }])
		vi.mocked(listGrants).mockResolvedValue([ANNA])
		vi.mocked(addGrant).mockResolvedValue([ANNA, { ...CREW, role: 'manager' }])
		const wrapper = await section()
		expect(button(wrapper, 'Give access').props('disabled')).toBe(true)

		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'cr')
		await flushPromises()
		await wrapper.findComponent(NcSelectUsers).vm.$emit('update:modelValue', wrapper.findComponent(NcSelectUsers).props('options')[0])
		await select(wrapper, 'Role').vm.$emit('update:modelValue', { id: 'manager', label: 'Manager' })
		await button(wrapper, 'Give access').vm.$emit('click')
		await flushPromises()

		expect(addGrant).toHaveBeenCalledWith('v-1', expect.objectContaining({ grantee: 'crew', grantee_type: 'group' }), 'manager')
		expect(rows(wrapper)).toEqual(['Anna O\'Brien', 'R&D'])
		expect(wrapper.findComponent(NcSelectUsers).props('modelValue')).toBeNull()
		expect(wrapper.emitted('granted')).toHaveLength(1)
	})

	it('offers a driver first, the role a car is lent for', async () => {
		const wrapper = await section()

		expect(select(wrapper, 'Role').props('modelValue').id).toBe('driver')
		expect(select(wrapper, 'Role').props('options').map((/** @type {any} */ one) => one.label)).toEqual(['Viewer', 'Driver', 'Manager'])
	})

	it('changes a role in place', async () => {
		vi.mocked(changeGrant).mockResolvedValue([{ ...ANNA, role: 'manager' }, CREW])
		const wrapper = await section()

		await select(wrapper, 'Role of Anna O\'Brien').vm.$emit('update:modelValue', { id: 'manager', label: 'Manager' })
		await flushPromises()

		expect(changeGrant).toHaveBeenCalledWith('v-1', 'g-1', 'manager')
		expect(select(wrapper, 'Role of Anna O\'Brien').props('modelValue').id).toBe('manager')
	})

	/** Revoking may take people off the recipients, which the sheet shows beside this section. */
	it('asks once, then removes a grant and tells the sheet', async () => {
		vi.mocked(revokeGrant).mockResolvedValue([CREW])
		const wrapper = await section()

		await button(wrapper, 'Remove Anna O\'Brien').vm.$emit('click')
		await flushPromises()
		expect(revokeGrant).not.toHaveBeenCalled()
		expect(wrapper.find('.grants__question').text()).toBe('Anna O\'Brien loses access to this vehicle.')

		await button(wrapper, 'Remove access').vm.$emit('click')
		await flushPromises()

		expect(revokeGrant).toHaveBeenCalledWith('v-1', 'g-1')
		expect(rows(wrapper)).toEqual(['R&D'])
		expect(wrapper.emitted('revoked')).toHaveLength(1)
		expect(wrapper.find('.grants__question').exists()).toBe(false)
	})

	it('keeps the grant when the question is cancelled', async () => {
		const wrapper = await section()

		await button(wrapper, 'Remove Anna O\'Brien').vm.$emit('click')
		await button(wrapper, 'Cancel').vm.$emit('click')

		expect(wrapper.find('.grants__question').exists()).toBe(false)
		expect(button(wrapper, 'Remove Anna O\'Brien')).toBeDefined()
		expect(revokeGrant).not.toHaveBeenCalled()
	})

	it('says so when a change is refused and keeps the list as it was', async () => {
		vi.mocked(changeGrant).mockRejectedValue(new Error('role is one of viewer, driver, manager'))
		const wrapper = await section()

		await select(wrapper, 'Role of Anna O\'Brien').vm.$emit('update:modelValue', { id: 'owner', label: 'Owner' })
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('Access was not changed: role is one of viewer, driver, manager')
		expect(select(wrapper, 'Role of Anna O\'Brien').props('modelValue').id).toBe('driver')
	})

	/** Core's search can offer somebody the grant then refuses: gone since, or sharing narrowed. */
	it.each(/** @type {['user'|'group', string][]} */ ([
		['user', 'You cannot give this account access.'],
		['group', 'You cannot give this group access.'],
	]))('puts a refused %s into words', async (type, words) => {
		vi.mocked(searchGrantees).mockResolvedValue([{ grantee: 'ben', grantee_type: type, display_name: 'Ben' }])
		vi.mocked(addGrant).mockRejectedValue(new Error(`grantee is no ${type} you may grant to`))
		const wrapper = await section()

		await wrapper.findComponent(NcSelectUsers).vm.$emit('search', 'be')
		await flushPromises()
		await wrapper.findComponent(NcSelectUsers).vm.$emit('update:modelValue', wrapper.findComponent(NcSelectUsers).props('options')[0])
		await button(wrapper, 'Give access').vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe(words)
		expect(rows(wrapper)).toEqual(['Anna O\'Brien', 'R&D'])
	})
})
