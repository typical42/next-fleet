/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { encode } from 'uqr'

/**
 * A QR code as one SVG path, so it prints at any size without blurring. Level M survives a
 * scuffed sticker; the four-module quiet zone is the one the standard asks for, drawn into the
 * code so no stylesheet can take it away.
 *
 * @param {string} text - what the code carries
 * @return {{size: number, path: string}} the side in modules and the dark modules, one
 *   rectangle per horizontal run
 */
export function qrOf(text) {
	const { data, size } = encode(text, { ecc: 'M', border: 4 })
	/** @type {string[]} */
	const runs = []
	data.forEach((row, y) => {
		let x = 0
		while (x < size) {
			if (!row[x]) {
				x++
				continue
			}
			const start = x
			while (x < size && row[x]) {
				x++
			}
			runs.push(`M${start} ${y}h${x - start}v1h-${x - start}z`)
		}
	})
	return { size, path: runs.join('') }
}
