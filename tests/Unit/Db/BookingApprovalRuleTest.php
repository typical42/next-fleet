<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\Vehicle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Whether a vehicle's bookings wait for an answer: its own choice, or its owner's for all. */
class BookingApprovalRuleTest extends TestCase {
	/** @return array<string, array{?string, bool, string, bool}> the stored column, the owner's default, the choice, the effect */
	public static function rules(): array {
		return [
			'as for all, default off' => [null, false, 'default', false],
			'as for all, default on' => [null, true, 'default', true],
			'always, default off' => ['1', false, 'always', true],
			'always, default on' => ['1', true, 'always', true],
			'never, default off' => ['0', false, 'never', false],
			'never, default on' => ['0', true, 'never', false],
		];
	}

	#[DataProvider('rules')]
	public function testTheChoiceOrTheOwnersDefaultDecides(?string $column, bool $ownerDefault, string $rule, bool $needs): void {
		$vehicle = Vehicle::fromRow(['user_id' => 'alice', 'booking_approval' => $column]);
		$vehicle->setOwnerApproval($ownerDefault);

		$this->assertSame($needs, $vehicle->needsApproval());
		$this->assertSame(['booking_approval' => $needs, 'booking_approval_rule' => $rule], array_intersect_key(
			$vehicle->jsonSerialize(),
			['booking_approval' => 0, 'booking_approval_rule' => 0],
		));
	}

	/** A new vehicle follows its owner. */
	public function testANewVehicleIsAsForAllTheOwnersVehicles(): void {
		$this->assertSame('default', (new Vehicle())->approvalRule());
		$this->assertFalse((new Vehicle())->needsApproval());
	}
}
