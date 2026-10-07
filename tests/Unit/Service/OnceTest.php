<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Db\Expense;
use OCA\NextFleet\Db\ExpenseMapper;
use OCA\NextFleet\Exception\AlreadyCreatedException;
use OCA\NextFleet\Service\Once;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use PHPUnit\Framework\TestCase;

/**
 * A create sent twice under one client uuid writes once: the second is answered with the first's row.
 */
class OnceTest extends TestCase {
	private const UUID = '0195e2f1-1111-4000-8000-000000000001';
	private const VEHICLE = 7;

	/** @var list<Expense> what the table holds */
	private array $rows = [];

	private function create(array $fields): Once {
		$mapper = $this->createMock(ExpenseMapper::class);
		$mapper->method('findAnyByUuid')->willReturnCallback(function (string $uuid): Expense {
			foreach ($this->rows as $row) {
				if ($row->getUuid() === $uuid) {
					return $row;
				}
			}
			throw new DoesNotExistException('none');
		});

		return Once::of(
			$fields,
			$mapper,
			static fn (Expense $row): bool => $row->getVehicleId() === self::VEHICLE,
			static fn (Expense $row): array => ['answered' => $row->getUuid()],
		);
	}

	private function row(int $vehicleId): Expense {
		$row = new Expense();
		$row->setUuid(self::UUID);
		$row->setVehicleId($vehicleId);

		return $row;
	}

	private function duplicateKey(): Exception {
		$duplicate = $this->createMock(Exception::class);
		$duplicate->method('getReason')->willReturn(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION);

		return $duplicate;
	}

	public function testWithoutAUuidTheCreateRuns(): void {
		$once = $this->create([]);

		$this->assertNull($once->uuid);
		$this->assertSame('written', $once->run(static fn (): string => 'written'));
	}

	public function testAFreshUuidIsStampedOnTheRow(): void {
		$row = new Expense();

		$this->create(['client_uuid' => self::UUID])->stamp($row);

		$this->assertSame(self::UUID, $row->getUuid());
	}

	/** @return array<string, array{mixed}> */
	public static function notUuids(): array {
		return [
			'no dashes' => ['0195e2f1111140008000000000000001'],
			'a word' => ['retry'],
			'a number' => [42],
			'empty' => [''],
		];
	}

	/** @dataProvider notUuids */
	public function testAClientUuidIsAUuid(mixed $uuid): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->create(['client_uuid' => $uuid]);
	}

	/** As some platforms write them; the server's own are lowercase. */
	public function testAnUppercaseUuidIsStampedInLowercase(): void {
		$row = new Expense();

		$this->create(['client_uuid' => strtoupper(self::UUID)])->stamp($row);

		$this->assertSame(self::UUID, $row->getUuid());
	}

	public function testARowAlreadyWrittenIsAnsweredInsteadOfACreate(): void {
		$this->rows = [$this->row(self::VEHICLE)];

		try {
			$this->create(['client_uuid' => self::UUID])->run(function (): never {
				$this->fail('created twice');
			});
		} catch (AlreadyCreatedException $e) {
			$this->assertSame(['answered' => self::UUID], $e->answer);

			return;
		}
		$this->fail('no answer');
	}

	/** A uuid somebody else's row holds is no retry of this create, and tells nothing about that row. */
	public function testAUuidAnotherVehiclesRowHoldsIsRefused(): void {
		$this->rows = [$this->row(self::VEHICLE + 1)];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('client_uuid is taken');
		$this->create(['client_uuid' => self::UUID])->run(function (): never {
			$this->fail('created over it');
		});
	}

	/** Its twin committed between the check and the insert: the unique index refuses, and the twin's row answers. */
	public function testATwinThatWonTheRaceIsAnswered(): void {
		$once = $this->create(['client_uuid' => self::UUID]);

		try {
			$once->run(function (): never {
				$this->rows = [$this->row(self::VEHICLE)];
				throw $this->duplicateKey();
			});
		} catch (AlreadyCreatedException $e) {
			$this->assertSame(['answered' => self::UUID], $e->answer);

			return;
		}
		$this->fail('no answer');
	}

	/** Another unique index refused: not this class's to answer. */
	public function testAnotherDuplicateKeyIsThrownOn(): void {
		$duplicate = $this->duplicateKey();

		try {
			$this->create(['client_uuid' => self::UUID])->run(static function () use ($duplicate): never {
				throw $duplicate;
			});
		} catch (Exception $e) {
			$this->assertSame($duplicate, $e);

			return;
		}
		$this->fail('swallowed');
	}

	/** @return array<string, array{array<string, string>, int}> */
	public static function notARetry(): array {
		return [
			'a duplicate key without a uuid' => [[], Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION],
			'a deadlock' => [['client_uuid' => self::UUID], Exception::REASON_DEADLOCK],
		];
	}

	/**
	 * Not a twin's doing: thrown on, even with a row under the uuid by then.
	 *
	 * @dataProvider notARetry
	 */
	public function testAnErrorNoTwinCausedIsThrownOn(array $fields, int $reason): void {
		$error = $this->createMock(Exception::class);
		$error->method('getReason')->willReturn($reason);

		try {
			$this->create($fields)->run(function () use ($error): never {
				$this->rows = [$this->row(self::VEHICLE)];
				throw $error;
			});
		} catch (Exception $e) {
			$this->assertSame($error, $e);

			return;
		}
		$this->fail('swallowed');
	}
}
