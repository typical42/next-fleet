<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Listener;

use OCA\NextFleet\BackgroundJob\ForgetMemberJob;
use OCA\NextFleet\Db\AccessMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserRemovedEvent;

/**
 * After the member is out, so the access check already answers without the group.
 *
 * @template-implements IEventListener<UserRemovedEvent>
 */
class GroupMemberRemovedListener implements IEventListener {
	public function __construct(
		private AccessMapper $grants,
		private IJobList $jobs,
		private ITimeFactory $time,
	) {
	}

	/** @throws \OCP\DB\Exception */
	public function handle(Event $event): void {
		if (!$event instanceof UserRemovedEvent) {
			return;
		}
		$groupId = $event->getGroup()->getGID();
		// A group no car is granted to took nothing from them; a directory sync removes many.
		if ($this->grants->findByGroup($groupId) === []) {
			return;
		}

		$this->jobs->add(ForgetMemberJob::class, [
			'group' => $groupId,
			'user' => $event->getUser()->getUID(),
			'at' => $this->time->getTime(),
		]);
	}
}
