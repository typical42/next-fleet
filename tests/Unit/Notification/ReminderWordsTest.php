<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Notification;

use OCA\NextFleet\Jurisdiction\De\InspectionScheme;
use OCA\NextFleet\Jurisdiction\Generic\ServiceTemplates;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;
use OCA\NextFleet\Notification\ReminderWords;
use OCA\NextFleet\Service\ReminderEngine;
use OCA\NextFleet\Service\VehicleService;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** A reminder's words in a notice and a mail; its title is src/utils/reminders.js reminderTitle() on the server. */
class ReminderWordsTest extends TestCase {
	/** @return array<string, array{string}> */
	public static function templates(): array {
		$scheme = new InspectionScheme();
		$offered = [
			...(new ServiceTemplates())->all(),
			...array_map(static fn (string $type): ReminderTemplate => $scheme->template($type), VehicleService::VEHICLE_TYPES),
		];
		$keys = array_unique(array_map(static fn (ReminderTemplate $template): string => $template->key, $offered));

		return array_combine($keys, array_map(static fn (string $key): array => [$key], $keys));
	}

	/** Each template the app offers reads in the reader's language, never as its key. */
	#[DataProvider('templates')]
	public function testEveryOfferedTemplateHasWordsOfItsOwn(string $key): void {
		$title = ReminderWords::title($this->l10n(), ['template_key' => $key, 'title' => null]);

		$this->assertStringStartsWith('translated:', $title);
	}

	public function testATypedTitleIsTheUsersAndStaysAsTyped(): void {
		$this->assertSame('Wallbox prüfen', ReminderWords::title($this->l10n(), ['template_key' => 'oil_change', 'title' => 'Wallbox prüfen']));
	}

	/** A template a later version brought, read by this one after a downgrade. */
	public function testATemplateWithoutWordsReadsAsItsKey(): void {
		$this->assertSame('timing_belt', ReminderWords::title($this->l10n(), ['template_key' => 'timing_belt', 'title' => null]));
	}

	/** @return array<string, array{string, string}> */
	public static function units(): array {
		return [
			'kilometres' => ['km', 'Oil change is due at 15000 km'],
			'engine hours' => ['h', 'Oil change is due at 15000 h'],
		];
	}

	/** A tractor's lead names its hours, as its screens do. */
	#[DataProvider('units')]
	public function testTheLeadByCounterNamesTheVehiclesUnit(string $unit, string $expected): void {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$p = ['template_key' => 'oil_change', 'title' => 'Oil change', 'due_date' => null, 'due_odo' => 15000];

		$this->assertSame($expected, ReminderWords::line($l, ReminderEngine::ODO, $p, $unit));
	}

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text): string => 'translated:' . $text);

		return $l;
	}
}
