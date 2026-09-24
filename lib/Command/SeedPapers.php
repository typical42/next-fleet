<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * The demo fleet's papers, put in the seeding account's own Files as the picker would find them.
 * A class of its own because `IRootFolder` cannot be mocked outside a server, and SeedCommand's
 * unit test runs outside one.
 */
class SeedPapers {
	private const FOLDER = 'Fleet demo';

	public function __construct(
		private IRootFolder $root,
	) {
	}

	/**
	 * A stand-in scan, written over on a re-run so the demo keeps one copy. An SVG, because a real
	 * format would need a library to write (ADR 0005).
	 *
	 * @param string $says the one line it shows
	 * @return int its `file_id`
	 * @throws NotPermittedException also when the name is taken by the other kind of node
	 * @throws NotFoundException
	 */
	public function write(string $userId, string $name, string $says): int {
		$home = $this->root->getUserFolder($userId);
		$folder = $home->nodeExists(self::FOLDER) ? $home->get(self::FOLDER) : $home->newFolder(self::FOLDER);
		if (!$folder instanceof Folder) {
			throw new NotPermittedException(self::FOLDER . ' is a file in ' . $userId . '\'s Files');
		}
		$content = '<svg xmlns="http://www.w3.org/2000/svg" width="420" height="297" viewBox="0 0 420 297">'
			. '<rect width="420" height="297" fill="#f4f1e8" stroke="#555"/>'
			. '<text x="24" y="48" font-family="sans-serif" font-size="20">' . htmlspecialchars($says, ENT_XML1) . '</text>'
			. '<text x="24" y="80" font-family="sans-serif" font-size="14">Demo</text></svg>';
		if (!$folder->nodeExists($name)) {
			return $folder->newFile($name, $content)->getId();
		}

		$file = $folder->get($name);
		if (!$file instanceof File) {
			throw new NotPermittedException($name . ' is a folder in ' . $userId . '\'s Files');
		}
		$file->putContent($content);

		return $file->getId();
	}
}
