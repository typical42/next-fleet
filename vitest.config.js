/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { defineConfig } from 'vitest/config'

// This file shadows vite.config.js, so the Vue plugin the app build gets from
// @nextcloud/vite-config is absent here. The first spec that imports a .vue
// file has to add @vitejs/plugin-vue and a DOM environment.
export default defineConfig({
	test: {
		// Explicit, so the run never wanders into vendor/ or js/.
		include: ['src/**/*.spec.js'],
	},
})
