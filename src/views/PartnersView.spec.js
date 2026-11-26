/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcListItem from '@nextcloud/vue/components/NcListItem'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import PartnerDetail from '../components/PartnerDetail.vue'
import { listPartners } from '../services/api.js'
import PartnersView from './PartnersView.vue'

vi.mock('../services/api.js', async (original) => ({
	...await original(),
	listPartners: vi.fn(),
}))

const MUELLER = { uuid: 'p-1', user_id: 'owner', name: 'Müller GmbH', address: 'Hauptstraße 1, Stuttgart', note: null, created_at: 1, updated_at: 10 }
const VARIANT = { uuid: 'p-2', user_id: 'owner', name: 'Mueller GmbH', address: null, note: null, created_at: 2, updated_at: 20 }
const FLEET = [{ uuid: 'v-1', updated_at: 1, plate: 'B-XY 123', may: ['view', 'own'] }]

/**
 * The screen, mounted and past its first read.
 *
 * @return {Promise<import('@vue/test-utils').VueWrapper>} the wrapper
 */
async function screen() {
	const wrapper = shallowMount(PartnersView, { props: { vehicles: FLEET } })
	await flushPromises()

	return wrapper
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {unknown[]} the names the list shows
 */
function names(wrapper) {
	return wrapper.findAllComponents(NcListItem).map((one) => one.props('name'))
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @param {string} name - the partner to open
 */
async function open(wrapper, name) {
	const item = wrapper.findAllComponents(NcListItem).find((one) => one.props('name') === name)
	await item?.vm.$emit('click', new Event('click'))
}

/**
 * @param {import('@vue/test-utils').VueWrapper} wrapper - the mounted screen
 * @return {any} the open partner
 */
function detail(wrapper) {
	return wrapper.findComponent(PartnerDetail)
}

beforeEach(() => {
	vi.resetAllMocks()
	vi.mocked(listPartners).mockResolvedValue([VARIANT, MUELLER])
})

describe('the partners screen', () => {
	it('lists the partners as the server ordered them', async () => {
		const wrapper = await screen()

		expect(names(wrapper)).toEqual(['Mueller GmbH', 'Müller GmbH'])
	})

	/** Until the list is read, an empty list is no empty list. */
	it('shows that the list is loading, not that it is empty', async () => {
		vi.mocked(listPartners).mockReturnValue(new Promise(() => {}))

		const wrapper = shallowMount(PartnersView, { props: { vehicles: FLEET } })
		await flushPromises()

		expect(wrapper.findComponent(NcLoadingIcon).exists()).toBe(true)
		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(false)
	})

	/** Nobody types a partner here: a trip that names one adds it. */
	it('says how a partner gets onto an empty list', async () => {
		vi.mocked(listPartners).mockResolvedValue([])

		const wrapper = await screen()

		const empty = wrapper.findComponent(NcEmptyContent)
		expect(empty.props('name')).toBe('No partners yet')
		expect(empty.props('description')).toBe('A business trip adds its partner to this list.')
	})

	it('says why the list did not come, and reads it again on request', async () => {
		vi.mocked(listPartners).mockRejectedValueOnce(new Error('The server answered 500'))

		const wrapper = await screen()

		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')
		expect(wrapper.findComponent(NcEmptyContent).exists()).toBe(false)

		await wrapper.findComponent(NcButton).vm.$emit('click')
		await flushPromises()

		expect(names(wrapper)).toEqual(['Mueller GmbH', 'Müller GmbH'])
	})

	it('opens one partner with the list to merge into and the fleet, and goes back to the list', async () => {
		const wrapper = await screen()

		await open(wrapper, 'Müller GmbH')

		expect(names(wrapper)).toEqual([])
		expect(detail(wrapper).props('partner')).toEqual(MUELLER)
		expect(detail(wrapper).props('partners')).toEqual([VARIANT, MUELLER])
		expect(detail(wrapper).props('vehicles')).toEqual(FLEET)

		await detail(wrapper).vm.$emit('back')

		expect(detail(wrapper).exists()).toBe(false)
		expect(names(wrapper)).toEqual(['Mueller GmbH', 'Müller GmbH'])
	})

	it('keeps a saved partner open under its new name', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Müller GmbH')

		await detail(wrapper).vm.$emit('saved', { ...MUELLER, name: 'Müller Maschinenbau GmbH', updated_at: 11 })

		expect(detail(wrapper).props('partner').name).toBe('Müller Maschinenbau GmbH')
		await detail(wrapper).vm.$emit('back')
		expect(names(wrapper)).toEqual(['Mueller GmbH', 'Müller Maschinenbau GmbH'])
	})

	/** The partner merged away is gone; the one it went into is what the owner looks at next. */
	it('opens the partner a merge went into, without the one that went', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Mueller GmbH')

		await detail(wrapper).vm.$emit('merged', { gone: 'p-2', into: { ...MUELLER, updated_at: 30 } })

		expect(detail(wrapper).props('partner')).toEqual({ ...MUELLER, updated_at: 30 })
		expect(detail(wrapper).props('partners')).toEqual([{ ...MUELLER, updated_at: 30 }])
	})

	it('goes back to the list without a deleted partner', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Mueller GmbH')

		await detail(wrapper).vm.$emit('deleted', 'p-2')

		expect(detail(wrapper).exists()).toBe(false)
		expect(names(wrapper)).toEqual(['Müller GmbH'])
	})

	/** After a refusal for a stale token, the open partner comes again as the server holds it. */
	it('reads the list again on request and keeps the partner open', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Müller GmbH')
		vi.mocked(listPartners).mockResolvedValue([VARIANT, { ...MUELLER, note: 'Gate 3', updated_at: 12 }])

		await detail(wrapper).vm.$emit('reload')
		await flushPromises()

		expect(detail(wrapper).props('partner')).toEqual({ ...MUELLER, note: 'Gate 3', updated_at: 12 })
	})

	it('says why a new read for the open partner did not come, and reads it again on request', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Müller GmbH')
		vi.mocked(listPartners).mockRejectedValueOnce(new Error('The server answered 500'))

		await detail(wrapper).vm.$emit('reload')
		await flushPromises()

		expect(detail(wrapper).exists()).toBe(true)
		expect(wrapper.findComponent(NcNoteCard).props('text')).toBe('The server answered 500')

		vi.mocked(listPartners).mockResolvedValue([VARIANT, { ...MUELLER, updated_at: 12 }])
		await wrapper.findComponent(NcButton).vm.$emit('click')
		await flushPromises()

		expect(wrapper.findComponent(NcNoteCard).exists()).toBe(false)
		expect(detail(wrapper).props('partner')).toEqual({ ...MUELLER, updated_at: 12 })
	})

	it('goes back to the list when the open partner is gone on a new read', async () => {
		const wrapper = await screen()
		await open(wrapper, 'Müller GmbH')
		vi.mocked(listPartners).mockResolvedValue([VARIANT])

		await detail(wrapper).vm.$emit('reload')
		await flushPromises()

		expect(detail(wrapper).exists()).toBe(false)
		expect(names(wrapper)).toEqual(['Mueller GmbH'])
	})
})
