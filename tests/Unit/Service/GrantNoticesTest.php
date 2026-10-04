<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Access;
use OCA\NextFleet\Db\AccessMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\GrantNotices;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A group's members are told by a queued job Nextcloud has already taken off its list, so one
 * member nobody can tell must not leave the rest untold.
 */
class GrantNoticesTest extends TestCase {
	public function testAMemberThatCannotBeToldLeavesTheOthersTold(): void {
		$told = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}
		$notification->method('setUser')->willReturnCallback(function (string $uid) use ($notification, &$told): INotification {
			$told[] = $uid;

			return $notification;
		});
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(static function () use (&$told): void {
			if (end($told) === 'bea') {
				throw new \RuntimeException('the push server broke');
			}
		});
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn(array_map($this->user(...), ['ann', 'bea', 'cem']));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');
		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('@1750000000'));
		$notices = new GrantNotices($this->createMock(AccessMapper::class), $groups, $notifications, $clock, $this->createMock(IJobList::class), $logger);

		$notices->tellMembers(Vehicle::fromRow(['uuid' => 'v-1', 'user_id' => 'owner']), Access::fromRow(['uuid' => 'g-1', 'grantee' => 'crew', 'grantee_type' => Access::GROUP]));

		$this->assertSame(['ann', 'bea', 'cem'], $told);
	}

	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);

		return $user;
	}
}
