<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Vehicle;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Service\VehicleAccess;
use OCA\NextFleet\Service\VehicleService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * The rules a vehicle is written under: who owns it, who reaches it, what a request may and may
 * not decide, and which columns are never left to the database.
 */
class VehicleServiceTest extends TestCase {
	private const UUID = '0195e2f1-0000-4000-8000-000000000001';
	private const OWNER = 'alice';
	private const DRIVER = 'carol';
	private const STRANGER = 'bob';

	private VehicleMapper&MockObject $mapper;
	private IConfig&MockObject $config;
	private VehicleAccess&MockObject $access;

	protected function setUp(): void {
		$this->mapper = $this->createMock(VehicleMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnArgument(3);

		// The rules of who reaches what are VehicleAccess's own (VehicleAccessTest); here it
		// stands for the answer: the owner may everything, the driver may look.
		$this->access = $this->createMock(VehicleAccess::class);
		$this->access->method('may')->willReturnCallback(
			static fn (string $userId, string $operation): bool => match ($userId) {
				self::OWNER => true,
				self::DRIVER => $operation === VehicleAccess::VIEW,
				default => false,
			},
		);
		$this->access->method('reachableVehicleIds')->willReturn([]);
	}

	private function service(): VehicleService {
		return new VehicleService($this->mapper, $this->access, $this->config, $this->jurisdictions());
	}

	/**
	 * The real registration list, not a double: what this service has to get right is which
	 * profile a vehicle is written under, and a stubbed profile would prove it against nothing.
	 */
	private function jurisdictions(): Jurisdictions {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			/** @param class-string $id */
			static fn (string $id): object => new $id(),
		);

		return new Jurisdictions($container);
	}

	/** A vehicle as a read hands it over: clean, with its own identity and dating. */
	private function stored(): Vehicle {
		return Vehicle::fromRow([
			'id' => 7,
			'uuid' => self::UUID,
			'user_id' => 'alice',
			'plate' => 'B-XY 123',
			'vehicle_type' => 'car',
			'odo_unit' => 'km',
			'jurisdiction' => 'uk',
			'lifecycle' => 'active',
			'created_at' => 1749000000,
			'updated_at' => 1750000000,
			'created_by' => 'alice',
		]);
	}

	/**
	 * The owner and the author are the session's, and a request that names someone else does
	 * not get to say so.
	 */
	public function testCreateTakesTheOwnerFromTheSessionAndNotFromTheRequest(): void {
		$vehicle = $this->service()->create('alice', [
			'plate' => 'B-XY 123',
			'user_id' => 'mallory',
			'created_by' => 'mallory',
		]);

		$this->assertSame('alice', $vehicle->getUserId());
		$this->assertSame('alice', $vehicle->getCreatedBy());
		$this->assertSame('B-XY 123', $vehicle->getPlate());
	}

	/**
	 * A freelancer's vehicles are all in one country, so the jurisdiction is not a field on the
	 * create form - it comes from the user's own setting (docs/ui.md).
	 */
	public function testCreateTakesTheJurisdictionFromThePersonalSetting(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')
			->with('alice', 'nextfleet', 'jurisdiction', 'de')
			->willReturn('uk');

		$vehicle = $this->service()->create('alice', []);

		$this->assertSame('uk', $vehicle->getJurisdiction());
	}

	/**
	 * Germany is the first jurisdiction (plan.md), so it is what a user who never chose one
	 * gets.
	 */
	public function testCreateFallsBackToTheFirstJurisdiction(): void {
		$vehicle = $this->service()->create('alice', []);

		$this->assertSame('de', $vehicle->getJurisdiction());
	}

	/**
	 * A stored setting is not a request and never passed through a validator, so a value the
	 * column cannot hold would otherwise turn every single create into a 500.
	 */
	public function testCreateFallsBackWhenTheStoredSettingIsUnusable(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn('Bundesrepublik Deutschland');

		$this->assertSame('de', $this->service()->create('alice', [])->getJurisdiction());
	}

	/**
	 * The create sheet asks for four fields (docs/ui.md), so the rest of what a row needs is
	 * decided here: a car, in service. The unit and the currency are the profile's
	 * (testCreateTakesUnitsAndCurrencyFromTheProfile).
	 */
	public function testCreateFillsInWhatTheSheetDoesNotAsk(): void {
		$vehicle = $this->service()->create('alice', []);

		$this->assertSame('car', $vehicle->getVehicleType());
		$this->assertSame('active', $vehicle->getLifecycle());
	}

	/**
	 * What a country decides is asked of its profile, not held as a literal here
	 * (docs/contributing.md): under Germany a new vehicle counts kilometres and is priced in
	 * euros.
	 */
	public function testCreateTakesUnitsAndCurrencyFromTheProfile(): void {
		$vehicle = $this->service()->create('alice', []);

		$this->assertSame('de', $vehicle->getJurisdiction());
		$this->assertSame('km', $vehicle->getOdoUnit());
		$this->assertSame('EUR', $vehicle->getCurrency());
	}

	/**
	 * "I don't know" is an answer and has to break nothing: the generic profile names no
	 * currency, so the vehicle carries none and states its own later.
	 */
	public function testCreateUnderAProfileWithNoCurrencyWritesNone(): void {
		$vehicle = $this->service()->create('alice', ['jurisdiction' => 'generic']);

		$this->assertSame('generic', $vehicle->getJurisdiction());
		$this->assertSame('km', $vehicle->getOdoUnit());
		$this->assertNull($vehicle->getCurrency());
	}

	/**
	 * The profile is the vehicle's own, not the author's: a request that states a country is
	 * answered by that country, whatever the personal setting says.
	 */
	public function testAStatedJurisdictionDecidesTheDefaultsOverTheSetting(): void {
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturn('generic');

		$vehicle = $this->service()->create('alice', ['jurisdiction' => 'de']);

		$this->assertSame('de', $vehicle->getJurisdiction());
		$this->assertSame('EUR', $vehicle->getCurrency());
	}

	/**
	 * A vehicle whose country left a later release still gets written: the key stays on the row,
	 * and the generic profile answers for it (lib/Jurisdiction/Jurisdictions.php).
	 */
	public function testAnUnknownJurisdictionIsAnsweredByTheGenericProfile(): void {
		$vehicle = $this->service()->create('alice', ['jurisdiction' => 'uk']);

		$this->assertSame('uk', $vehicle->getJurisdiction());
		$this->assertNull($vehicle->getCurrency());
	}

	/** A default is only a default: what the request states wins over what the country says. */
	public function testARequestOutranksTheProfilesDefaults(): void {
		$vehicle = $this->service()->create('alice', [
			'jurisdiction' => 'de',
			'currency' => 'CHF',
			'odo_unit' => 'h',
		]);

		$this->assertSame('CHF', $vehicle->getCurrency());
		$this->assertSame('h', $vehicle->getOdoUnit());
	}

	/**
	 * A column a property default merely agrees with is not dirty, so QBMapper leaves it out of
	 * the INSERT and the NOT NULL constraint refuses the row. Every one of them is written.
	 *
	 * @dataProvider notNullColumns
	 */
	public function testCreateWritesEveryColumnTheDatabaseWillNotDefault(string $property): void {
		$vehicle = $this->service()->create('alice', []);

		$this->assertArrayHasKey($property, $vehicle->getUpdatedFields());
	}

	/**
	 * The vocabulary is CONTEXT.md's and the data model's, and a column that classifies is
	 * worth nothing once anything may be written into it.
	 *
	 * @param array<string, mixed> $fields
	 * @dataProvider foreignWords
	 */
	public function testCreateRefusesAWordTheDomainDoesNotHave(array $fields): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->create('alice', $fields);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function foreignWords(): iterable {
		yield 'vehicle_type' => [['vehicle_type' => 'spaceship']];
		yield 'engine' => [['engine' => 'steam']];
		yield 'odo_unit' => [['odo_unit' => 'miles']];
		yield 'lifecycle' => [['lifecycle' => 'archived']];
		yield 'energy_types' => [['energy_types' => ['petrol', 'coal']]];
		yield 'energy_types is a set' => [['energy_types' => 'petrol']];
	}

	/**
	 * A tractor counts hours and a plug-in hybrid takes two energies - the awkward rows the
	 * data model was written for go in unchanged.
	 */
	public function testCreateKeepsTheDomainsOwnWords(): void {
		$vehicle = $this->service()->create('alice', [
			'vehicle_type' => 'tractor',
			'engine' => 'hybrid',
			'energy_types' => ['petrol', 'electric'],
			'odo_unit' => 'h',
			'lifecycle' => 'laid_up',
		]);

		$this->assertSame('tractor', $vehicle->getVehicleType());
		$this->assertSame('hybrid', $vehicle->getEngine());
		$this->assertSame(['petrol', 'electric'], $vehicle->getEnergyTypes());
		$this->assertSame('h', $vehicle->getOdoUnit());
		$this->assertSame('laid_up', $vehicle->getLifecycle());
	}

	/**
	 * An update is a write to the row the client read: the fields it sends, and the token it
	 * read, in one statement (docs/architecture.md#concurrency).
	 */
	public function testUpdateWritesTheGivenFieldsUnderTheClientsToken(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());
		$this->mapper->expects($this->once())
			->method('updateChecked')
			->with($this->anything(), 1750000000)
			->willReturnArgument(0);

		$vehicle = $this->service()->update(self::OWNER, self::UUID, 1750000000, ['plate' => 'B-ZZ 9']);

		$this->assertSame('B-ZZ 9', $vehicle->getPlate());
	}

	/** Denied before the write, not after it: the row is not touched at all. */
	public function testAStrangerDoesNotWriteAVehicle(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());
		$this->mapper->expects($this->never())->method('updateChecked');

		$this->expectException(AccessDeniedException::class);
		$this->service()->update(self::STRANGER, self::UUID, 1750000000, ['plate' => 'B-ZZ 9']);
	}

	/**
	 * The jurisdiction lives on the vehicle, not on whoever is editing it, and a co-driver's
	 * own default has no business overwriting it. What a request leaves out stays as it was.
	 */
	public function testUpdateKeepsWhatTheRequestDoesNotMention(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());
		$this->mapper->method('updateChecked')->willReturnArgument(0);

		$vehicle = $this->service()->update(self::OWNER, self::UUID, 1750000000, ['plate' => 'B-ZZ 9']);

		$this->assertSame('uk', $vehicle->getJurisdiction());
		$this->assertSame('alice', $vehicle->getUserId());
		$this->assertSame(self::UUID, $vehicle->getUuid());
	}

	/**
	 * Deleting is stamping the row, and it takes the same token as any other write - the tab
	 * that deletes a vehicle someone else has just edited loses too.
	 */
	public function testDeleteStampsTheRowUnderTheClientsToken(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());
		$this->mapper->expects($this->once())
			->method('softDelete')
			->with($this->anything(), 1750000000)
			->willReturnArgument(0);

		$this->service()->delete(self::OWNER, self::UUID, 1750000000);
	}

	/**
	 * A check that stopped at "this user reaches the vehicle" would hand the co-driver the delete
	 * button. Which operation is asked for is half the question.
	 */
	public function testAGrantToLookIsNotAGrantToDelete(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());
		$this->mapper->expects($this->never())->method('softDelete');

		$this->assertSame(self::UUID, $this->service()->find(self::DRIVER, self::UUID)->getUuid());

		$this->expectException(AccessDeniedException::class);
		$this->service()->delete(self::DRIVER, self::UUID, 1750000000);
	}

	/**
	 * The overview is what a user owns and what they were granted. The grants resolve to ids
	 * once, and the vehicles come back in one query - not one per row
	 * (docs/architecture.md#nextcloud-integration).
	 */
	public function testTheListWidensToWhatWasGranted(): void {
		$this->access = $this->createMock(VehicleAccess::class);
		$this->access->method('reachableVehicleIds')->with(self::DRIVER)->willReturn([7, 9]);

		$this->mapper->expects($this->once())
			->method('findAllVisible')
			->with(self::DRIVER, [7, 9])
			->willReturn([$this->stored()]);

		$this->assertCount(1, $this->service()->list(self::DRIVER));
	}

	/** Owning a vehicle takes no grant, so a user with none still sees their own fleet. */
	public function testTheListOfSomeoneWithNoGrantIsWhatTheyOwn(): void {
		$this->mapper->expects($this->once())
			->method('findAllVisible')
			->with(self::OWNER, [])
			->willReturn([$this->stored()]);

		$this->assertCount(1, $this->service()->list(self::OWNER));
	}

	/** A vehicle is found by its identity, never by the row number. */
	public function testFindGoesByTheIdentity(): void {
		$this->mapper->expects($this->once())
			->method('findByUuid')
			->with(self::UUID)
			->willReturn($this->stored());

		$this->assertSame(self::UUID, $this->service()->find(self::OWNER, self::UUID)->getUuid());
	}

	/**
	 * The realistic bug in an id-addressed API (docs/security.md): a uuid is all it takes to name
	 * a row, so every route that takes one asks first.
	 */
	public function testAStrangerDoesNotReachAVehicle(): void {
		$this->mapper->method('findByUuid')->willReturn($this->stored());

		$this->expectException(AccessDeniedException::class);
		$this->service()->find(self::STRANGER, self::UUID);
	}

	/**
	 * A form posts strings, and the columns are integers and one calendar day. Sixty litres is
	 * millilitres, and the day is the day whatever the browser's timezone was.
	 */
	public function testCreateReadsWhatAFormPosts(): void {
		$vehicle = $this->service()->create('alice', [
			'tank_ml' => '60000',
			'purchase_price' => '1850000',
			'first_reg' => '2019-03-07',
		]);

		$this->assertSame(60000, $vehicle->getTankMl());
		$this->assertSame(1850000, $vehicle->getPurchasePrice());
		$this->assertSame('2019-03-07', $vehicle->getFirstReg()?->format('Y-m-d'));
	}

	/**
	 * The database would refuse each of these too, but as a 500 that names no field. Refusing
	 * them here is what makes the answer a 400 the sheet can point at.
	 *
	 * @param array<string, mixed> $fields
	 * @dataProvider misshapenValues
	 */
	public function testCreateRefusesAValueTheColumnCannotHold(array $fields): void {
		$this->expectException(\InvalidArgumentException::class);

		$this->service()->create('alice', $fields);
	}

	/**
	 * @return iterable<string, array{array<string, mixed>}>
	 */
	public static function misshapenValues(): iterable {
		yield 'a tank that is not a number' => [['tank_ml' => 'full']];
		yield 'a tank below empty' => [['tank_ml' => '-1']];
		yield 'a fractional tank' => [['tank_ml' => '4.5']];
		yield 'a German date' => [['first_reg' => '07.03.2019']];
		yield 'a day that is not one' => [['first_reg' => '2019-02-31']];
		yield 'a plate longer than the column' => [['plate' => str_repeat('B', 33)]];
	}

	/**
	 * @return iterable<string, array{string}>
	 */
	public static function notNullColumns(): iterable {
		yield 'user_id' => ['userId'];
		yield 'created_by' => ['createdBy'];
		yield 'vehicle_type' => ['vehicleType'];
		yield 'odo_unit' => ['odoUnit'];
		yield 'jurisdiction' => ['jurisdiction'];
		yield 'lifecycle' => ['lifecycle'];
	}
}
