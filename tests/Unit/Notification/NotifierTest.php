<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Notification;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Notification\Notifier;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\UnknownNotificationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every point the engine receipts reads as a sentence; the integration suite
 * (tests/Integration/ReminderJobTest.php) shows it arriving in a real language.
 */
class NotifierTest extends TestCase {
	/** @return array<string, array{string, ?string, ?int, string}> */
	public static function points(): array {
		return [
			'a month before' => ['month_before', '2031-05-31', null, 'B-XY 123: Oil change is due on 31 May 2031'],
			'the month it is due' => ['month_start', '2031-05-31', null, 'B-XY 123: Oil change is due on 31 May 2031'],
			'on the day' => ['due_date', '2031-05-31', null, 'B-XY 123: Oil change is due today'],
			'the lead by km' => ['odo', null, 150000, 'B-XY 123: Oil change is due at 150000 km'],
			'the km reached' => ['odo_due', null, 150000, 'B-XY 123: Oil change is due'],
			'the day after' => ['overdue', '2031-05-31', null, 'B-XY 123: Oil change is overdue (due 31 May 2031)'],
		];
	}

	#[DataProvider('points')]
	public function testEachPointReadsAsASentence(string $point, ?string $dueDate, ?int $dueOdo, string $expected): void {
		$parsed = null;
		$notification = $this->notification($point, $dueDate, $dueOdo);
		$notification->method('setParsedSubject')->willReturnCallback(function (string $subject) use (&$parsed, $notification) {
			$parsed = $subject;

			return $notification;
		});

		$this->notifier()->prepare($notification, 'en');

		$this->assertSame($expected, $parsed);
	}

	public function testAPointThisAppDoesNotSendIsRefused(): void {
		$this->expectException(UnknownNotificationException::class);

		$this->notifier()->prepare($this->notification('someday', null, null), 'en');
	}

	private function notifier(): Notifier {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$l->method('l')->willReturnCallback(static fn (string $type, \DateTime $day): string => $day->format('j F Y'));
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($l);
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('findByUuid')->willReturn(new Vehicle());

		return new Notifier($factory, $this->createMock(IConfig::class), $this->createMock(IURLGenerator::class), $vehicles, $this->createMock(VehicleAccess::class));
	}

	/** @return INotification&\PHPUnit\Framework\MockObject\MockObject */
	private function notification(string $point, ?string $dueDate, ?int $dueOdo): INotification {
		$notification = $this->createMock(INotification::class);
		$notification->method('getApp')->willReturn('nextfleet');
		$notification->method('getUser')->willReturn('alice');
		$notification->method('getSubject')->willReturn($point);
		$notification->method('getSubjectParameters')->willReturn([
			'vehicle' => 'v-1', 'plate' => 'B-XY 123', 'template_key' => 'oil_change', 'title' => null,
			'due_date' => $dueDate, 'due_odo' => $dueOdo,
		]);
		$notification->method('setIcon')->willReturnSelf();

		return $notification;
	}
}
