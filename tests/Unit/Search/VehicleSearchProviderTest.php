<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Search;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Search\VehicleSearchProvider;
use OCA\NextFleet\Service\VehicleService;
use OCA\NextFleet\Tests\Fixture\SharedCases;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A result is titled and sub-lined as the screen names a vehicle: src/utils/format.spec.js reads the
 * same cases. tests/Integration/SearchTest.php covers finding over real rows.
 */
class VehicleSearchProviderTest extends TestCase {
	/**
	 * A case's `search_line` is its second label without the body type, whose words live in the
	 * frontend. A vehicle with nothing filled in matches no term, so its case is left out.
	 *
	 * @return array<string, array{array<string, string>, string, string}>
	 */
	public static function vehicles(): array {
		$cases = [];
		/** @var array{case: string, vehicle: array<string, string>, name: string, second_label: string, search_line?: string} $case */
		foreach (SharedCases::read('vehicle-labels') as $case) {
			if ($case['vehicle'] !== []) {
				$cases[$case['case']] = [$case['vehicle'], $case['name'], $case['search_line'] ?? $case['second_label']];
			}
		}

		return $cases;
	}

	/** @param array<string, string> $row */
	#[DataProvider('vehicles')]
	public function testTheLineBelowIsTheSecondLabel(array $row, string $title, string $below): void {
		$vehicle = Vehicle::fromRow($row + ['uuid' => 'v-1', 'lifecycle' => 'active']);

		$entry = $this->search([$vehicle], $title)[0];

		$this->assertSame($title, $entry['title']);
		$this->assertSame($below, $entry['subline']);
	}

	/**
	 * @param list<Vehicle> $vehicles
	 * @return list<array<string, mixed>>
	 */
	private function search(array $vehicles, string $term): array {
		$service = $this->createMock(VehicleService::class);
		$service->method('list')->willReturn($vehicles);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($term);
		$query->method('getLimit')->willReturn(5);

		$result = (new VehicleSearchProvider($service, $l, $this->createMock(IURLGenerator::class)))->search($user, $query);

		return array_map(static fn ($entry): array => $entry->jsonSerialize(), $result->jsonSerialize()['entries']);
	}
}
