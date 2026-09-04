/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'

// The bundle is loaded by the app's own page template, which carries this
// element. Guarded so that loading it anywhere else is inert, not a console
// error.
const root = document.getElementById('nextfleet')
if (root) {
	createApp(App).use(createPinia()).mount(root)
}
