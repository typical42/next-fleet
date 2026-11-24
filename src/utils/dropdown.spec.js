/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { placeDropdown } from './dropdown.js'

/** @type {HTMLElement} */
let menu
/** @type {{ $el: HTMLElement, $refs: { toggle: HTMLElement } }} */
let select
/** @type {EventTarget} */
let viewport

beforeEach(() => {
	const root = document.createElement('div')
	const toggle = document.createElement('div')
	root.append(toggle)
	menu = document.createElement('ul')
	document.body.append(root, menu)
	select = { $el: root, $refs: { toggle } }
	// happy-dom has none; a phone's keyboard is what moves it.
	viewport = Object.assign(new EventTarget(), { width: 412, height: 420, offsetLeft: 0, offsetTop: 0 })
	vi.stubGlobal('visualViewport', viewport)
})

afterEach(() => {
	document.body.replaceChildren()
	vi.unstubAllGlobals()
})

/**
 * Lets the position computed on the last change land.
 *
 * @return {Promise<void>}
 */
function settled() {
	return new Promise((resolve) => setTimeout(resolve))
}

describe('placeDropdown', () => {
	it('lifts the list above the sheet whatever the page\'s stylesheets say', () => {
		const stop = placeDropdown(menu, select)
		expect(menu.style.zIndex).toBe('9999')
		expect(menu.classList).toContain('vs__dropdown-menu--floating')
		expect(menu.classList).toContain('nc-select__dropdown')
		stop()
	})

	it('places the list and caps it at the room on its side', async () => {
		const stop = placeDropdown(menu, select)
		await settled()
		expect(menu.style.top).toMatch(/px$/)
		expect(menu.style.left).toMatch(/px$/)
		expect(menu.style.maxHeight).toMatch(/^min\(var\(--vs-dropdown-max-height\), max\(var\(--default-clickable-area\), -?[\d.]+px\)\)$/)
		stop()
	})

	it('follows the keyboard until it is stopped', async () => {
		const stop = placeDropdown(menu, select)
		await settled()
		menu.style.top = ''
		viewport.dispatchEvent(new Event('resize'))
		await settled()
		expect(menu.style.top).toMatch(/px$/)

		stop()
		menu.style.top = ''
		viewport.dispatchEvent(new Event('resize'))
		await settled()
		expect(menu.style.top).toBe('')
	})
})
