<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Partner;
use OCA\NextFleet\Db\PartnerMapper;
use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\PartnerLinks;
use PHPUnit\Framework\TestCase;

/**
 * Two drivers who type one new name at once, on two vehicles of one owner, make one record. The
 * vehicle's hold does not order them, so a name the list lacks is written only under the owner's
 * hold, and looked up again under it: the first writer's record is then there to find.
 */
class PartnerLinksTest extends TestCase {
	private const OWNER = 'alice';

	/** @var list<string> */
	private array $calls = [];
	/** Who owns the vehicle by the time its hold is taken. */
	private string $owner = self::OWNER;
	private ?Partner $written = null;

	/**
	 * @param list<?Partner> $byName what each lookup by name answers, in turn
	 * @return list<string>
	 */
	private function link(string $text, array $byName): array {
		$this->calls = [];
		$partners = $this->createMock(PartnerMapper::class);
		$partners->method('findByName')->willReturnCallback(function () use (&$byName): ?Partner {
			$this->calls[] = 'by name';

			return array_shift($byName);
		});
		$partners->method('findLinkedByText')->willReturnCallback(function (): ?Partner {
			$this->calls[] = 'by text';

			return null;
		});
		$partners->method('insert')->willReturnCallback(function (Partner $partner): Partner {
			$this->calls[] = 'insert';
			$this->written = $partner;

			return $partner;
		});
		$vehicles = $this->createMock(VehicleMapper::class);
		$vehicles->method('ownerOf')->willReturnCallback(fn (): string => $this->owner);
		$vehicles->method('holdOwner')->willReturnCallback(function (string $owner): void {
			$this->calls[] = 'hold ' . $owner;
		});
		$vehicle = new Vehicle();
		$vehicle->setId(7);
		$vehicle->setUserId(self::OWNER);

		(new PartnerLinks($partners, $vehicles))->of($vehicle, $text);

		return $this->calls;
	}

	public function testANewNameIsWrittenUnderTheOwnersHoldAfterASecondLook(): void {
		$this->assertSame(
			['by name', 'by text', 'hold ' . self::OWNER, 'by name', 'by text', 'insert'],
			$this->link('Müller', [null, null]),
		);
	}

	public function testANameWrittenMeanwhileIsLinkedAndNotWrittenAgain(): void {
		$this->assertSame(
			['by name', 'by text', 'hold ' . self::OWNER, 'by name'],
			$this->link('Müller', [null, new Partner()]),
		);
	}

	/**
	 * The owner is read under the vehicle's hold, not off the snapshot the gate took before it: a
	 * transfer that committed in between makes the trip the new owner's, and its partner too.
	 */
	public function testANameGoesIntoTheListOfWhoOwnsTheVehicleUnderItsHold(): void {
		$this->owner = 'bob';

		$this->assertSame(['by name', 'by text', 'hold bob', 'by name', 'by text', 'insert'], $this->link('Müller', [null, null]));
		$this->assertSame(['bob', 'bob'], [$this->written?->getUserId(), $this->written?->getCreatedBy()]);
	}

	/** The common case holds nothing beyond the vehicle, so the owner's other vehicles never wait. */
	public function testAKnownNameTakesNoHold(): void {
		$this->assertSame(['by name'], $this->link('Müller', [new Partner()]));
	}
}
