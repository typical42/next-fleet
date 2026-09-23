/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { addRecipient, closeGap, ConflictError, createReminder, createVehicle, csvUrl, deleteEntry, deleteVehicle, dismissReminder, getPreferences, listFleetReminders, listRecipients, listReminders, listVehicles, logbookUrl, mileageClaimUrl, readEntry, readGaps, readKpis, readTimeline, readYear, recordReading, recordTrip, reminderTemplates, removeRecipient, restoreEntry, searchUsers, restoreVehicle, savePreferences, snoozeReminder, updateEntry, updateVehicle } from './api.js'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (/** @type {string} */ path) => `/index.php${path}`,
	generateOcsUrl: (/** @type {string} */ path) => `/ocs/v2.php/${path}`,
}))
vi.mock('@nextcloud/auth', () => ({ getRequestToken: () => 'a-request-token' }))

/**
 * One answer from the server, as fetch hands it over.
 *
 * @param {number} status - the HTTP status
 * @param {unknown} body - the parsed JSON body
 */
function answers(status, body) {
	const fetch = vi.fn().mockResolvedValue({
		ok: status >= 200 && status < 300,
		status,
		json: async () => body,
	})
	vi.stubGlobal('fetch', fetch)

	return fetch
}

const vehicle = { uuid: '0195e2f1-0000-4000-8000-000000000001', plate: 'B-XY 123', updated_at: 1750000000 }

afterEach(() => {
	vi.unstubAllGlobals()
})

describe('listVehicles', () => {
	/** A read carries no body: `fetch` sends `undefined` as no body at all, `"null"` as a broken one. */
	it('asks for the fleet without inventing a body', async () => {
		const fetch = answers(200, [vehicle])

		const fleet = await listVehicles()

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain('/apps/nextfleet/api/vehicles')
		expect(options.method).toBe('GET')
		expect(options.body).toBeUndefined()
		expect(fleet).toEqual([vehicle])
	})
})

describe('createVehicle', () => {
	/**
	 * The create sheet asks four fields (docs/ui.md); everything else the server decides, so the
	 * answer is what the client keeps - identity and token included.
	 */
	it('sends what the sheet asked for and keeps what the server made of it', async () => {
		const fetch = answers(201, vehicle)

		const created = await createVehicle({ plate: 'B-XY 123' })

		const [, options] = fetch.mock.calls[0]
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ plate: 'B-XY 123' })
		expect(created.uuid).toBe(vehicle.uuid)
	})
})

describe('recordReading', () => {
	/**
	 * A new Reading hangs off its vehicle and carries no token: nothing was read that it could lose
	 * a race against (docs/architecture.md#concurrency).
	 */
	it('posts to the vehicle the reading belongs to', async () => {
		const fetch = answers(201, { uuid: 'r1', value: 148320, origin: 'observed', flagged: false })

		const reading = await recordReading(vehicle.uuid, { value: 148320, read_at_off: 120 })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}/readings`)
		expect(options.method).toBe('POST')
		expect(reading.origin).toBe('observed')
	})
})

describe('recordTrip', () => {
	/**
	 * A trip hangs off its vehicle and carries no token either: it is written, and under Logbook
	 * Mode later revised through the audit trail rather than overwritten
	 * (docs/architecture.md#concurrency). What comes back is the row the server wrote, which is
	 * not what was sent - `reconciled` is the app's to set (lib/Service/TripService.php).
	 */
	it('posts to the vehicle the trip belongs to and answers with the row the server wrote', async () => {
		const fetch = answers(201, { uuid: 't1', end_odo: 148402, distance: null, reconciled: false })

		const trip = await recordTrip(vehicle.uuid, { ended_at: 1750000000, end_odo: 148402 })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}/trips`)
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ ended_at: 1750000000, end_odo: 148402 })
		expect(trip.reconciled).toBe(false)
	})
})

describe('readTimeline', () => {
	const page = { rows: [{ type: 'trip', occurred_at: 1750000000, occurred_at_off: 120 }], next: null }

	/**
	 * The chip and the scroll position are the query string's, and both are optional: a request
	 * that sends neither asks for the newest rows of every kind
	 * (lib/Controller/TimelineController.php).
	 */
	it('asks for the newest rows of every kind when nothing narrows it', async () => {
		const fetch = answers(200, page)

		const answered = await readTimeline(vehicle.uuid, {})

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/timeline`)
		expect(options.method).toBe('GET')
		expect(options.body).toBeUndefined()
		expect(answered.rows).toHaveLength(1)
	})

	/**
	 * The cursor is the server's own word handed back (docs/architecture.md#the-timeline), so it
	 * travels as it was given - `:` and all, which is what encoding it as a parameter is for.
	 */
	it('sends the chip and the cursor it was handed', async () => {
		const fetch = answers(200, page)

		await readTimeline(vehicle.uuid, { type: 'trip', cursor: '1750000000:trip:42' })

		const [url] = fetch.mock.calls[0]
		expect(url).toContain('type=trip')
		expect(url).toContain(`cursor=${encodeURIComponent('1750000000:trip:42')}`)
	})

	/** A chip that narrows nothing is the absent parameter, not an empty one the server refuses. */
	it('leaves out what was not narrowed', async () => {
		const fetch = answers(200, page)

		await readTimeline(vehicle.uuid, { type: '', cursor: null })

		expect(fetch.mock.calls[0][0]).not.toContain('?')
	})
})

describe('closeGap', () => {
	/**
	 * The Gap is named by the trip that opened it, and what the driver confirmed travels with it:
	 * the server closes nothing that no longer matches (lib/Service/TripService.php).
	 */
	it('posts what the driver confirmed and answers with the trip that closed it', async () => {
		const fetch = answers(201, { uuid: 't-9', category: 'private', distance: 40, reconciled: true })

		const trip = await closeGap(vehicle.uuid, { trip: 't-1', distance: 40, from_at: 1750000000, from_at_off: 120, to_at: 1750100000, to_at_off: 120 })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/gaps/t-1/close`)
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ distance: 40, from_at: 1750000000, to_at: 1750100000 })
		expect(trip.reconciled).toBe(true)
	})

	/** A Gap that moved since it was read is a conflict, which the screen answers by reading again. */
	it('throws a conflict when the Gap has moved', async () => {
		answers(412, { message: 'Changed since you read it', conflict: true })

		await expect(closeGap(vehicle.uuid, { trip: 't-1', distance: 40, from_at: 1, from_at_off: 0, to_at: 2, to_at_off: 0 }))
			.rejects.toBeInstanceOf(ConflictError)
	})
})

describe('readKpis', () => {
	it('asks for one period and says whether VAT is reclaimed', async () => {
		const fetch = answers(200, { consumption: [], wall_side: null, cost: {}, hours: null })

		await readKpis(vehicle.uuid, { from: 1749900000, to: 1750200000, net: true })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/kpis?from=1749900000&to=1750200000&net=true`)
		expect(options.method).toBe('GET')
	})
})

describe('readYear', () => {
	/** The server cuts the months at the reader's midnight, so the reader names the zone. */
	it('asks for one year in the zone it names, and says whether VAT is reclaimed', async () => {
		const fetch = answers(200, { year: {}, months: [] })

		await readYear(vehicle.uuid, { year: '2026', tz: 'Europe/Berlin', net: false })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/costs/2026?tz=Europe%2FBerlin&net=false`)
		expect(options.method).toBe('GET')
	})
})

describe('readGaps', () => {
	/** The vehicle's Gaps, from the route beside its timeline (lib/Controller/TimelineController.php). */
	it('reads the Gaps of the vehicle it names', async () => {
		const fetch = answers(200, [{ trip: 't-1', distance: 40 }])

		const answered = await readGaps(vehicle.uuid)

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/gaps`)
		expect(options.method).toBe('GET')
		expect(answered).toHaveLength(1)
	})
})

describe('updateVehicle', () => {

	/**
	 * The write addresses the vehicle by uuid and carries the token it was read with, because
	 * that is what the server checks it against. Nextcloud refuses a session write without the
	 * request token, so it travels too.
	 */
	it('sends the identity and the token the server checks', async () => {
		const fetch = answers(200, { ...vehicle, plate: 'B-ZZ 9', updated_at: 1750000001 })

		const saved = await updateVehicle({ ...vehicle, plate: 'B-ZZ 9' })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}`)
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body).updated_at).toBe(1750000000)
		expect(options.headers.requesttoken).toBe('a-request-token')
		expect(saved.updated_at).toBe(1750000001)
	})

	/**
	 * The other tab saved first, so this write matched nothing. The sheet has to stay open and
	 * say so (docs/ui.md), which it cannot do if the client drops the answer.
	 */
	it('raises the conflict rather than swallowing it', async () => {
		answers(412, { message: 'Changed since you read it', conflict: true })

		await expect(updateVehicle(vehicle)).rejects.toBeInstanceOf(ConflictError)
	})

	/**
	 * Nextcloud refuses its own failed CSRF check with 412 too
	 * (docs/architecture.md#concurrency). Offering a retry there would loop, and the values are
	 * not what is wrong.
	 */
	it('does not read a failed CSRF check as a conflict', async () => {
		answers(412, { message: 'CSRF check failed' })

		const failure = await updateVehicle(vehicle).catch((error) => error)

		expect(failure).toBeInstanceOf(Error)
		expect(failure).not.toBeInstanceOf(ConflictError)
	})
})

describe('deleteVehicle', () => {
	/**
	 * A DELETE has no body, so the token the write is checked against travels in the query string
	 * (lib/Controller/VehicleController.php). What comes back is the vehicle as the delete left it,
	 * and the token on it is the only one the undo is accepted with
	 * (docs/architecture.md#concurrency).
	 */
	it('sends the token in the query string and answers with the one the undo needs', async () => {
		const fetch = answers(200, { ...vehicle, updated_at: 1750000002 })

		const deleted = await deleteVehicle(vehicle)

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}?updated_at=1750000000`)
		expect(options.method).toBe('DELETE')
		expect(options.body).toBeUndefined()
		expect(deleted.updated_at).toBe(1750000002)
	})
})

describe('restoreVehicle', () => {
	/**
	 * Undo is the one write that does not advance the token, so it is checked against the very
	 * token the delete answered with (docs/architecture.md#concurrency) - the toast holds that
	 * vehicle and hands it back here.
	 */
	it('posts the token the delete answered with', async () => {
		const deleted = { ...vehicle, updated_at: 1750000002 }
		const fetch = answers(200, deleted)

		const back = await restoreVehicle(deleted)

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}/restore`)
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ updated_at: 1750000002 })
		expect(back.uuid).toBe(vehicle.uuid)
	})

	/**
	 * The row moved on since the delete, so the token the toast holds matches nothing. It is the
	 * same refusal a save gets and it reaches the caller as one: the toast has to say the way back
	 * is gone rather than claim the vehicle is here.
	 */
	it('raises the conflict when the token no longer matches', async () => {
		answers(412, { message: 'Changed since you read it', conflict: true })

		await expect(restoreVehicle(vehicle)).rejects.toBeInstanceOf(ConflictError)
	})
})

const settings = {
	preferences: { jurisdiction: 'de' },
	jurisdictions: [{ key: 'de', name: 'Germany' }, { key: 'generic', name: 'Generic' }],
}

describe('getPreferences', () => {
	/**
	 * No identity in the URL: a session reaches its own settings and no others
	 * (lib/Service/PreferencesService.php).
	 */
	it('asks for the settings of whoever is logged in', async () => {
		const fetch = answers(200, settings)

		const state = await getPreferences()

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain('/apps/nextfleet/api/preferences')
		expect(options.method).toBe('GET')
		expect(state.jurisdictions.map((one) => one.key)).toEqual(['de', 'generic'])
	})
})

describe('savePreferences', () => {
	/**
	 * A preference carries no `updated_at`: it has one writer, so there is no race to lose
	 * (docs/architecture.md#concurrency). The answer is the whole screen again, options included.
	 */
	it('writes the chosen value and keeps the state that comes back', async () => {
		const fetch = answers(200, { ...settings, preferences: { jurisdiction: 'generic' } })

		const saved = await savePreferences({ jurisdiction: 'generic' })

		const [, options] = fetch.mock.calls[0]
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body)).toEqual({ jurisdiction: 'generic' })
		expect(saved.preferences.jurisdiction).toBe('generic')
	})
})

describe('logbookUrl', () => {
	/**
	 * The export is a page the browser opens, not an answer this client reads, so it is an address
	 * outside `/api` and nothing is fetched (docs/architecture.md#the-fahrtenbuch-export).
	 */
	it('names one vehicle and one year outside the api', () => {
		expect(logbookUrl(vehicle.uuid, '2025')).toBe(`/index.php/apps/nextfleet/vehicles/${vehicle.uuid}/logbook/2025`)
	})
})

describe('mileageClaimUrl', () => {
	/** A page the browser opens, beside the logbook for the same reason. */
	it('names one vehicle and one year outside the api', () => {
		expect(mileageClaimUrl(vehicle.uuid, '2025')).toBe(`/index.php/apps/nextfleet/vehicles/${vehicle.uuid}/mileage/2025`)
	})
})

describe('csvUrl', () => {
	/** A file the browser saves, beside the logbook for the same reason. */
	it('names one vehicle, one year and one table outside the api', () => {
		expect(csvUrl(vehicle.uuid, '2025', 'trips')).toBe(`/index.php/apps/nextfleet/vehicles/${vehicle.uuid}/csv/2025/trips`)
	})
})

describe('the Entry writes', () => {
	const entry = { uuid: 'e-1', updated_at: 1750000100 }

	/** Each kind is written under its own collection, and the edit carries the token beside the fields. */
	it('puts an edit where its kind lives, token and all', async () => {
		const fetch = answers(200, entry)

		await updateEntry(vehicle.uuid, 'odometer', entry, { value: 148400 })

		const [url, options] = fetch.mock.calls[0]
		expect(url).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/readings/e-1`)
		expect(options.method).toBe('PUT')
		expect(JSON.parse(options.body)).toEqual({ value: 148400, updated_at: 1750000100 })
	})

	it('deletes with the token in the query string, and restores with the one it answered', async () => {
		const fetch = answers(200, entry)

		await deleteEntry(vehicle.uuid, 'expense', entry)
		await restoreEntry(vehicle.uuid, 'trip', entry)

		expect(fetch.mock.calls[0][0]).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/expenses/e-1?updated_at=1750000100`)
		expect(fetch.mock.calls[0][1].method).toBe('DELETE')
		expect(fetch.mock.calls[1][0]).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/trips/e-1/restore`)
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ updated_at: 1750000100 })
	})

	it('reads one Entry back as its timeline row', async () => {
		const fetch = answers(200, { type: 'energy', energy: entry })

		await readEntry(vehicle.uuid, 'energy', 'e-1')

		expect(fetch.mock.calls[0][0]).toBe(`/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/timeline/energy/e-1`)
	})
})

describe('the reminder calls', () => {
	const reminder = { uuid: 'r-1', updated_at: 1750000200 }
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}`

	it('reads the list and the templates of the vehicle they hang off', async () => {
		const fetch = answers(200, [])

		await listReminders(vehicle.uuid)
		await reminderTemplates(vehicle.uuid)

		expect(fetch.mock.calls[0][0]).toBe(`${base}/reminders`)
		expect(fetch.mock.calls[1][0]).toBe(`${base}/reminder-templates`)
	})

	it('reads the whole fleet\'s in one request', async () => {
		const fetch = answers(200, [])

		await listFleetReminders()

		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/nextfleet/api/reminders')
	})

	it('creates without a token and snoozes and dismisses with one', async () => {
		const fetch = answers(200, reminder)

		await createReminder(vehicle.uuid, { template_key: 'hu_au', due_date: '2027-05-31' })
		await snoozeReminder(vehicle.uuid, reminder, '2026-10-01')
		await dismissReminder(vehicle.uuid, reminder)

		expect(fetch.mock.calls[0][0]).toBe(`${base}/reminders`)
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual({ template_key: 'hu_au', due_date: '2027-05-31' })
		expect(fetch.mock.calls[1][0]).toBe(`${base}/reminders/r-1/snooze`)
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ until: '2026-10-01', updated_at: 1750000200 })
		expect(fetch.mock.calls[2][0]).toBe(`${base}/reminders/r-1/dismiss`)
		expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ updated_at: 1750000200 })
	})

	/** An edit, a delete and its undo are reached the way an Entry's are, so they share its calls. */
	it('edits, deletes and restores under the reminders collection', async () => {
		const fetch = answers(200, reminder)

		await updateEntry(vehicle.uuid, 'reminder', reminder, { mode: 'date' })
		await deleteEntry(vehicle.uuid, 'reminder', reminder)
		await restoreEntry(vehicle.uuid, 'reminder', reminder)

		expect(fetch.mock.calls[0][0]).toBe(`${base}/reminders/r-1`)
		expect(fetch.mock.calls[1][0]).toBe(`${base}/reminders/r-1?updated_at=1750000200`)
		expect(fetch.mock.calls[2][0]).toBe(`${base}/reminders/r-1/restore`)
	})
})

describe('the recipient calls', () => {
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/recipients`

	// An account name may hold a space or an at sign, and it travels in the path of a remove.
	it('reads, adds and removes by account without a token', async () => {
		const fetch = answers(200, [])

		await listRecipients(vehicle.uuid)
		await addRecipient(vehicle.uuid, 'jane doe@example.org')
		await removeRecipient(vehicle.uuid, 'jane doe@example.org')

		expect(fetch.mock.calls[0][0]).toBe(base)
		expect(fetch.mock.calls[1][0]).toBe(base)
		expect(fetch.mock.calls[1][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ user_id: 'jane doe@example.org' })
		expect(fetch.mock.calls[2][0]).toBe(`${base}/jane%20doe%40example.org`)
		expect(fetch.mock.calls[2][1].method).toBe('DELETE')
	})

	/** The picker asks core, which applies the instance's own rules on who may find whom. */
	it('searches accounts through the core autocomplete and hands back id and name', async () => {
		const fetch = answers(200, { ocs: { data: [{ id: 'jane', label: 'Jane Doe', source: 'users' }] } })

		const found = await searchUsers('ja ne')

		const url = new URL(fetch.mock.calls[0][0], 'https://cloud.example')
		expect(url.pathname).toBe('/ocs/v2.php/core/autocomplete/get')
		expect(url.searchParams.get('search')).toBe('ja ne')
		expect(url.searchParams.getAll('shareTypes[]')).toEqual(['0'])
		expect(fetch.mock.calls[0][1].headers['OCS-APIRequest']).toBe('true')
		expect(found).toEqual([{ user_id: 'jane', display_name: 'Jane Doe' }])
	})
})
