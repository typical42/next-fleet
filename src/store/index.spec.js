/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it } from 'vitest'

import { useVehiclesStore } from './index.js'

describe('vehicles store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('identifies a vehicle by uuid, not by plate', () => {
		const store = useVehiclesStore()

		store.upsert({ uuid: 'a', plate: 'M-AB 123' })
		store.upsert({ uuid: 'a', plate: 'M-XY 789' })

		expect(store.list).toEqual([{ uuid: 'a', plate: 'M-XY 789' }])
	})
})
