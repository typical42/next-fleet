<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;

/**
 * A file the user picked in Files and the app may act on: one of their own, in their own Files.
 * There is no upload path (docs/security.md), so this is the file's whole access check, for a
 * paper and an import alike.
 */
class OwnFiles {
	public function __construct(
		private IRootFolder $root,
	) {
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function id(mixed $value): int {
		$fileId = filter_var($value, FILTER_VALIDATE_INT);
		if ($fileId === false) {
			throw new \InvalidArgumentException('file_id is the id of a file in Files');
		}

		return $fileId;
	}

	/**
	 * Not found, rather than forbidden: whether somebody else's file exists is not the caller's to
	 * learn.
	 *
	 * @throws DoesNotExistException
	 */
	public function find(string $userId, int $fileId): File {
		$node = $this->root->getUserFolder($userId)->getFirstNodeById($fileId);
		// Readable is not enough: a share is readable, and not the caller's to pass on.
		if (!$node instanceof File || !$node->isReadable() || !self::owns($userId, $node)) {
			throw new DoesNotExistException('No such file in ' . $userId . '\'s Files');
		}

		return $node;
	}

	/**
	 * The owner alone is not enough either: a group folder or an admin's external storage names
	 * whoever asks as its owner. Their home storage is what is theirs.
	 */
	public static function owns(string $userId, Node $node): bool {
		return $node->getOwner()?->getUID() === $userId
			&& $node->getStorage()->instanceOfStorage(IHomeStorage::class);
	}

	/**
	 * The file to read, closed by the caller. Gone between find() and here reads as never there.
	 *
	 * @return resource
	 * @throws DoesNotExistException
	 * @throws \OCP\Lock\LockedException while somebody writes it
	 */
	public function open(File $file) {
		try {
			$stream = $file->fopen('rb');
		} catch (NotFoundException|NotPermittedException) {
			$stream = false;
		}
		if ($stream === false) {
			throw new DoesNotExistException('No such file: ' . $file->getId());
		}

		return $stream;
	}
}
