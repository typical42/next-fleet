/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'

/**
 * @param {Element} root - the element the page template carries
 */
export function mount(root) {
	createApp(App).use(createPinia()).mount(root)
}
