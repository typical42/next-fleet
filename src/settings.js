/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import SettingsView from './views/SettingsView.vue'

// The second entry point: the app's block on the user's own settings page, mounted from
// templates/personal.php. It holds no fleet, so it needs no Pinia - it reads and writes one
// user's preferences and nothing else.
const root = document.getElementById('nextfleet-settings')
if (root) {
	createApp(SettingsView).mount(root)
}
