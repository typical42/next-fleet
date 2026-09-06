/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { updateVehicle } from '../services/api.js'
import { useVehiclesStore } from './index.js'

// The network is the api client's own seam (api.spec.js); what is under test here is what the
// store does with the two answers it can get.
vi.mock('../services/api.js', () => ({ updateVehicle: vi.fn() }))

describe('vehicles store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.mocked(updateVehicle).mockReset()
	})

	it('identifies a vehicle by uuid, not by plate', () => {
		const store = useVehiclesStore()

		store.upsert({ uuid: 'a', plate: 'M-AB 123', updated_at: 1750000000 })
		store.upsert({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 })

		expect(store.list).toEqual([{ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 }])
	})

	it('replaces its copy with what the server saved', async () => {
		const store = useVehiclesStore()
		store.upsert({ uuid: 'a', plate: 'M-AB 123', updated_at: 1750000000 })
		vi.mocked(updateVehicle).mockResolvedValue({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 })

		await store.save({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000000 })

		expect(store.list).toEqual([{ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 }])
	})

	/**
	 * A refused write reaches whoever asked for the save, so the sheet can stay open and offer a
	 * retry (docs/ui.md). Swallowing it would close the sheet over a write that never happened.
	 */
	it('lets a refused write through and keeps the row it holds', async () => {
		const store = useVehiclesStore()
		store.upsert({ uuid: 'a', plate: 'M-AB 123', updated_at: 1750000000 })
		const refusal = new Error('Changed since you read it')
		vi.mocked(updateVehicle).mockRejectedValue(refusal)

		await expect(store.save({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000000 }))
			.rejects.toBe(refusal)

		expect(store.list).toEqual([{ uuid: 'a', plate: 'M-AB 123', updated_at: 1750000000 }])
	})
})
