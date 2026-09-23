/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { request } from 'node:http'

/**
 * What only the server side can do: run the reminder job at a moved clock. Through the Docker
 * Engine API on its socket rather than the docker CLI, because `npm run test:e2e:docker` runs
 * inside Playwright's image, which has the socket mounted and no CLI.
 */

const socketPath = '/var/run/docker.sock'

/**
 * The compose service behind each project in playwright.config.js (.docker/compose.yml).
 *
 * @type {Record<string, string>}
 */
const services = { nc34: 'app', nc31: 'app31' }

/**
 * @param {string} method - the HTTP verb
 * @param {string} path - below the Engine API's root
 * @param {object} [body] - sent as JSON
 * @return {Promise<{status: number, text: string}>} the answer, whole
 */
function engine(method, path, body) {
	return new Promise((resolve, reject) => {
		const call = request({ socketPath, method, path, headers: { 'Content-Type': 'application/json' } }, (response) => {
			/** @type {Buffer[]} */
			const chunks = []
			response.on('data', (chunk) => chunks.push(chunk))
			response.on('end', () => resolve({ status: response.statusCode ?? 0, text: Buffer.concat(chunks).toString() }))
		})
		call.on('error', reject)
		call.end(body === undefined ? undefined : JSON.stringify(body))
	})
}

/**
 * Runs one command as www-data in the project's Nextcloud container and fails loudly on a
 * non-zero exit, with what it printed.
 *
 * @param {string} project - `testInfo.project.name`
 * @param {string[]} command - argv, from the server root
 * @return {Promise<string>} what it printed
 */
async function exec(project, command) {
	const labels = ['com.docker.compose.project=nextfleet', `com.docker.compose.service=${services[project]}`]
	const found = await engine('GET', `/containers/json?filters=${encodeURIComponent(JSON.stringify({ label: labels }))}`)
	const [container] = JSON.parse(found.text)
	if (container === undefined) {
		throw new Error(`No running container for ${project} - is .docker/compose.yml up?`)
	}

	const created = await engine('POST', `/containers/${container.Id}/exec`, {
		Cmd: command,
		User: 'www-data',
		WorkingDir: '/var/www/html',
		AttachStdout: true,
		AttachStderr: true,
		// A terminal sends one plain stream; without one, each chunk carries a frame header.
		Tty: true,
	})
	const { Id } = JSON.parse(created.text)
	const { text } = await engine('POST', `/exec/${Id}/start`, { Detach: false, Tty: true })
	// The stream can end before Docker has recorded the exit.
	let state = JSON.parse((await engine('GET', `/exec/${Id}/json`)).text)
	while (state.Running) {
		await new Promise((resolve) => setTimeout(resolve, 100))
		state = JSON.parse((await engine('GET', `/exec/${Id}/json`)).text)
	}
	const { ExitCode } = state
	if (ExitCode !== 0) {
		throw new Error(`${command.join(' ')} exited ${ExitCode}:\n${text}`)
	}

	return text
}

/**
 * Runs ReminderJob once at the given instant (tests/e2e/job.php).
 *
 * @param {string} project - `testInfo.project.name`
 * @param {Date} at - the moment the job believes it is
 * @return {Promise<string>} what it printed
 */
export function runJobAt(project, at) {
	return exec(project, ['php', 'custom_apps/nextfleet/tests/e2e/job.php', at.toISOString()])
}
