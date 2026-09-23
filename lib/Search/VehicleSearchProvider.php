<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Search;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Service\VehicleService;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;
use OCP\Search\SearchResultEntry;

/**
 * Finds a vehicle, and nothing else (docs/architecture.md, "Unified search").
 */
class VehicleSearchProvider implements IProvider {
	public function __construct(
		private VehicleService $vehicles,
		private IL10N $l,
		private IURLGenerator $urls,
	) {
	}

	public function getId(): string {
		return Application::APP_ID;
	}

	public function getName(): string {
		return $this->l->t('Vehicles');
	}

	public function getOrder(string $route, array $routeParameters): ?int {
		return str_starts_with($route, Application::APP_ID . '.') ? -1 : 50;
	}

	public function search(IUser $user, ISearchQuery $query): SearchResult {
		$term = mb_strtolower(trim($query->getTerm()));
		$plate = self::bare($term);
		// Filtered here rather than in SQL: the fleet one person reaches is small, and a plate
		// matched without its separators is not a LIKE every database agrees on.
		$found = array_values(array_filter(
			$this->vehicles->list($user->getUID()),
			// A disposed vehicle has left the navigation, and its link would open the overview.
			static fn (Vehicle $vehicle): bool => $vehicle->getLifecycle() !== Vehicle::DISPOSED && self::matches($vehicle, $term, $plate),
		));

		$offset = (int)$query->getCursor();
		$page = array_map($this->entry(...), array_slice($found, $offset, $query->getLimit()));

		return SearchResult::paginated($this->getName(), $page, $offset + count($page));
	}

	private static function matches(Vehicle $vehicle, string $term, string $plate): bool {
		return ($plate !== '' && str_contains(self::bare((string)$vehicle->getPlate()), $plate))
			|| ($term !== '' && str_contains(mb_strtolower(self::madeOf($vehicle)), $term));
	}

	/** A plate's letters and digits: where the hyphen and the spaces go is nobody's memory. */
	private static function bare(string $plate): string {
		return mb_strtolower((string)preg_replace('/[^\p{L}\p{N}]/u', '', $plate));
	}

	private static function madeOf(Vehicle $vehicle): string {
		return trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));
	}

	/** Named as the screen names it (src/utils/format.js `nameOf`, `subtitleOf`). */
	private function entry(Vehicle $vehicle): SearchResultEntry {
		$plate = $vehicle->getPlate() ?? '';
		$made = self::madeOf($vehicle);

		// The image goes in as a thumbnail: NC 31's search reads `icon` only as a CSS class.
		return new SearchResultEntry(
			$this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app.svg')),
			$plate !== '' ? $plate : $made,
			$plate !== '' ? $made : '',
			$this->urls->linkToRouteAbsolute('nextfleet.page.index', ['vehicle' => $vehicle->getUuid()]),
		);
	}
}
