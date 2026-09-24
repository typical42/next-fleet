<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Dashboard;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Notification\ReminderWords;
use OCA\NextFleet\Service\ReminderService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * What is due across the fleet (docs/architecture.md, "Dashboard"). An adapter over
 * `ReminderService::due()`: the dashboard loads on every login, so it reads nothing else.
 */
class DueWidget implements IAPIWidgetV2, IIconWidget {
	public function __construct(
		private ReminderService $reminders,
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getTitle(): string {
		// TRANSLATORS: A dashboard panel's title; keep it short, the panel header truncates it.
		return $this->l->t('Vehicle reminders');
	}

	public function getOrder(): int {
		return 50;
	}

	/** The header shows the image from getIconUrl() whenever there is one. */
	public function getIconClass(): string {
		return '';
	}

	public function getIconUrl(): string {
		return $this->image('app.svg');
	}

	public function getUrl(): ?string {
		return $this->urls->linkToRouteAbsolute('nextfleet.page.index');
	}

	public function load(): void {
	}

	/**
	 * `since` pages by newness, which an urgency order does not have; the most urgent always lead.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		$items = array_map(
			fn (array $one): WidgetItem => $this->item($one['vehicle'], $one['reminder']),
			array_slice($this->reminders->due($userId), 0, $limit),
		);

		return new WidgetItems($items, $this->l->t('Nothing due'));
	}

	/**
	 * Named as the notification names it; the state is a word beside the colour, never the colour
	 * alone (docs/ui.md).
	 *
	 * @param array<string, mixed> $reminder in its wire form, as `due()` answers it
	 */
	private function item(Vehicle $vehicle, array $reminder): WidgetItem {
		/** @var array{template_key: ?string, title: ?string, state: string} $reminder */
		return new WidgetItem(
			$this->l->t('%1$s: %2$s', [$this->nameOf($vehicle),ReminderWords::title($this->l, $reminder)]),
			$this->stateWord($reminder['state']),
			$this->urls->linkToRouteAbsolute('nextfleet.page.index', ['vehicle' => $vehicle->getUuid()]),
			$this->image('light-' . self::light($reminder['state']) . '.svg'),
		);
	}

	/** src/utils/format.js `nameOf`. */
	private function nameOf(Vehicle $vehicle): string {
		$made = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return ($vehicle->getPlate() ?? '') !== '' ? (string)$vehicle->getPlate() : ($made !== '' ? $made : $this->l->t('Unnamed vehicle'));
	}

	/** src/utils/reminders.js `light`. */
	private static function light(string $state): string {
		return match ($state) {
			Reminder::DUE, Reminder::OVERDUE => 'red',
			Reminder::WARNED => 'amber',
			default => 'green',
		};
	}

	/** src/utils/reminders.js `stateWord`, for the states still open. */
	private function stateWord(string $state): string {
		return match ($state) {
			Reminder::OVERDUE => $this->l->t('Overdue'),
			Reminder::DUE => $this->l->t('Due'),
			Reminder::WARNED => $this->l->t('Coming up'),
			Reminder::SNOOZED => $this->l->t('Snoozed'),
			default => $this->l->t('Planned'),
		};
	}

	private function image(string $file): string {
		return $this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, $file));
	}
}
