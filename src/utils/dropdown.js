/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { autoUpdate, computePosition, flip, limitShift, offset, shift, size } from '@floating-ui/dom'

/**
 * One above NcModal's mask (9998), which every sheet sits in. NcSelect sets its 9999 on the field
 * only; the list it moves to <body> takes what the page's stylesheets leave there, and vue-select's
 * own `:root` value is 1000. Nextcloud 35 sets 9999 for the list in its server.css, a server
 * without that rule shows the list under the sheet.
 */
const ABOVE_SHEETS = '9999'

/**
 * Places the list of an NcSelect or NcSelectUsers, for `:calculate-position`. It follows NcSelect's
 * own placement with two changes. The list keeps its own z-index (ABOVE_SHEETS). And it is never
 * taller than the visible room on its side: with a phone's keyboard open, that room is small, and a
 * full list would run off the top of the screen.
 *
 * @param {HTMLElement} menu - the list, already in <body>
 * @param {{ $el: Element, $refs: Record<string, unknown> }} select - the vue-select inside the NcSelect
 * @return {() => void} stops following the field
 */
export function placeDropdown(menu, select) {
	const field = /** @type {HTMLElement} */ (select.$refs.toggle)
	menu.classList.add('vs__dropdown-menu--floating', 'nc-select__dropdown')
	menu.style.zIndex = ABOVE_SHEETS

	const update = () => computePosition(field, menu, {
		placement: 'bottom',
		middleware: [
			// Before offset, which reads the final side.
			flip(),
			// Above the field, the gap clears the field's floating label.
			offset(({ placement }) => placement.startsWith('top') ? 10 : -1),
			size({
				apply({ availableHeight }) {
					// Never under one row, else the list is a line that looks like nothing opened.
					menu.style.maxHeight = `min(var(--vs-dropdown-max-height), max(var(--default-clickable-area), ${availableHeight}px))`
				},
			}),
			shift({ limiter: limitShift() }),
		],
	}).then(({ x, y, placement }) => {
		const up = placement.startsWith('top')
		select.$el.classList.toggle('select--drop-up', up)
		menu.classList.toggle('vs__dropdown-menu--floating-placement-top', up)
		Object.assign(menu.style, {
			left: `${x}px`,
			top: `${y}px`,
			width: `${field.getBoundingClientRect().width}px`,
		})
	})

	const stop = autoUpdate(field, menu, update)
	// A phone's keyboard shrinks the visual viewport only; autoUpdate listens to the window.
	const viewport = window.visualViewport
	viewport?.addEventListener('resize', update)
	viewport?.addEventListener('scroll', update)

	return () => {
		stop()
		viewport?.removeEventListener('resize', update)
		viewport?.removeEventListener('scroll', update)
	}
}
