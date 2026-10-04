<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\AppInfo\Application;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * A file the user picked in Files and the app may act on: one of their own, in their own Files.
 * There is no upload path (docs/security.md), so this is the file's whole access check, for a
 * paper and an import alike.
 */
class OwnFiles {
	/** How long a list trusts what it was told of a file: a rename shows within five minutes. */
	private const SHOWN_TTL = 300;

	private ICache $cache;

	public function __construct(
		private IRootFolder $root,
		ICacheFactory $caches,
	) {
		$this->cache = $caches->createDistributed(Application::APP_ID . '-files');
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
		$node = $this->mine($userId, $fileId);
		if (!$node instanceof File || !$node->isReadable()) {
			throw new DoesNotExistException('No such file in ' . $userId . '\'s Files');
		}
		// Newer than whatever a list remembered: the list attaching answers with names it.
		$this->remember($userId, $fileId, $node);

		return $node;
	}

	/**
	 * The node `$fileId` is in the user's own Files, or null. Every view is asked: their own file
	 * shared back to them through a group shows twice, and either may come first.
	 *
	 * @throws \OCP\Files\NotPermittedException if the user has no Files
	 */
	public function mine(string $userId, int $fileId): ?Node {
		foreach ($this->root->getUserFolder($userId)->getById($fileId) as $node) {
			// Readable is not enough: a share is readable, and not the caller's to pass on.
			if (self::owns($userId, $node)) {
				return $node;
			}
		}

		return null;
	}

	/**
	 * What a list shows of a file `$userId` attached - its name and MIME type while it is still
	 * their own file (mine()), else null. Mounting their Files per row made a long list slow, so
	 * the answer is kept five minutes. Only for showing: whatever hands the file out asks find()
	 * live.
	 *
	 * @return ?array{name: string, mime: string}
	 * @throws \OCP\Files\NotPermittedException if the user has no Files; nothing is kept then
	 */
	public function shown(string $userId, int $fileId): ?array {
		/** @var array{name: string, mime: string}|false|null $kept */
		$kept = $this->cache->get(self::key($userId, $fileId));

		return ($kept ?? $this->remember($userId, $fileId, $this->mine($userId, $fileId))) ?: null;
	}

	/**
	 * @return array{name: string, mime: string}|false what shown() answers, false for null
	 */
	private function remember(string $userId, int $fileId, ?Node $node): array|false {
		$kept = $node instanceof File ? ['name' => $node->getName(), 'mime' => $node->getMimeType()] : false;
		$this->cache->set(self::key($userId, $fileId), $kept, self::SHOWN_TTL);

		return $kept;
	}

	private static function key(string $userId, int $fileId): string {
		return $fileId . '/' . $userId;
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
