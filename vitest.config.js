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
		// Explicit, so the run never wanders into vendor/, js/, or tests/e2e/, whose specs
		// are Playwright's and need a stack up. Specs about the app sit next to it in src/;
		// tests/js/ holds the ones about the repo itself.
		include: ['src/**/*.spec.js', 'tests/js/**/*.spec.js'],
		// @nextcloud/vue ships its components with their stylesheets imported, and Node
		// cannot load a `.css` file. Vite can, so the library is transformed rather than
		// left to the runtime — the price of mounting a component that uses one.
		server: { deps: { inline: [/@nextcloud\/vue/] } },
	},
})
