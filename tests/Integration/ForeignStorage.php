<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCP\Files\Config\IMountProvider;
use OCP\Files\Storage\IStorageFactory;
use OCP\IUser;

/**
 * A storage that is not the user's home, mounted at `Foreign` in their Files: what a group folder or
 * an admin's external storage looks like to the app. Neither app runs on the test servers, and the
 * external storage's local backend is this same mount.
 *
 * Register it before anything touches the user's Files: the server sets a user's mounts up once per
 * process.
 */
class ForeignStorage implements IMountProvider {
	public const FOLDER = 'Foreign';

	public function __construct(
		private string $uid,
		private string $dir,
	) {
	}

	/**
	 * OCP has no storage to build: the private classes are what the server mounts itself.
	 *
	 * @psalm-suppress UndefinedClass, InvalidReturnType, InvalidReturnStatement
	 */
	public function getMountsForUser(IUser $user, IStorageFactory $loader): array {
		if ($user->getUID() !== $this->uid) {
			return [];
		}

		return [new \OC\Files\Mount\MountPoint(
			\OC\Files\Storage\Local::class,
			'/' . $this->uid . '/files/' . self::FOLDER,
			['datadir' => $this->dir],
			$loader,
			null,
			null,
			self::class,
		)];
	}
}
