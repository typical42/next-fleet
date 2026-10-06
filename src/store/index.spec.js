/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { beforeEach, describe, expect, it, vi } from 'vitest'

import { createVehicle, deleteEntry, deleteVehicle, detachDocument, getVehicle, leaveVehicle, listVehicles, readInbox, recordMaintenance, recordReading, recordTrip, restoreDocument, restoreEntry, restoreVehicle, runImport, undoImport, updateEntry, updateVehicle } from '../services/api.js'
import { useInboxStore } from './inbox.js'
import { useVehiclesStore } from './index.js'

// The network is the api client's own seam (api.spec.js); what is under test here is what the
// store does with the two answers it can get.
vi.mock('../services/api.js', () => ({
	createVehicle: vi.fn(),
	deleteEntry: vi.fn(),
	deleteVehicle: vi.fn(),
	detachDocument: vi.fn(),
	getVehicle: vi.fn(),
	leaveVehicle: vi.fn(),
	listVehicles: vi.fn(),
	readInbox: vi.fn(),
	recordMaintenance: vi.fn(),
	recordReading: vi.fn(),
	recordTrip: vi.fn(),
	restoreDocument: vi.fn(),
	restoreEntry: vi.fn(),
	restoreVehicle: vi.fn(),
	runImport: vi.fn(),
	undoImport: vi.fn(),
	updateEntry: vi.fn(),
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

	/** A paper taken off puts its file back in the inbox, and its undo takes it out again. */
	it('reads the inbox count again after a paper is detached and after it is restored', async () => {
		const PAPER = /** @type {any} */ ({ uuid: 'd-1', file_id: 42 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 1 })
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 })
		vi.mocked(detachDocument).mockResolvedValue([])
		vi.mocked(restoreDocument).mockResolvedValue([PAPER])
		const inbox = useInboxStore()
		await inbox.load()
		const store = useVehiclesStore()

		await store.detach('a', PAPER)
		await flushPromises()
		expect(inbox.count).toBe(1)

		await store.restore()
		await flushPromises()
		expect(inbox.count).toBe(0)
	})

	/** The list is the answer; the count beside the menu is no reason to keep a removed paper on screen. */
	it('takes a paper off without waiting for the inbox', async () => {
		vi.mocked(readInbox).mockResolvedValueOnce({ folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 })
		vi.mocked(readInbox).mockReturnValueOnce(new Promise(() => {}))
		vi.mocked(detachDocument).mockResolvedValue([])
		await useInboxStore().load()

		await expect(useVehiclesStore().detach('a', /** @type {any} */ ({ uuid: 'd-1' }))).resolves.toEqual([])
	})

	it('identifies a vehicle by uuid, not by plate', () => {
		const store = useVehiclesStore()

		store.upsert(vehicle({ uuid: 'a', plate: 'M-AB 123' }))
		store.upsert(vehicle({ uuid: 'a', plate: 'M-XY 789', updated_at: 1750000001 }))

		expect(store.list.map((v) => v.plate)).toEqual(['M-XY 789'])
	})

	describe('visible', () => {
		it('drops disposed vehicles and sinks laid-up ones', () => {
			const store = useVehiclesStore()

			store.upsert(vehicle({ uuid: 'laid', lifecycle: 'laid_up' }))
			store.upsert(vehicle({ uuid: 'gone', lifecycle: 'disposed' }))
			store.upsert(vehicle({ uuid: 'driven', lifecycle: 'active' }))

			expect(store.visible.map((v) => v.uuid)).toEqual(['driven', 'laid'])
		})
	})

	describe('load', () => {
		/** A vehicle deleted in another tab disappears here. */
		it('holds the fleet the server sent and nothing else', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'stale' }))
			vi.mocked(listVehicles).mockResolvedValue([vehicle({ uuid: 'fresh' })])

			await store.load()

			expect(store.list.map((v) => v.uuid)).toEqual(['fresh'])
		})
	})

	describe('create', () => {
		it('keeps what the server made of the four fields', async () => {
			const store = useVehiclesStore()
			vi.mocked(createVehicle).mockResolvedValue(vehicle({ uuid: 'new', odo_unit: 'h' }))

			const created = await store.create({ plate: 'M-EV 7' })

			expect(created.uuid).toBe('new')
			expect(store.list.map((v) => v.odo_unit)).toEqual(['h'])
		})
	})

	describe('record', () => {
		it('re-reads the vehicle instead of counting the new value itself', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148000 }))
			vi.mocked(recordReading).mockResolvedValue({ uuid: 'r1', value: 148320, origin: 'observed', flagged: false, kind: 'reading', read_at: 1750000000, read_at_off: 120, counter: 'main', updated_at: 1750000000 })
			vi.mocked(getVehicle).mockResolvedValue(vehicle({ uuid: 'a', odo_value: 148320 }))

			const reading = await store.record('a', { value: 148320, read_at_off: 120 })

			expect(reading.value).toBe(148320)
			expect(getVehicle).toHaveBeenCalledWith('a')
			expect(store.list[0].odo_value).toBe(148320)
		})

		it('does not report a written reading as failed because the vehicle would not come back', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148000 }))
			vi.mocked(recordReading).mockResolvedValue({ uuid: 'r1', value: 148320, origin: 'observed', flagged: false, kind: 'reading', read_at: 1750000000, read_at_off: 120, counter: 'main', updated_at: 1750000000 })
			vi.mocked(getVehicle).mockRejectedValue(new Error('The server answered 503'))

			const reading = await store.record('a', { value: 148320, read_at_off: 120 })

			expect(reading.uuid).toBe('r1')
			expect(store.list[0].odo_value).toBe(148000)
		})

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

		it('does not report a written trip as failed because the vehicle would not come back', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', odo_value: 148320 }))
			vi.mocked(recordTrip).mockResolvedValue(/** @type {any} */ ({ uuid: 't1', end_odo: 148402 }))
			vi.mocked(getVehicle).mockRejectedValue(new Error('The server answered 503'))

			const trip = await store.log('a', { ended_at: 1750000000, end_odo: 148402 })

			expect(trip.uuid).toBe('t1')
			expect(store.list[0].odo_value).toBe(148320)
		})

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
		it('drops the vehicle and keeps the token the undo is checked against', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a' }))
			store.upsert(vehicle({ uuid: 'b' }))
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))

			await store.remove(vehicle({ uuid: 'a' }))

			expect(store.list.map((v) => v.uuid)).toEqual(['b'])
			expect(store.deleted?.updated_at).toBe(1750000002)
		})

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

	describe('leave', () => {
		it('drops the vehicle when no group still reaches it', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a' }))
			store.upsert(vehicle({ uuid: 'b' }))
			vi.mocked(leaveVehicle).mockResolvedValue({ role: null, groups: [] })

			expect(await store.leave('a')).toEqual({ role: null, groups: [] })

			expect(leaveVehicle).toHaveBeenCalledWith('a')
			expect(store.list.map((v) => v.uuid)).toEqual(['b'])
			expect(store.deleted).toBeNull()
		})

		it('reads the vehicle again when a group still reaches it', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', may: ['view', 'log'] }))
			const held = { role: null, groups: [{ grantee: 'crew', display_name: 'The Crew', role: 'viewer' }] }
			vi.mocked(leaveVehicle).mockResolvedValue(held)
			vi.mocked(getVehicle).mockResolvedValue(vehicle({ uuid: 'a', may: ['view'] }))

			expect(await store.leave('a')).toEqual(held)

			expect(store.byUuid.get('a')?.may).toEqual(['view'])
		})

		it('lets a refused leave through and keeps the vehicle', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a' }))
			const refusal = new Error('No such vehicle')
			vi.mocked(leaveVehicle).mockRejectedValue(refusal)

			await expect(store.leave('a')).rejects.toBe(refusal)

			expect(store.list.map((v) => v.uuid)).toEqual(['a'])
		})
	})

	describe('restore', () => {
		/** The restore answers with a new token, which the store keeps for the next edit. */
		it('puts back the vehicle the last delete took, under the token it answered with', async () => {
			const store = useVehiclesStore()
			store.upsert(vehicle({ uuid: 'a', plate: 'M-AB 123' }))
			vi.mocked(deleteVehicle).mockResolvedValue(vehicle({ uuid: 'a', updated_at: 1750000002 }))
			vi.mocked(restoreVehicle).mockResolvedValue(vehicle({ uuid: 'a', plate: 'M-AB 123', updated_at: 1750000003 }))
			await store.remove(vehicle({ uuid: 'a' }))

			await store.restore()

			expect(restoreVehicle).toHaveBeenCalledWith(expect.objectContaining({ updated_at: 1750000002 }))
			expect(store.list.map((v) => [v.plate, v.updated_at])).toEqual([['M-AB 123', 1750000003]])
			expect(store.deleted).toBeNull()
		})

		/** Still deleted, so no row comes back; the offer stays for a second attempt. */
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

		it('asks for nothing when there is nothing to undo', async () => {
			const store = useVehiclesStore()

			await store.restore()

			expect(restoreVehicle).not.toHaveBeenCalled()
		})
	})

	describe('a maintenance record', () => {
		/**
		 * Each write may close a reminder or reopen it (docs/architecture.md#reminder-engine); a
		 * fill-up closes none.
		 */
		it('tells whoever lists reminders each time it is written', async () => {
			const store = useVehiclesStore()
			vi.mocked(getVehicle).mockResolvedValue(vehicle({}))
			vi.mocked(recordMaintenance).mockResolvedValue({ uuid: 'm-1' })
			vi.mocked(updateEntry).mockResolvedValue({ uuid: 'm-1' })
			vi.mocked(deleteEntry).mockResolvedValue({ uuid: 'm-1', updated_at: 2 })
			vi.mocked(restoreEntry).mockResolvedValue({ uuid: 'm-1' })
			const entry = { uuid: 'm-1', updated_at: 1 }

			await store.maintain('a', {})
			await store.revise('a', 'maintenance', entry, {})
			await store.strike('a', 'maintenance', entry)
			await store.restore()
			expect(store.reminded).toBe(4)

			await store.revise('a', 'energy', entry, {})
			expect(store.reminded).toBe(4)
		})
	})

	describe('an import', () => {
		const ASKED = /** @type {any} */ ({ file_id: 42, importer: 'lubelogger', record_type: 'fuel', etag: 'e-1' })
		const CREATED = [{ type: 'energy', uuid: 'e-1' }, { type: 'energy', uuid: 'e-2' }]
		const COUNTS = { new: 2, duplicate: 1, unreadable: 0, creates: 2 }

		/** Its fill-ups are Readings, so the counter moved; its list is the undo's only key. */
		it('re-reads the vehicle and holds the list the undo names', async () => {
			const store = useVehiclesStore()
			vi.mocked(runImport).mockResolvedValue({ counts: COUNTS, created: CREATED })
			vi.mocked(getVehicle).mockResolvedValue(vehicle({ uuid: 'a', odo_value: 1200 }))

			const result = await store.bring('a', ASKED)

			expect(runImport).toHaveBeenCalledWith('a', ASKED)
			expect(result.counts).toEqual(COUNTS)
			expect(store.byUuid.get('a')?.odo_value).toBe(1200)
			expect(store.imported).toEqual({ vehicle: 'a', counts: COUNTS, created: CREATED })
		})

		/** One offer at a time: the import's undo is the toast's, as a delete's is. */
		it('takes the place of the last delete\'s way back, and gives it up to the next', async () => {
			const store = useVehiclesStore()
			vi.mocked(getVehicle).mockResolvedValue(vehicle({}))
			vi.mocked(deleteEntry).mockResolvedValue({ uuid: 'm-1', updated_at: 2 })
			vi.mocked(runImport).mockResolvedValue({ counts: COUNTS, created: CREATED })
			await store.strike('a', 'expense', { uuid: 'x-1', updated_at: 1 })

			await store.bring('a', ASKED)
			expect(store.struck).toBeNull()

			await store.strike('a', 'expense', { uuid: 'x-1', updated_at: 1 })
			expect(store.imported).toBeNull()
		})

		/** The timeline, the header and the banner read again on `restored`, as after any undo. */
		it('is undone as the list it created, all of it', async () => {
			const store = useVehiclesStore()
			vi.mocked(runImport).mockResolvedValue({ counts: COUNTS, created: CREATED })
			vi.mocked(undoImport).mockResolvedValue({ undone: 2 })
			vi.mocked(getVehicle).mockResolvedValue(vehicle({}))
			await store.bring('a', ASKED)

			await store.restore()

			expect(undoImport).toHaveBeenCalledWith('a', CREATED)
			expect(store.imported).toBeNull()
			expect(store.restored).toBe(1)
			expect(getVehicle).toHaveBeenCalledTimes(2)
		})

		/** The toast says it can no longer be undone as a whole; the offer is its to drop. */
		it('lets a refused undo through and keeps the offer', async () => {
			const store = useVehiclesStore()
			const refusal = new Error('Changed')
			vi.mocked(runImport).mockResolvedValue({ counts: COUNTS, created: CREATED })
			vi.mocked(undoImport).mockRejectedValue(refusal)
			vi.mocked(getVehicle).mockResolvedValue(vehicle({}))
			await store.bring('a', ASKED)

			await expect(store.restore()).rejects.toBe(refusal)

			expect(store.imported?.created).toEqual(CREATED)
			expect(store.restored).toBe(0)
		})

		it('is let go of like a delete', async () => {
			const store = useVehiclesStore()
			vi.mocked(runImport).mockResolvedValue({ counts: COUNTS, created: CREATED })
			vi.mocked(getVehicle).mockResolvedValue(vehicle({}))
			await store.bring('a', ASKED)

			store.forget()

			expect(store.imported).toBeNull()
		})
	})

	describe('forget', () => {
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
