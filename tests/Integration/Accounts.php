<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\IConfig;
use OCP\IUserManager;

/**
 * Accounts made once per class: a first login copies the skeleton into the home, which is slow,
 * and an account made again under the same uid is where an orphaned home folder fails every
 * later run on "files already exist for this user".
 */
trait Accounts {
	/**
	 * Each account gone, and any home folder that outlived its account in a crashed run.
	 *
	 * @param list<string> $uids
	 */
	private static function deleteAccounts(array $uids): void {
		$users = \OCP\Server::get(IUserManager::class);
		$data = \OCP\Server::get(IConfig::class)->getSystemValueString('datadirectory', (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/data');
		foreach ($uids as $uid) {
			$users->get($uid)?->delete();
			$home = $data . '/' . $uid;
			if (is_dir($home)) {
				self::removeTree($home);
			}
		}
	}

	private static function removeTree(string $dir): void {
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::CHILD_FIRST,
		);
		foreach ($entries as $entry) {
			/** @var \SplFileInfo $entry */
			$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
		}
		rmdir($dir);
	}
}
