<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Listener;

use OCA\NextFleet\Service\GrantService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Group\Events\UserAddedEvent;

/**
 * At once, not queued as a removal is: it writes no row, and a client that syncs meanwhile would
 * keep a partner list it got only part of (GrantService::joined()).
 *
 * @template-implements IEventListener<UserAddedEvent>
 */
class GroupMemberAddedListener implements IEventListener {
	public function __construct(
		private GrantService $grants,
	) {
	}

	/** @throws \OCP\DB\Exception */
	public function handle(Event $event): void {
		if (!$event instanceof UserAddedEvent) {
			return;
		}

		$this->grants->joined($event->getGroup()->getGID());
	}
}
