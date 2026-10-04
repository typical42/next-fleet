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
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

/**
 * "Own" is the owner and the place: a group folder or an admin's external storage names the
 * user who asks as its owner, and neither is their own Files (docs/security.md#authorization).
 */
class OwnFilesTest extends TestCase {
	private const ANNA = 'anna';

	/** How often a user's Files were mounted. */
	private int $mounted = 0;
	/** @var array<string, mixed> */
	private array $cached = [];
	/** @var list<int> */
	private array $ttls = [];

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

	/** Hers and in her home, but not one she may read: attaching it would hand others what she cannot open. */
	public function testAFileOfHersSheCannotReadIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->ownFiles($this->file(self::ANNA, true, readable: false))->find(self::ANNA, 42);
	}

	/** Her own file, also shared back to her through a group: the server may list either view first. */
	public function testAFileSeenThroughTwoMountsIsHersIfOneIsHerHome(): void {
		$home = $this->file(self::ANNA, true);

		$this->assertSame($home, $this->ownFiles($this->file('ben', true), $home)->find(self::ANNA, 42));
		$this->assertSame($home, $this->ownFiles($home, $this->file('ben', true))->find(self::ANNA, 42));
	}

	public function testOwnsAsksTheSameOfAnyNode(): void {
		$this->assertTrue(OwnFiles::owns(self::ANNA, $this->file(self::ANNA, true)));
		$this->assertFalse(OwnFiles::owns(self::ANNA, $this->file(self::ANNA, false)));
		$this->assertFalse(OwnFiles::owns(self::ANNA, $this->file('ben', true)));
	}

	/**
	 * A list of papers names each file. Mounting the attacher's Files per row is what made a long
	 * list slow, so what a list shows is asked once and kept five minutes, for this request and
	 * the next.
	 */
	public function testAListAsksFilesOnceAndThenTheCache(): void {
		$file = $this->file(self::ANNA, true);
		$first = $this->ownFiles($file);

		$this->assertSame(['name' => 'Rechnung.pdf', 'mime' => 'application/pdf'], $first->shown(self::ANNA, 42));
		$this->assertSame(['name' => 'Rechnung.pdf', 'mime' => 'application/pdf'], $first->shown(self::ANNA, 42));
		$this->assertSame(1, $this->mounted);
		$this->assertSame([300], array_values(array_unique($this->ttls)));

		$next = $this->ownFiles($file);
		$this->assertSame(['name' => 'Rechnung.pdf', 'mime' => 'application/pdf'], $next->shown(self::ANNA, 42));
		$this->assertSame(1, $this->mounted);
	}

	public function testAListShowsNothingOfAFileNotHersAndRemembersThatToo(): void {
		$this->assertNull($this->ownFiles($this->file('ben', true))->shown(self::ANNA, 42));
		$this->assertNull($this->ownFiles($this->file('ben', true))->shown(self::ANNA, 42));
		$this->assertSame(1, $this->mounted);
	}

	/**
	 * A file back from the trash bin and attached again was just found live: the list that
	 * attaching answers with names it, whatever it remembered.
	 */
	public function testAFileFoundLiveIsListedAsFound(): void {
		$this->ownFiles($this->file('ben', true))->shown(self::ANNA, 42);
		$files = $this->ownFiles($this->file(self::ANNA, true));

		$files->find(self::ANNA, 42);

		$this->assertSame(['name' => 'Rechnung.pdf', 'mime' => 'application/pdf'], $files->shown(self::ANNA, 42));
	}

	/** The download hands the file out, so it never trusts what a list remembered. */
	public function testFindAsksFilesEveryTime(): void {
		$files = $this->ownFiles($this->file(self::ANNA, true));
		$files->shown(self::ANNA, 42);

		$files->find(self::ANNA, 42);
		$files->find(self::ANNA, 42);

		$this->assertSame(3, $this->mounted);
	}

	private function file(string $owner, bool $home, bool $readable = true): File {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($owner);
		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(static fn (string $class): bool => $home && $class === IHomeStorage::class);
		$file = $this->createMock(File::class);
		$file->method('isReadable')->willReturn($readable);
		$file->method('getOwner')->willReturn($user);
		$file->method('getStorage')->willReturn($storage);
		$file->method('getName')->willReturn('Rechnung.pdf');
		$file->method('getMimeType')->willReturn('application/pdf');

		return $file;
	}

	/** One request's OwnFiles; every one of a test shares the cache, as requests share the server's. */
	private function ownFiles(File ...$views): OwnFiles {
		$folder = $this->createMock(Folder::class);
		$folder->method('getById')->with(42)->willReturn($views);
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->with(self::ANNA)->willReturnCallback(function () use ($folder): Folder {
			$this->mounted++;

			return $folder;
		});
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => $this->cached[$key] ?? null);
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value, int $ttl): bool {
			$this->cached[$key] = $value;
			$this->ttls[] = $ttl;

			return true;
		});
		$caches = $this->createMock(ICacheFactory::class);
		$caches->method('createDistributed')->willReturn($cache);

		return new OwnFiles($root, $caches);
	}
}
