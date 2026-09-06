/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { ConflictError, createVehicle, listVehicles, recordReading, updateVehicle } from './api.js'

vi.mock('@nextcloud/router', () => ({
	generateUrl: (/** @type {string} */ path) => `/index.php${path}`,
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
	 * A Reading hangs off its vehicle and carries no token: it is only ever written, never
	 * updated (docs/architecture.md#concurrency).
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
