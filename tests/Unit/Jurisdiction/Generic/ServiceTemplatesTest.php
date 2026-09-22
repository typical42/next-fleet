<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Jurisdiction\Generic;

use OCA\NextFleet\Db\Reminder;
use OCA\NextFleet\Jurisdiction\Generic\ServiceTemplates;
use OCA\NextFleet\Jurisdiction\ReminderTemplate;
use PHPUnit\Framework\TestCase;

/** The intervals a reminder sheet offers before anyone knows the manufacturer's. */
class ServiceTemplatesTest extends TestCase {
	public function testItOffersOilBrakeFluidAndTyres(): void {
		$this->assertEquals([
			new ReminderTemplate('oil_change', Reminder::EITHER, 12, 15000, 1000),
			new ReminderTemplate('brake_fluid', Reminder::DATE, 24, null, null),
			new ReminderTemplate('tyre_swap', Reminder::DATE, 6, null, null),
		], (new ServiceTemplates())->all());
	}
}
