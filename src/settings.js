/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import SettingsView from './views/SettingsView.vue'

// The block on the personal settings page (templates/personal.php). It holds only one user's
// preferences, so it needs no Pinia.
const root = document.getElementById('nextfleet-settings')
if (root) {
	createApp(SettingsView).mount(root)
}
