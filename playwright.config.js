/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from '@playwright/test'

// One project per Nextcloud major in .docker/compose.yml, so a failure names the
// version that broke rather than "the E2E run". The stack has to be up already;
// docs/development.md has the two commands.
// A test tagged @nc34 runs on that major alone. The bundle is one bundle and its stylesheet is one
// stylesheet, so a test that measures the CSS answer - the responsive and dark-mode audit - would
// re-measure the same file on every other major and double the slowest suite in the repo.
const only34 = /@nc34/

const majors = [
	{ name: 'nc34', baseURL: process.env.NEXTFLEET_URL_NC34 ?? 'http://localhost:8080' },
	{ name: 'nc31', baseURL: process.env.NEXTFLEET_URL_NC31 ?? 'http://localhost:8081', grepInvert: only34 },
]

export default defineConfig({
	testDir: './tests/e2e',
	forbidOnly: Boolean(process.env.CI),
	reporter: 'list',
	// A freshly installed Nextcloud with an empty opcache, serving four browsers at once, takes
	// well over the default five seconds to answer the first page loads. That is CI's normal
	// state, and every assertion here waits on a request rather than on an animation. The test
	// budget goes up with it: a cold run of the M1 slice measured 30 s against the 30 s default.
	expect: { timeout: 15_000 },
	timeout: 90_000,
	use: {
		browserName: 'chromium',
	},
	projects: majors.map(({ name, baseURL, grepInvert }) => ({ name, grepInvert, use: { baseURL } })),
})
