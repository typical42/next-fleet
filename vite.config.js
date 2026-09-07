/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createAppConfig } from '@nextcloud/vite-config'
import { resolve } from 'node:path'

// One entry per page the app puts a bundle on: its own, and its block on the user's settings
// page. Vite names each output after its key, so `templates/personal.php` asks for
// `nextfleet-settings` (tests/Unit/PersonalTemplateTest.php).
export default createAppConfig({
	main: resolve(import.meta.dirname, 'src/main.js'),
	settings: resolve(import.meta.dirname, 'src/settings.js'),
})
