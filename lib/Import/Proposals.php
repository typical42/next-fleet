<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Import;

/**
 * What an importer made of a file: a proposal per row, and the questions only reading the rows
 * raised. While one is open the rows are read with a provisional answer, so the preview can show
 * them; the import itself waits for the user's.
 */
final class Proposals {
	public function __construct(
		/** @var list<Proposal> in the file's order */
		public readonly array $all,
		/** @var array<string, list<string>> question => what may answer it, such as `date_order` => [dmy, mdy] */
		public readonly array $open = [],
		/**
		 * @var list<string> the file's distinct category texts, answered or not, so an answer can be
		 *                   changed once `category_map` is no longer open
		 */
		public readonly array $categories = [],
		/**
		 * @var array<array-key, string> what a category text of $categories becomes unless the user
		 *                               answers otherwise; a code is an integer key, as PHP makes it
		 */
		public readonly array $defaults = [],
	) {
	}
}
