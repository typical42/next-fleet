<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\DocumentMapper;
use OCP\Files\File;
use OCP\Files\IRootFolder;

/**
 * The receipt inbox (docs/architecture.md#the-inbox): the images and PDFs in the user's chosen
 * folder that no paper of theirs references yet. It only lists; attaching is DocumentService's,
 * and the files are never moved.
 *
 * @psalm-import-type NextFleetWaiting from \OCA\NextFleet\ResponseDefinitions as Waiting
 * @psalm-import-type NextFleetInbox from \OCA\NextFleet\ResponseDefinitions as Inbox
 */
class InboxService {
	/** A screenful and then some; `count` says how many more wait. */
	private const LIMIT = 100;

	public function __construct(
		private PreferencesService $preferences,
		private DocumentMapper $documents,
		private VehicleAccess $access,
		private IRootFolder $root,
	) {
	}

	/**
	 * @return Inbox
	 * @throws \OCP\DB\Exception
	 */
	public function list(string $userId): array {
		$folder = $this->preferences->inbox($userId);
		if ($folder === null) {
			return ['folder' => null, 'files' => [], 'count' => 0];
		}

		$attached = array_flip($this->documents->findAttachedFileIds($userId, array_keys($this->access->reachable($userId))));
		$waiting = [];
		// Direct children only: auto-upload sorts into subfolders only when asked to, and a
		// recursive walk over a large folder is a slow request.
		foreach ($folder->getDirectoryListing() as $node) {
			$mime = $node->getMimetype();
			if (!$node instanceof File
				|| !(str_starts_with($mime, 'image/') || $mime === 'application/pdf')
				|| isset($attached[$node->getId()])
				// A share or a storage mounted in here is not their own, which attaching refuses.
				|| !OwnFiles::owns($userId, $node)) {
				continue;
			}

			$waiting[] = [
				'file_id' => $node->getId(),
				'name' => $node->getName(),
				'mime' => $mime,
				'mtime' => $node->getMTime(),
				'size' => (int)$node->getSize(),
			];
		}
		usort($waiting, static fn (array $a, array $b): int => [$b['mtime'], $b['file_id']] <=> [$a['mtime'], $a['file_id']]);

		return [
			'folder' => [
				'file_id' => $folder->getId(),
				// A label, as a paper's name is: the id is what the preference holds.
				'path' => (string)$this->root->getUserFolder($userId)->getRelativePath($folder->getPath()),
			],
			'files' => array_slice($waiting, 0, self::LIMIT),
			'count' => count($waiting),
		];
	}
}
