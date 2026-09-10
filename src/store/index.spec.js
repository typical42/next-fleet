/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createVehicle, deleteVehicle, getVehicle, listVehicles, recordReading, recordTrip, restoreVehicle, updateVehicle } from '../services/api.js'
import { useVehiclesStore } from './index.js'

// The network is the api client's own seam (api.spec.js); what is under test here is what the
// store does with the two answers it can get.
vi.mock('../services/api.js', () => ({
	createVehicle: vi.fn(),
	deleteVehicle: vi.fn(),
	getVehicle: vi.fn(),
	listVehicles: vi.fn(),
	recordReading: vi.fn(),
	recordTrip: vi.fn(),
	restoreVehicle: vi.fn(),
	updateVehicle: vi.fn(),
}))

/**
 * A vehicle as the server hands it over, with only the fields a test cares about spelled out.
 *
 * @param {Partial<import('../services/api.js').Vehicle>} fields - what this one differs in
 * @return {import('../services/api.js').Vehicle} the vehicle
 */
function vehicle(fields) {
	return { uuid: 'a', updated_at: 1750000000, lifecycle: 'active', odo_unit: 'km', ...fields }
}

describe('vehicles store', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		vi.resetAllMocks()
	})

	it('identifies a vehicle by uuid, not by plate', () => {
		const store = useVehiclesStore()

		store.upsert(vehicle({ uuid: 'a', plate: 'M-AB 123' }))
		store.upsert(vehicle({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 }))

		expect(store.list.map((v) => v.plate)).toEqual(['M-XY 789'])
	})

	describe('visible', () => {
		/**
		 * The overview is a to-do list, not an inventory: a sold vehicle leaves it and a laid-up
		 * one sinks below the vehicles somebody still drives (docs/ui.md).
		 */
		it('drops disposed vehicles and sinks laid-up ones', () => {
			const store = useVehiclesStore()

			store.upsert(vehicle({ uuid: 'laid', lifecycle: 'laid_up' }))
			store.upsert(vehicle({ uuid: 'gone', lifecycle: 'disposed' }))
			store.upsert(vehicle({ uuid: 'driven', lifecycle: 'active' }))

			expect(store.visible.map((v) => v.uuid)).toEqual(['driven', 'laid'])
		})
	})

	describe('load', () => {
		/**
		 * The fleet the server sends is the whole answer, so a vehicle somebody deleted in another
		 * tab has to disappear here rather than linger as a row nothing can write to.
		 */
		it('holds the fleet the server sent and nothing else', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'stale' }))
			vi.mocked(listVehicles).mockResolvedValue([vehicle({ uuid: 'fresh' })])

			await store.load()

			expect(store.list.map((v) => v.uuid)).toEqual(['fresh'])
		})
	})

	describe('create', () => {
		/** What the sheet leaves out the server decides, so the answer is what the store keeps. */
		it('keeps what the server made of the four fields', async () => {
			const store = useVehiclesStore()
			vi.mocked(createVehicle).mockResolvedValue(vehicle({ uuid: 'new', odo_unit: 'h' }))

			const created = await store.create({ plate: 'M-EV 7' })

			expect(created.uuid).toBe('new')
			expect(store.list.map((v) => v.odo_unit)).toEqual(['h'])
		})
	})

	describe('record', () => {
		/**
		 * `odo_value` is a cache the server recomputes from the whole chain of readings
		 * (docs/architecture.md#odometer-rules), and a Reading answers with itself. So the vehicle
		 * has to be read back; computing the new counter here would be a second implementation of
		 * the rules, and a wrong one the moment a reading lands out of order.
		 */
		it('re-reads the vehicle instead of counting the new value itself', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148000 }))
			vi.mocked(recordReading).mockResolvedValue({ uuid: 'r1', value: 148320, origin: 'observed', flagged: false, read_at: 1750000000, read_at_off: 120 })
			vi.mocked(getVehicle).mockResolvedValue(vehicle({ uuid: 'a', odo_value: 148320 }))

			const reading = await store.record('a', { value: 148320, read_at_off: 120 })

			expect(reading.value).toBe(148320)
			expect(getVehicle).toHaveBeenCalledWith('a')
			expect(store.list[0].odo_value).toBe(148320)
		})

		/**
		 * The Reading is written by then. Reporting the failed re-read as a failed save would
		 * offer a retry that writes the Reading a second time - and nothing on the server refuses
		 * a duplicate, because two equal readings are not a contradiction
		 * (docs/architecture.md#odometer-rules). A stale counter until the next load is the
		 * cheaper wrong.
		 */
		it('does not report a written reading as failed because the vehicle would not come back', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148000 }))
			vi.mocked(recordReading).mockResolvedValue({ uuid: 'r1', value: 148320, origin: 'observed', flagged: false, read_at: 1750000000, read_at_off: 120 })
			vi.mocked(getVehicle).mockRejectedValue(new Error('The server answered 503'))

			const reading = await store.record('a', { value: 148320, read_at_off: 120 })

			expect(reading.uuid).toBe('r1')
			expect(store.list[0].odo_value).toBe(148000)
		})

		/**
		 * A reading the server refused never reached the vehicle either, so re-reading it would
		 * only hide the failure the sheet has to show (docs/ui.md).
		 */
		it('lets a refused reading through and leaves the vehicle alone', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148000 }))
			const refusal = new Error('value is a whole number, never negative')
			vi.mocked(recordReading).mockRejectedValue(refusal)

			await expect(store.record('a', { value: -1, read_at_off: 120 })).rejects.toBe(refusal)

			expect(getVehicle).not.toHaveBeenCalled()
			expect(store.list[0].odo_value).toBe(148000)
		})
	})

	describe('log', () => {
		/**
		 * A trip writes a Reading of its own (docs/architecture.md#odometer-rules), so the counter
		 * moves for the same reason `record` does — and it is read back for the same reason: the
		 * new value is the server's to compute, whether the trip stated it or was counted a
		 * distance onto the chain.
		 */
		it('re-reads the vehicle the trip moved', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148320 }))
			vi.mocked(recordTrip).mockResolvedValue(/** @type {any} */ ({ uuid: 't1', end_odo: 148402 }))
			vi.mocked(getVehicle).mockResolvedValue(vehicle({ uuid: 'a', odo_value: 148402 }))

			const trip = await store.log('a', { ended_at: 1750000000, end_odo: 148402 })

			expect(trip.uuid).toBe('t1')
			expect(getVehicle).toHaveBeenCalledWith('a')
			expect(store.list[0].odo_value).toBe(148402)
		})

		/**
		 * The trip is written by then, and it wrote a Reading with it. Offering the sheet a retry
		 * would log the journey twice, and nothing refuses a duplicate.
		 */
		it('does not report a written trip as failed because the vehicle would not come back', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148320 }))
			vi.mocked(recordTrip).mockResolvedValue(/** @type {any} */ ({ uuid: 't1', end_odo: 148402 }))
			vi.mocked(getVehicle).mockRejectedValue(new Error('The server answered 503'))

			const trip = await store.log('a', { ended_at: 1750000000, end_odo: 148402 })

			expect(trip.uuid).toBe('t1')
			expect(store.list[0].odo_value).toBe(148320)
		})

		/** A trip the server refused never reached the vehicle, so the sheet has to hear the refusal. */
		it('lets a refused trip through and leaves the vehicle alone', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148320 }))
			const refusal = new Error('a trip carries end_odo or distance')
			vi.mocked(recordTrip).mockRejectedValue(refusal)

			await expect(store.log('a', { ended_at: 1750000000 })).rejects.toBe(refusal)

			expect(getVehicle).not.toHaveBeenCalled()
			expect(store.list[0].odo_value).toBe(148320)
		})
	})

	describe('save', () => {
		it('replaces its copy with what the server saved', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ plate: 'M-AB 123' }))
			vi.mocked(updateVehicle).mockResolvedValue(vehicle({ plate: 'M-XY 789', updated_at: 1750000001 }))

			await store.save(vehicle({ plate: 'M-XY 789' }))

			expect(store.list.map((v) => v.plate)).toEqual(['M-XY 789'])
		})

		/**
		 * A refused write reaches whoever asked for the save, so the sheet can stay open and offer a
		 * retry (docs/ui.md). Swallowing it would close the sheet over a write that never happened.
		 */
		it('lets a refused write through and keeps the row it holds', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ plate: 'M-AB 123' }))
			const refusal = new Error('Changed since you read it')
			vi.mocked(updateVehicle).mockRejectedValue(refusal)

			await expect(store.save(vehicle({ plate: 'M-XY 789' }))).rejects.toBe(refusal)

			expect(store.list.map((v) => v.plate)).toEqual(['M-AB 123'])
		})
	})

	describe('remove', () => {
		/**
		 * A deleted vehicle is gone from the fleet, not a row carrying a flag: `visible` hides only
		 * the disposed ones, so a vehicle left behind here would still be listed and still be
		 * openable. The store keeps the vehicle the delete answered with, because the token on it
		 * is the only one the undo is accepted with (docs/architecture.md#concurrency) and the
		 * screen that asked for the delete goes with the vehicle.
		 */
		it('drops the vehicle and keeps the token the undo is checked against', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a' }))
			store.upsert(vehicle({ uuid: 'b' }))
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))

			await store.remove(vehicle({ uuid: 'a' }))

			expect(store.list.map((v) => v.uuid)).toEqual(['b'])
			expect(store.deleted?.updated_at).toBe(1750000002)
		})

		/**
		 * A refused delete deleted nothing, so dropping the row would take away a vehicle that is
		 * still there - and the failure has to reach whoever asked, as it does for a save.
		 */
		it('lets a refused delete through and keeps the vehicle', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a' }))
			const refusal = new Error('Changed since you read it')
			vi.mocked(deleteVehicle).mockRejectedValue(refusal)

			await expect(store.remove(vehicle({ uuid: 'a' }))).rejects.toBe(refusal)

			expect(store.list.map((v) => v.uuid)).toEqual(['a'])
			expect(store.deleted).toBeNull()
		})
	})

	describe('restore', () => {
		/**
		 * Undo takes the vehicle the delete answered with, and no other: the token it carries is
		 * the one the server checks, and it is minted by the delete
		 * (docs/architecture.md#concurrency).
		 */
		it('puts back the vehicle the last delete took, under the token it answered with', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', plate: 'M-AB 123' }))
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))
			vi.mocked(restoreVehicle).mockResolvedValue(vehicle({ uuid: 'a', plate: 'M-AB 123' }))
			await store.remove(vehicle({ uuid: 'a' }))

			await store.restore()

			expect(restoreVehicle).toHaveBeenCalledWith(expect.objectContaining({ updated_at: 1750000002 }))
			expect(store.list.map((v) => v.plate)).toEqual(['M-AB 123'])
			expect(store.deleted).toBeNull()
		})

		/**
		 * The token the store holds matched nothing, so the vehicle is still deleted. Inventing a
		 * row for it here would put a vehicle on the overview that no write can reach, and letting
		 * go of the token would take away the second attempt.
		 */
		it('lets a refused undo through and puts nothing back', async () => {
			const store = useVehiclesStore()
			const refusal = new Error('Changed since you read it')
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))
			vi.mocked(restoreVehicle).mockRejectedValue(refusal)
			store.upsert(vehicle({ uuid: 'a' }))
			await store.remove(vehicle({ uuid: 'a' }))

			await expect(store.restore()).rejects.toBe(refusal)

			expect(store.list).toEqual([])
			expect(store.deleted?.uuid).toBe('a')
		})

		/** Nothing was deleted, so there is nothing to ask the server for. */
		it('asks for nothing when there is nothing to undo', async () => {
			const store = useVehiclesStore()

			await store.restore()

			expect(restoreVehicle).not.toHaveBeenCalled()
		})
	})

	describe('forget', () => {
		/** The way back is offered once. Letting go of it is what closing the toast means. */
		it('lets go of the deleted vehicle', async () => {
			const store = useVehiclesStore()
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))
			store.upsert(vehicle({ uuid: 'a' }))
			await store.remove(vehicle({ uuid: 'a' }))

			store.forget()

			expect(store.deleted).toBeNull()
		})
	})
})
