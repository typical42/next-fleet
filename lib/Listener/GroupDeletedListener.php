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
use OCP\Group\Events\BeforeGroupDeletedEvent;
use OCP\Group\Events\GroupDeletedEvent;

/**
 * After the group is gone rather than before, for the reason UserDeletedListener gives. Before,
 * it only reads who is in it: nobody can once it is gone.
 *
 * @template-implements IEventListener<BeforeGroupDeletedEvent|GroupDeletedEvent>
 */
class GroupDeletedListener implements IEventListener {
	public function __construct(
		private GrantService $grants,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof BeforeGroupDeletedEvent) {
			$this->grants->noteGroup($event->getGroup()->getGID());
		} elseif ($event instanceof GroupDeletedEvent) {
			$this->grants->forgetGroup($event->getGroup()->getGID());
		}
	}
}
