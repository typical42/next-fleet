/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// tsc does not read single-file components; without this every `.vue` import is unresolved.
declare module '*.vue' {
	import type { DefineComponent } from 'vue'

	const component: DefineComponent
	export default component
}
