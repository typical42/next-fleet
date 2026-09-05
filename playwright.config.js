/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from '@playwright/test'

// One project per Nextcloud major in .docker/compose.yml, so a failure names the
// version that broke rather than "the E2E run". The stack has to be up already;
// docs/development.md has the two commands.
const majors = [
	{ name: 'nc34', baseURL: process.env.NEXTFLEET_URL_NC34 ?? 'http://localhost:8080' },
	{ name: 'nc31', baseURL: process.env.NEXTFLEET_URL_NC31 ?? 'http://localhost:8081' },
]

export default defineConfig({
	testDir: './tests/e2e',
	forbidOnly: Boolean(process.env.CI),
	reporter: 'list',
	use: {
		browserName: 'chromium',
	},
	projects: majors.map(({ name, baseURL }) => ({ name, use: { baseURL } })),
})
