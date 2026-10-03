<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\OwnFiles;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\IRootFolder;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * "Own" is the owner and the place: a group folder or an admin's external storage names the
 * user who asks as its owner, and neither is their own Files (docs/security.md#authorization).
 */
class OwnFilesTest extends TestCase {
	private const ANNA = 'anna';

	public function testAFileAnnaOwnsInHerHomeIsHers(): void {
		$file = $this->file(self::ANNA, true);

		$this->assertSame($file, $this->ownFiles($file)->find(self::ANNA, 42));
	}

	public function testAFileThatNamesHerOwnerOutsideHerHomeIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->ownFiles($this->file(self::ANNA, false))->find(self::ANNA, 42);
	}

	public function testAShareInHerHomeIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->ownFiles($this->file('ben', true))->find(self::ANNA, 42);
	}

	public function testOwnsAsksTheSameOfAnyNode(): void {
		$this->assertTrue(OwnFiles::owns(self::ANNA, $this->file(self::ANNA, true)));
		$this->assertFalse(OwnFiles::owns(self::ANNA, $this->file(self::ANNA, false)));
		$this->assertFalse(OwnFiles::owns(self::ANNA, $this->file('ben', true)));
	}

	private function file(string $owner, bool $home): File {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => $home && $class === IHomeStorage::class);
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn(true);
		$file->method('getOwner')->willReturn($user);
		$file->method('getStorage')->willReturn($storage);

		return $file;
	}

	private function ownFiles(File $file): OwnFiles {
		$folder = $this->createMock(Folder::class);
		$folder->method('getFirstNodeById')->with(42)->willReturn($file);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with(self::ANNA)->willReturn($folder);

		return new OwnFiles($root);
	}
}
