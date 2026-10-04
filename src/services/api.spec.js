/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { addGrant, addRecipient, attachDocument, BookingConflictError, cancelBooking, ChangedError, changeBooking, changeGrant, checkIn, checkOut, closeGap, createBooking, ImportRefusedError, listBookings, ConflictError, previewImport, runImport, undoImport, createReminder, createVehicle, csvUrl, deleteEntry, deleteVehicle, detachDocument, dismissReminder, documentUrl, fetchDocument, getPreferences, LockedError, restoreDocument, listDocuments, listFleetReminders, listGrants, listRecipients, listReminders, listVehicles, logbookUrl, mileageClaimUrl, NotFoundError, OfflineError, readEntry, readGaps, readInbox, readKpis, readTimeline, readYear, recordReading, recordTrip, RefusedError, reminderTemplates, removeRecipient, resetReading, restoreEntry, revokeGrant, searchGrantees, searchUsers, restoreVehicle, savePreferences, snoozeReminder, stickerUrl, thumbnailUrl, updateEntry, updateVehicle } from './api.js'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (/** @type {string} */ path) => `/index.php${path}`,
	generateOcsUrl: (/** @type {string} */ path) => `/ocs/v2.php/${path}`,
	imagePath: (/** @type {string} */ app, /** @type {string} */ file) => `/${app}/img/${file}.svg`,
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

describe('a request that never reached the server', () => {
	/**
	 * fetch rejects with a TypeError when there is no network, in words that differ per browser
	 * ("Failed to fetch", "Load failed"). A driver with no signal is told so, and that what they
	 * typed is still there (docs/ui.md, a failed save is never lost).
	 */
	it('says there is no connection, whatever the browser called it', async () => {
		vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Load failed')))

		const asked = recordTrip(vehicle.uuid, { distance: 82 })

		await expect(asked).rejects.toBeInstanceOf(OfflineError)
		await expect(asked).rejects.toThrow('No connection to the server. Everything you typed is still here — try again when you have signal.')
	})

	/** Only the network's own failure: anything else is somebody's bug and keeps its words. */
	it('lets any other failure through as it was', async () => {
		vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new RangeError('a bug')))

		await expect(listVehicles()).rejects.toThrow('a bug')
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

	/** A 400 that names its reason carries it, so the sheet can say it in the reader's words. */
	it('carries the reason a refused field names', async () => {
		answers(400, { message: 'currency stays', reason: 'currency_in_use' })

		const failure = await updateVehicle({ ...vehicle, currency: 'CHF' }).catch((error) => error)

		expect(failure).toBeInstanceOf(RefusedError)
		expect([failure.message, failure.reason]).toEqual(['currency stays', 'currency_in_use'])
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
	 * Undo is checked against the very token the delete answered with
	 * (docs/architecture.md#concurrency) - the toast holds that vehicle and hands it back here.
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

describe('resetReading', () => {
	/** Any Entry's Reading, reached as a Reading, and checked against the token it was read with. */
	it('posts the token the reading was read with', async () => {
		const reading = { uuid: 'r-1', updated_at: 1750000005 }
		const fetch = answers(200, { ...reading, kind: 'reset', flagged: false })

		const answered = await resetReading(vehicle.uuid, reading)

		const [url, options] = fetch.mock.calls[0]
		expect(url).toContain(`/apps/nextfleet/api/vehicles/${vehicle.uuid}/readings/r-1/reset`)
		expect(options.method).toBe('POST')
		expect(JSON.parse(options.body)).toEqual({ updated_at: 1750000005 })
		expect(answered.kind).toBe('reset')
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

describe('the documents', () => {
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/documents`

	/** Each write answers with the list as it now stands, so there is no token to send. */
	it('lists, attaches and detaches under the vehicle', async () => {
		const fetch = answers(200, [])

		await listDocuments(vehicle.uuid)
		await attachDocument(vehicle.uuid, { file_id: 42, kind: 'receipt', linked_type: 'maintenance', linked_uuid: 'm-1' })
		await detachDocument(vehicle.uuid, 'd-1')
		await restoreDocument(vehicle.uuid, 'd-1')

		expect(fetch.mock.calls[3][0]).toBe(`${base}/d-1/restore`)
		expect(fetch.mock.calls[3][1].method).toBe('POST')
		expect(fetch.mock.calls[0][0]).toBe(base)
		expect(fetch.mock.calls[0][1].method).toBe('GET')
		expect(fetch.mock.calls[1][0]).toBe(base)
		expect(fetch.mock.calls[1][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ file_id: 42, kind: 'receipt', linked_type: 'maintenance', linked_uuid: 'm-1' })
		expect(fetch.mock.calls[2][0]).toBe(`${base}/d-1`)
		expect(fetch.mock.calls[2][1].method).toBe('DELETE')
	})

	/**
	 * A 404 on attach is the file not being the person's own (docs/architecture.md#documents),
	 * which the section says in its own words; the server's message names only the vehicle.
	 */
	it('tells a refusal for something not there apart from the rest', async () => {
		answers(404, { message: 'No such vehicle' })

		const failure = await attachDocument(vehicle.uuid, { file_id: 42, kind: 'receipt' }).catch((error) => error)

		expect(failure).toBeInstanceOf(NotFoundError)
		expect(failure.message).toBe('No such vehicle')
	})

	/** A link the browser follows, outside the api beside the CSV. */
	it('downloads one paper outside the api', () => {
		expect(documentUrl(vehicle.uuid, 'd-1')).toBe(`/index.php/apps/nextfleet/vehicles/${vehicle.uuid}/documents/d-1`)
	})

	/** Fetched rather than followed, so a refusal is said on the screen and not in a tab of JSON. */
	it('fetches one paper\'s file from that address', async () => {
		const file = new Blob(['%PDF'])
		const fetch = vi.fn().mockResolvedValue({ ok: true, status: 200, blob: async () => file })
		vi.stubGlobal('fetch', fetch)

		expect(await fetchDocument(vehicle.uuid, 'd-1')).toBe(file)
		expect(fetch.mock.calls[0][0]).toBe(documentUrl(vehicle.uuid, 'd-1'))
	})

	it('tells a paper that is gone, and one being written, from the rest', async () => {
		answers(404, { message: 'No such document' })
		expect(await fetchDocument(vehicle.uuid, 'd-1').catch((error) => error)).toBeInstanceOf(NotFoundError)

		answers(423, { message: 'Being written, try again' })
		expect(await fetchDocument(vehicle.uuid, 'd-1').catch((error) => error)).toBeInstanceOf(LockedError)

		answers(403, { message: 'Not yours' })
		const refused = await fetchDocument(vehicle.uuid, 'd-1').catch((error) => error)
		expect(refused.message).toBe('Not yours')
	})
})

describe('the import calls', () => {
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/import`
	const body = { file_id: 42, importer: 'lubelogger', record_type: 'fuel', units: { distance: 'km', volume: 'l' }, tz: 'Europe/Berlin' }

	it('previews, imports and undoes under the vehicle', async () => {
		const fetch = answers(200, {})

		await previewImport(vehicle.uuid, body)
		await runImport(vehicle.uuid, { ...body, etag: 'e-1' })
		await undoImport(vehicle.uuid, [{ type: 'energy', uuid: 'e-1' }])

		expect(fetch.mock.calls.map(([url, options]) => [url, options.method])).toEqual([
			[`${base}/preview`, 'POST'],
			[base, 'POST'],
			[`${base}/undo`, 'POST'],
		])
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ ...body, etag: 'e-1' })
		expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ created: [{ type: 'energy', uuid: 'e-1' }] })
	})

	/** The screen previews again on a changed file, and drops the undo on changed entries. */
	it('tells a 409 that is no booking apart from the rest', async () => {
		answers(409, { message: 'The file changed since the preview' })

		const failure = await runImport(vehicle.uuid, { ...body, etag: 'e-1' }).catch((error) => error)

		expect(failure).toBeInstanceOf(ChangedError)
		expect(failure.message).toBe('The file changed since the preview')
	})

	/** The reason is a word the screen puts into words; the message is English for a log. */
	it('carries why a file is not read', async () => {
		answers(422, { message: 'Not a file an import reads: binary', reason: 'binary', row: 3 })

		const failure = await previewImport(vehicle.uuid, body).catch((error) => error)

		expect(failure).toBeInstanceOf(ImportRefusedError)
		expect(failure.reason).toBe('binary')
		expect(failure.row).toBe(3)
	})
})

describe('the inbox', () => {
	/** One request answers the folder, the files waiting in it and how many there are. */
	it('reads the waiting files outside any vehicle', async () => {
		const inbox = { folder: { file_id: 7, path: '/Belege' }, files: [], count: 0 }
		const fetch = answers(200, inbox)

		expect(await readInbox()).toEqual(inbox)
		expect(fetch.mock.calls[0][0]).toBe('/index.php/apps/nextfleet/api/inbox')
		expect(fetch.mock.calls[0][1].method).toBe('GET')
	})

	/** Nextcloud's own preview; a PDF, which core may not preview, shows core's PDF icon instead. */
	it('shows a thumbnail by core\'s preview, and a PDF by its icon', () => {
		expect(thumbnailUrl({ file_id: 42, mime: 'image/jpeg' })).toBe('/index.php/core/preview?fileId=42&x=256&y=256&a=1&mimeFallback=true')
		expect(thumbnailUrl({ file_id: 43, mime: 'application/pdf' })).toBe('/core/img/filetypes/application-pdf.svg')
	})
})

describe('stickerUrl', () => {
	/** A phone's camera opens it with nothing to resolve it against, so it names the server. */
	it('is an absolute address that opens the entry sheet on the vehicle', () => {
		expect(stickerUrl(vehicle.uuid)).toBe(`${window.location.origin}/index.php/apps/nextfleet/?vehicle=${vehicle.uuid}&entry=new`)
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

describe('the booking calls', () => {
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/bookings`
	const booking = { uuid: 'b-1', updated_at: 1750000300 }
	const span = { starts_at: 1790000000, starts_at_off: 120, ends_at: 1790007200, ends_at_off: 120, purpose: 'Client' }

	/** A change states the whole booking beside its token; a cancel has no body, so its token is in the query. */
	it('lists, books, changes and cancels under the vehicle', async () => {
		const fetch = answers(200, booking)

		await listBookings(vehicle.uuid)
		await createBooking(vehicle.uuid, span)
		await changeBooking(vehicle.uuid, booking, span)
		await cancelBooking(vehicle.uuid, booking)

		expect(fetch.mock.calls[0][0]).toBe(base)
		expect(fetch.mock.calls[0][1].method).toBe('GET')
		expect(fetch.mock.calls[1][0]).toBe(base)
		expect(fetch.mock.calls[1][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual(span)
		expect(fetch.mock.calls[2][0]).toBe(`${base}/b-1`)
		expect(fetch.mock.calls[2][1].method).toBe('PUT')
		expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ ...span, updated_at: 1750000300 })
		expect(fetch.mock.calls[3][0]).toBe(`${base}/b-1?updated_at=1750000300`)
		expect(fetch.mock.calls[3][1].method).toBe('DELETE')
	})

	/** The 409 carries the booking in the way, so the sheet names it without a second read. */
	it('hands over the booking that holds the span', async () => {
		const held = { uuid: 'b-2', user_id: 'anna', user_name: 'Anna', starts_at: 1790000000, starts_at_off: 120, ends_at: 1790014400, ends_at_off: 120 }
		answers(409, { message: 'the vehicle is booked then', booking: held })

		const failure = await createBooking(vehicle.uuid, span).catch((error) => error)

		expect(failure).toBeInstanceOf(BookingConflictError)
		expect(failure.booking).toEqual(held)
	})

	/** No token: the state the booking must be in is the guard against a second submit. */
	it('checks out and in under the booking, with the handover and no token', async () => {
		const fetch = answers(200, booking)
		const handover = { odo: 52000, level: 80, notes: 'Scratch on the left door', at_off: 120 }

		await checkOut(vehicle.uuid, booking, handover)
		await checkIn(vehicle.uuid, booking, handover)

		expect(fetch.mock.calls[0][0]).toBe(`${base}/b-1/check-out`)
		expect(fetch.mock.calls[0][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[0][1].body)).toEqual(handover)
		expect(fetch.mock.calls[1][0]).toBe(`${base}/b-1/check-in`)
		expect(fetch.mock.calls[1][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual(handover)
	})
})

describe('the grant calls', () => {
	const base = `/index.php/apps/nextfleet/api/vehicles/${vehicle.uuid}/grants`

	it('lists, grants, changes and revokes without a token', async () => {
		const fetch = answers(200, [])

		await listGrants(vehicle.uuid)
		await addGrant(vehicle.uuid, { grantee: 'crew', grantee_type: 'group' }, 'driver')
		await changeGrant(vehicle.uuid, 'g-1', 'manager')
		await revokeGrant(vehicle.uuid, 'g-1')

		expect(fetch.mock.calls[0][0]).toBe(base)
		expect(fetch.mock.calls[1][1].method).toBe('POST')
		expect(JSON.parse(fetch.mock.calls[1][1].body)).toEqual({ grantee: 'crew', grantee_type: 'group', role: 'driver' })
		expect(fetch.mock.calls[2][0]).toBe(`${base}/g-1`)
		expect(fetch.mock.calls[2][1].method).toBe('PUT')
		expect(JSON.parse(fetch.mock.calls[2][1].body)).toEqual({ role: 'manager' })
		expect(fetch.mock.calls[3][0]).toBe(`${base}/g-1`)
		expect(fetch.mock.calls[3][1].method).toBe('DELETE')
	})

	/** Users and groups both, and a group keeps its type: a gid may also be somebody's uid. */
	it('searches accounts and groups through the core autocomplete', async () => {
		const fetch = answers(200, {
			ocs: {
				data: [
					{ id: 'jane', label: 'Jane Doe', source: 'users' },
					{ id: 'crew', label: 'The Crew', source: 'groups' },
				],
			},
		})

		const found = await searchGrantees('cr')

		const url = new URL(fetch.mock.calls[0][0], 'https://cloud.example')
		expect(url.pathname).toBe('/ocs/v2.php/core/autocomplete/get')
		expect(url.searchParams.get('search')).toBe('cr')
		expect(url.searchParams.getAll('shareTypes[]')).toEqual(['0', '1'])
		expect(found).toEqual([
			{ grantee: 'jane', grantee_type: 'user', display_name: 'Jane Doe' },
			{ grantee: 'crew', grantee_type: 'group', display_name: 'The Crew' },
		])
	})
})
