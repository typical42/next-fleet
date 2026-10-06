/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineStore } from 'pinia'
import { computed, ref } from 'vue'

import { createReminder, createVehicle, deleteEntry, deleteVehicle, detachDocument, getVehicle, leaveVehicle, listVehicles, recordEnergy, recordExpense, recordMaintenance, recordReading, recordTrip, restoreDocument, restoreEntry, restoreVehicle, runImport, undoImport, updateEntry, updateVehicle } from '../services/api.js'
import { useInboxStore } from './inbox.js'

/** @typedef {import('../services/api.js').Vehicle} Vehicle */
/** @typedef {import('../services/api.js').Reading} Reading */

/**
 * A sold vehicle leaves the overview and a laid-up one sinks below the driven ones (docs/ui.md).
 * Array#sort is stable, so the navigation keeps the server's order within a rank; the overview
 * reorders by urgency (`fleetByUrgency`, src/utils/reminders.js).
 */
/** @type {Record<string, number>} */
const RANK = { active: 0, laid_up: 1 }

/** Vehicles held by uuid: a plate is a label the user may change. */
export const useVehiclesStore = defineStore('vehicles', () => {
	/** @type {import('vue').Ref<Map<string, Vehicle>>} */
	const byUuid = ref(new Map())

	/**
	 * The way back for a vehicle: the last delete's answer, the only holder of the token the undo
	 * takes. State rather than a return value, because the delete unmounts the screen that asked.
	 *
	 * @type {import('vue').Ref<Vehicle|null>}
	 */
	const deleted = ref(null)

	/**
	 * The way back for an Entry: the last delete's answer and its vehicle. State, as `deleted` is.
	 *
	 * @type {import('vue').Ref<{vehicle: string, type: import('../services/api.js').Written, entry: {uuid: string, updated_at: number}}|null>}
	 */
	const struck = ref(null)

	/**
	 * The way back for an import: what it created, which is all the undo names
	 * (docs/architecture.md#import). State, as `deleted` is.
	 *
	 * @type {import('vue').Ref<{vehicle: string, counts: import('../services/api.js').ImportCounts, created: {type: string, uuid: string}[]}|null>}
	 */
	const imported = ref(null)

	/**
	 * The way back for a paper: the last one taken off, and its vehicle. No token: nothing edits a
	 * document (docs/architecture.md#documents).
	 *
	 * @type {import('vue').Ref<{vehicle: string, document: string}|null>}
	 */
	const detached = ref(null)

	/**
	 * The entry sheet's last save, for the toast's "Saved.". An edit carries the way back: its new
	 * token and the fields it replaced. A new Entry carries neither; the timeline deletes it.
	 *
	 * @type {import('vue').Ref<{vehicle: string, type: import('../services/api.js').Written, entry: {uuid: string, updated_at: number}|null, before: object|null}|null>}
	 */
	const saved = ref(null)

	/**
	 * The list the last paper's undo answered, for the papers section to show: the toast that makes
	 * the undo knows no section, as with `restored`.
	 *
	 * @type {import('vue').Ref<{vehicle: string, list: import('../services/api.js').Document[]}|null>}
	 */
	const refiled = ref(null)

	/**
	 * Counts undos that changed a timeline. The toast that makes them lives in the app shell and
	 * knows no timeline; a timeline watches this and reads itself again.
	 */
	const restored = ref(0)

	/**
	 * Counts writes that moved a reminder behind the due banner's back: `remind()`, and every write
	 * to a Maintenance Record, which may close one. The banner watches this and reads itself again.
	 */
	const reminded = ref(0)

	const list = computed(() => [...byUuid.value.values()])

	const visible = computed(() => list.value
		.filter((vehicle) => vehicle.lifecycle !== 'disposed')
		.sort((a, b) => (RANK[a.lifecycle ?? 'active'] ?? 0) - (RANK[b.lifecycle ?? 'active'] ?? 0)))

	/**
	 * @param {Vehicle} vehicle - the vehicle to add or replace
	 */
	function upsert(vehicle) {
		byUuid.value.set(vehicle.uuid, vehicle)
	}

	/**
	 * Read the fleet. The answer is the whole truth, so what it leaves out is dropped rather than
	 * kept as a row nothing can write to.
	 *
	 * @return {Promise<void>} when the store holds the fleet
	 */
	async function load() {
		const fleet = await listVehicles()

		byUuid.value = new Map(fleet.map((vehicle) => [vehicle.uuid, vehicle]))
	}

	/**
	 * Add a vehicle and hold what the server made of it, identity and defaults included.
	 *
	 * @param {Partial<Vehicle> & import('../services/api.js').Retried} fields - the ones the create sheet asks for (docs/ui.md)
	 * @return {Promise<Vehicle>} the vehicle as the server made it
	 */
	async function create(fields) {
		const vehicle = await createVehicle(fields)
		upsert(vehicle)

		return vehicle
	}

	/**
	 * Write a vehicle back and hold what the server saved, token included. A refusal is not caught:
	 * the sheet must stay open and offer the retry (docs/ui.md).
	 *
	 * @param {Vehicle} vehicle - the vehicle as edited, carrying the `updated_at` it was read with
	 * @return {Promise<Vehicle>} the vehicle as the server now holds it
	 */
	async function save(vehicle) {
		const saved = await updateVehicle(vehicle)
		upsert(saved)

		return saved
	}

	/**
	 * Delete a vehicle and drop it from the fleet: `visible` hides only disposed ones, so a kept
	 * row would still be listed. The answer is held as `deleted`, one offer at a time with the
	 * others (docs/architecture.md#concurrency). A refusal is not caught, as with `save()`.
	 *
	 * @param {Vehicle} vehicle - the vehicle as it was read, carrying the `updated_at` it was read with
	 * @return {Promise<void>} when it is out of the fleet and the way back is held
	 */
	async function remove(vehicle) {
		deleted.value = await deleteVehicle(vehicle)
		struck.value = null
		imported.value = null
		detached.value = null
		saved.value = null
		byUuid.value.delete(vehicle.uuid)
	}

	/**
	 * Give back the session's own grant. With no group still reaching the vehicle it leaves the
	 * fleet, with no way back: only the owner grants again. Otherwise it is read again for what the
	 * group allows; a failed read leaves it stale, as in refresh(), and the server refuses the
	 * rest. A refused leave is not caught, as with `save()`.
	 *
	 * @param {string} uuid - the vehicle
	 * @return {Promise<import('../services/api.js').Held>} what the session still holds on it
	 */
	async function leave(uuid) {
		const held = await leaveVehicle(uuid)
		if (held.groups.length === 0) {
			byUuid.value.delete(uuid)
			return held
		}
		try {
			upsert(await getVehicle(uuid))
		} catch {
			// See above: the leave stands.
		}

		return held
	}

	/**
	 * Delete one Entry or Reminder - a trip under Logbook Mode is voided - and hold the way back, as
	 * remove() does for a vehicle and one offer at a time with it. A refusal is not caught, as with
	 * `save()`.
	 *
	 * @param {string} uuid - the vehicle it hangs off
	 * @param {import('../services/api.js').Written} type - which kind it is
	 * @param {{uuid: string, updated_at: number}} entry - the Entry as it was read
	 * @return {Promise<void>} when it is gone and the way back is held
	 */
	async function strike(uuid, type, entry) {
		const left = await moving(uuid, type, () => deleteEntry(uuid, type, entry))
		deleted.value = null
		imported.value = null
		detached.value = null
		saved.value = null
		struck.value = { vehicle: uuid, type, entry: left }
	}

	/**
	 * Take a paper off a vehicle and hold the way back, as strike() does for an Entry. A refusal is
	 * not caught, as with `save()`.
	 *
	 * @param {string} uuid - the vehicle
	 * @param {import('../services/api.js').Document} paper - the one to take off
	 * @return {Promise<import('../services/api.js').Document[]>} the list as it now stands
	 */
	async function detach(uuid, paper) {
		const list = await detachDocument(uuid, paper.uuid)
		deleted.value = null
		struck.value = null
		imported.value = null
		saved.value = null
		detached.value = { vehicle: uuid, document: paper.uuid }
		// The file may wait in the inbox folder again. Not awaited: the list is the answer.
		useInboxStore().refresh()

		return list
	}

	/**
	 * Import a file: every row the preview with the same answers counted, in one write, and the
	 * way back held as a delete's is. Its fill-ups and records are Readings, so the vehicle is
	 * read back. A refusal is not caught, as with `save()`.
	 *
	 * @param {string} uuid - the vehicle
	 * @param {import('../services/api.js').ImportAsked & {etag: string}} asked - the preview's request and its etag
	 * @return {Promise<import('../services/api.js').ImportResult>} what the import answered
	 */
	async function bring(uuid, asked) {
		const result = await counted(uuid, () => runImport(uuid, asked))
		deleted.value = null
		struck.value = null
		detached.value = null
		saved.value = null
		imported.value = { vehicle: uuid, counts: result.counts, created: result.created }

		return result
	}

	/**
	 * Take back the standing offer: a delete on the token it answered with, an import as the list
	 * it created, an edit by writing back what it replaced (docs/architecture.md#concurrency). A
	 * refusal leaves the offer standing; it is the only way back. With no offer, as after a page
	 * load, it asks for nothing.
	 *
	 * @return {Promise<void>} when the vehicle, the Entry, the paper or the edited Entry is back, or the import's entries gone
	 */
	async function restore() {
		if (deleted.value !== null) {
			upsert(await restoreVehicle(deleted.value))
			deleted.value = null
			return
		}

		const brought = imported.value
		if (brought !== null) {
			await counted(brought.vehicle, () => undoImport(brought.vehicle, brought.created))
			imported.value = null
			restored.value++
			return
		}

		const paper = detached.value
		if (paper !== null) {
			refiled.value = { vehicle: paper.vehicle, list: await restoreDocument(paper.vehicle, paper.document) }
			detached.value = null
			useInboxStore().refresh()
			return
		}

		const edit = saved.value
		const { entry, before } = edit ?? {}
		if (edit !== null && entry && before) {
			await moving(edit.vehicle, edit.type, () => updateEntry(edit.vehicle, edit.type, entry, before))
			saved.value = null
			restored.value++
			return
		}

		const offer = struck.value
		if (offer === null) {
			return
		}

		await moving(offer.vehicle, offer.type, () => restoreEntry(offer.vehicle, offer.type, offer.entry))
		struck.value = null
		restored.value++
	}

	/**
	 * Add a reminder, and tell whoever lists them. A refusal is not caught, as with `save()`.
	 *
	 * @param {string} uuid - the vehicle it hangs off
	 * @param {Record<string, unknown>} fields - what the sheet writes
	 * @return {Promise<import('../services/api.js').Reminder>} the reminder as the server wrote it
	 */
	async function remind(uuid, fields) {
		const reminder = await createReminder(uuid, fields)
		reminded.value++

		return reminder
	}

	/** Let go of the way back, which is what closing the toast means. */
	function forget() {
		deleted.value = null
		struck.value = null
		imported.value = null
		detached.value = null
		saved.value = null
	}

	/**
	 * Say the entry sheet's save went through, and for an edit hold the way back, one offer at a time
	 * with the others. A new Entry offers nothing, so it gives way to an offer still standing: its
	 * word leaves on a clock, and would take that way back with it.
	 *
	 * @param {string} uuid - the vehicle
	 * @param {import('../services/api.js').Written} type - which kind was saved
	 * @param {{uuid: string, updated_at: number}|null} [entry] - what an edit answered with
	 * @param {object|null} [before] - the whole Entry as the edit found it, as its update takes it
	 */
	function wrote(uuid, type, entry = null, before = null) {
		const standing = deleted.value !== null || struck.value !== null || imported.value !== null || detached.value !== null
			|| (saved.value !== null && saved.value.before !== null)
		if (before === null && standing) {
			return
		}
		deleted.value = null
		struck.value = null
		imported.value = null
		detached.value = null
		saved.value = { vehicle: uuid, type, entry, before }
	}

	/**
	 * Rewrite one Entry or Reminder under the token it was read with. A refusal is not caught, as
	 * with `save()`.
	 *
	 * @param {string} uuid - the vehicle it hangs off
	 * @param {import('../services/api.js').Written} type - which kind it is
	 * @param {{uuid: string, updated_at: number}} entry - the Entry as it was read
	 * @param {object} fields - the whole Entry, as the sheet holds it
	 * @return {Promise<object>} the Entry as the server now holds it
	 */
	async function revise(uuid, type, entry, fields) {
		return moving(uuid, type, () => updateEntry(uuid, type, entry, fields))
	}

	/**
	 * A write to an Entry or a Reminder, and the read of the vehicle after it where the write moves
	 * a counter. An Expense moves none (see spend()), and neither does a Reminder.
	 *
	 * @template T
	 * @param {string} uuid - the vehicle
	 * @param {import('../services/api.js').Written} type - which kind is written
	 * @param {() => Promise<T>} write - the write to make
	 * @return {Promise<T>} what the write answered with
	 */
	async function moving(uuid, type, write) {
		if (type === 'maintenance') {
			return serviced(uuid, write)
		}

		return type === 'expense' || type === 'reminder' ? write() : counted(uuid, write)
	}

	/**
	 * Record one reading of a vehicle's counter.
	 *
	 * @param {string} uuid - the vehicle the counter belongs to
	 * @param {object} entry - what the sheet holds: the value, and the offset it was read at
	 * @return {Promise<Reading>} the reading as the server judged it
	 */
	async function record(uuid, entry) {
		return counted(uuid, () => recordReading(uuid, entry))
	}

	/**
	 * Record one trip. It writes a Reading of its own — the counter it ended on, or the distance
	 * counted onto the chain (docs/architecture.md#odometer-rules).
	 *
	 * @param {string} uuid - the vehicle that drove it
	 * @param {object} trip - what the sheet holds (src/services/api.js)
	 * @return {Promise<import('../services/api.js').Trip>} the trip as the server wrote it
	 */
	async function log(uuid, trip) {
		return counted(uuid, () => recordTrip(uuid, trip))
	}

	/**
	 * Record one fill-up. Its counters are Readings too (docs/architecture.md#odometer-rules).
	 *
	 * @param {string} uuid - the vehicle that took it
	 * @param {object} entry - what the sheet holds (src/services/api.js)
	 * @return {Promise<object>} the fill-up as the server wrote it
	 */
	async function fill(uuid, entry) {
		return counted(uuid, () => recordEnergy(uuid, entry))
	}

	/**
	 * Record one Maintenance Record. Its counters are Readings too, as a fill-up's are.
	 *
	 * @param {string} uuid - the vehicle the work was done on
	 * @param {object} entry - what the sheet holds (src/services/api.js)
	 * @return {Promise<object>} the record as the server wrote it
	 */
	async function maintain(uuid, entry) {
		return serviced(uuid, () => recordMaintenance(uuid, entry))
	}

	/**
	 * A write to a Maintenance Record: counted() for its counters, and a word to whoever lists
	 * reminders, since the reminder it closes moves with it.
	 *
	 * @template T
	 * @param {string} uuid - the vehicle the work was done on
	 * @param {() => Promise<T>} write - the write to make
	 * @return {Promise<T>} what the write answered with
	 */
	async function serviced(uuid, write) {
		const written = await counted(uuid, write)
		reminded.value++

		return written
	}

	/**
	 * Record one Expense. It knows no counter and leaves the vehicle's row as it was, so there is
	 * nothing to read back.
	 *
	 * @param {string} uuid - the vehicle the money was spent on
	 * @param {object} entry - what the sheet holds (src/services/api.js)
	 * @return {Promise<object>} the expense as the server wrote it
	 */
	async function spend(uuid, entry) {
		return recordExpense(uuid, entry)
	}

	/**
	 * One write that moves a vehicle's counter, then a re-read of the vehicle: `odo_value` is the
	 * server's cache over the whole chain, and counting it here would duplicate the odometer rules
	 * (docs/architecture.md#odometer-rules).
	 *
	 * A failed re-read is swallowed: the write has landed, and a retry would write it twice, which
	 * nothing refuses. A stale counter until the next load is the cheaper wrong. A refused write is
	 * thrown, since the sheet must say so (docs/ui.md).
	 *
	 * @template T
	 * @param {string} uuid - the vehicle the counter belongs to
	 * @param {() => Promise<T>} write - the write to make
	 * @return {Promise<T>} what the write answered with
	 */
	async function counted(uuid, write) {
		const written = await write()
		await refresh(uuid)

		return written
	}

	/**
	 * Read one vehicle again after a write moved what it carries: its counter, or who has it. A
	 * failed read leaves it stale until the next load, for the reason counted() gives.
	 *
	 * @param {string} uuid - the vehicle
	 * @return {Promise<void>} when it is read, or the read failed
	 */
	async function refresh(uuid) {
		try {
			upsert(await getVehicle(uuid))
		} catch {
			// See counted(): the write stands, only this view of it is behind.
		}
	}

	return { bring, byUuid, create, deleted, detach, detached, fill, imported, forget, leave, list, load, log, maintain, record, refiled, refresh, remind, reminded, remove, restore, restored, revise, save, saved, spend, strike, struck, upsert, visible, wrote }
})
