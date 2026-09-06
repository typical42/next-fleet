/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vitest/config'

// This file shadows vite.config.js, so the Vue plugin @nextcloud/vite-config gives
// the app build has to be declared again here.
export default defineConfig({
	plugins: [vue()],
	test: {
		// @nextcloud/auth reaches for `window` at module scope, so every spec that
		// imports the api client for real needs a document to load against.
		environment: 'happy-dom',
		// Explicit, so the run never wanders into vendor/ or js/.
		include: ['src/**/*.spec.js'],
	},
})
