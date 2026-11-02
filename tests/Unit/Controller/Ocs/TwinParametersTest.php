<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller\Ocs;

use OCA\NextFleet\Controller\Ocs;
use OCA\NextFleet\Service\EnergyService;
use OCA\NextFleet\Service\ExpenseService;
use OCA\NextFleet\Service\MaintenanceService;
use OCA\NextFleet\Service\TripService;
use OCA\NextFleet\Service\VehicleService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The OCS twin hands the whole request to its service, so a field it does not name still works.
 * But openapi.json is read off its parameters, and a field missing there is one no v1 client is
 * promised (docs/api.md#the-document).
 */
class TwinParametersTest extends TestCase {
	/** @return array<string, array{class-string, class-string}> */
	public static function writes(): array {
		return [
			'vehicle' => [VehicleService::class, Ocs\VehicleController::class],
			'fill-up' => [EnergyService::class, Ocs\EnergyController::class],
			'expense' => [ExpenseService::class, Ocs\ExpenseController::class],
			'record' => [MaintenanceService::class, Ocs\MaintenanceController::class],
			'trip' => [TripService::class, Ocs\TripController::class],
		];
	}

	/**
	 * @param class-string $service
	 * @param class-string $controller
	 */
	#[DataProvider('writes')]
	public function testEveryFieldAWriteTakesIsAParameterOfTheTwin(string $service, string $controller): void {
		/** @var array<string, mixed> $writable */
		$writable = (new \ReflectionClass($service))->getConstant('WRITABLE');
		foreach (['create', 'update'] as $method) {
			$parameters = array_map(
				static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
				(new \ReflectionMethod($controller, $method))->getParameters(),
			);
			foreach (array_keys($writable) as $field) {
				$this->assertContains($field, $parameters, $controller . '::' . $method);
			}
		}
	}
}
