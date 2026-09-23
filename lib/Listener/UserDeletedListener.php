<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Listener;

use OCA\NextFleet\Service\ErasureService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * After the account is gone rather than before: a deletion a backend refuses must leave the rows
 * as they were.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private ErasureService $erasure,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof UserDeletedEvent) {
			return;
		}

		$this->erasure->erase($event->getUser()->getUID());
	}
}
