<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Service;

use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;

/**
 * Which record of the owner's list a trip's partner text links
 * ([ADR 0013](docs/adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)). The request carries
 * the text, never a uuid, so API v1 writes a trip as before.
 */
class PartnerLinks {
	public function __construct(
		private PartnerMapper $partners,
		private VehicleMapper $vehicles,
	) {
	}

	/**
	 * The record a text names: the one of that name; else the one another live trip with this text
	 * links, so a spelling renamed or merged away still finds it; else a new one. A driver logs a
	 * trip as before, and the owner writes the new record, as the list is theirs.
	 *
	 * Inside the caller's transaction, under the vehicle's hold. A new record is written under the
	 * owner's hold too (VehicleMapper::holdOwner()), after a second look: two drivers who type one
	 * new name at once, on two of the owner's vehicles, make one record.
	 *
	 * @throws \OCP\DB\Exception
	 */
	public function of(Vehicle $vehicle, ?string $text): ?Partner {
		$name = Partner::nameOf($text);
		if ($name === null) {
			return null;
		}
		// Not the gate's snapshot, which a transfer may have overtaken (VehicleMapper::ownerOf()).
		$owner = $this->vehicles->ownerOf((int)$vehicle->getId());
		$found = $this->find($owner, (string)$text, $name);
		if ($found !== null) {
			return $found;
		}
		$this->vehicles->holdOwner($owner);
		$found = $this->find($owner, (string)$text, $name);
		if ($found !== null) {
			return $found;
		}

		$record = new Partner();
		$record->setUserId($owner);
		$record->setName($name);
		$record->setCreatedBy($owner);

		return $this->partners->insert($record);
	}

	/**
	 * The names the sheet completes a partner from (docs/ui.md): the owner's whole list, as a firm
	 * visits the same partner in each car. The ones these trips link come first, in the trips'
	 * order, then the rest by name.
	 *
	 * @param list<Trip> $trips the vehicle's latest first
	 * @return list<string>
	 * @throws \OCP\DB\Exception
	 */
	public function offer(Vehicle $vehicle, array $trips): array {
		$names = $this->partners->findNamesOf($vehicle->getUserId());
		$first = [];
		foreach ($trips as $trip) {
			$id = $trip->getPartnerId();
			if ($id !== null && isset($names[$id])) {
				$first[$id] = $names[$id];
			}
		}

		// Unique: no index keeps a name once per list, only the owner's hold.
		return array_values(array_unique($first + $names));
	}

	/**
	 * Both forms of the text: a trip stores it as typed, and an older one may differ from the
	 * name in its Unicode form alone.
	 *
	 * @throws \OCP\DB\Exception
	 */
	private function find(string $owner, string $text, string $name): ?Partner {
		return $this->partners->findByName($owner, $name)
			?? $this->partners->findLinkedByText($owner, array_values(array_unique([$text, $name])));
	}
}
