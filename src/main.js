/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// The bundle is loaded by the app's own page template, which carries this
// element. Guarded so that loading it anywhere else is inert, not a console
// error.
const root = document.getElementById('nextfleet')

// On NC 31 a second copy of this entry runs, so it mounts once and holds no state of
// its own (docs/development.md, "The main entry is one dynamic import").
if (root && root.dataset.booted === undefined) {
	root.dataset.booted = ''
	import('./boot.js').then(({ mount }) => mount(root))
}
