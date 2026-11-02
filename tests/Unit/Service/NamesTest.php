<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Service\Names;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

/** A user or a group as the instance spells it, and a group as its members. */
class NamesTest extends TestCase {
	public function testAUserIsSpelledAsTheInstanceSpellsIt(): void {
		$this->assertSame('anna', $this->names()->spelled(Access::USER, 'ANNA'));
	}

	public function testAGroupIsSpelledAsTheInstanceSpellsIt(): void {
		$this->assertSame('crew', $this->names()->spelled(Access::GROUP, 'Crew'));
	}

	/** A user's name is no group's, nor the other way round. */
	public function testANameIsLookedUpAsItsTypeOnly(): void {
		$this->assertNull($this->names()->spelled(Access::GROUP, 'anna'));
		$this->assertNull($this->names()->spelled(Access::USER, 'crew'));
	}

	public function testAGroupIsItsMembers(): void {
		$this->assertSame(['anna', 'ben'], $this->names()->members('crew'));
	}

	/** A group deleted since names nobody. */
	public function testAGroupGoneHasNoMembers(): void {
		$this->assertSame([], $this->names()->members('gone'));
	}

	private function names(): Names {
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(fn (string $uid): ?IUser => strtolower($uid) === 'anna' ? $this->user('anna') : null);
		$crew = $this->createMock(IGroup::class);
		$crew->method('getGID')->willReturn('crew');
		$crew->method('getUsers')->willReturn([$this->user('anna'), $this->user('ben')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturnCallback(static fn (string $gid): ?IGroup => strtolower($gid) === 'crew' ? $crew : null);

		return new Names($users, $groups);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}
