<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Db;

use OCA\NextFleet\Db\CostCentreMapper;
use OCA\NextFleet\Db\PartnerMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * A lookup by name, code or trip text against a database whose column compares case- or
 * accent-blind: SQL hands back every spelling it counts as one, lowest id first, and the lookup
 * keeps only the byte-identical one, as the step-13 backfill groups names.
 */
class NameLookupTest extends TestCase {
	/** What the query finds, in its order. */
	private array $rows = [];

	private function db(): IDBConnection {
		$expr = $this->createMock(IExpressionBuilder::class);
		foreach (['eq', 'in', 'isNull'] as $method) {
			$expr->method($method)->willReturn('');
		}
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'selectAlias', 'from', 'innerJoin', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$first = 0;
		$max = null;
		$qb->method('setFirstResult')->willReturnCallback(static function (int $offset) use ($qb, &$first) {
			$first = $offset;
			return $qb;
		});
		$qb->method('setMaxResults')->willReturnCallback(static function (?int $limit) use ($qb, &$max) {
			$max = $limit;
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$first, &$max) {
			$rows = array_slice($this->rows, $first, $max);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(static function () use (&$rows) {
				return array_shift($rows) ?? false;
			});

			return $result;
		});
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return $db;
	}

	private function partners(): PartnerMapper {
		return new PartnerMapper($this->db(), $this->createMock(ITimeFactory::class), $this->createMock(ISecureRandom::class));
	}

	private function centres(): CostCentreMapper {
		return new CostCentreMapper($this->db(), $this->createMock(ITimeFactory::class), $this->createMock(ISecureRandom::class));
	}

	private static function partner(int $id, string $name): array {
		return ['id' => $id, 'user_id' => 'alice', 'name' => $name];
	}

	private static function centre(int $id, string $name, ?string $code): array {
		return ['id' => $id, 'user_id' => 'alice', 'name' => $name, 'code' => $code];
	}

	public function testAPartnerByNameSkipsACaseVariant(): void {
		$this->rows = [self::partner(1, 'ACME GmbH'), self::partner(2, 'acme GmbH')];

		$this->assertSame(2, $this->partners()->findByName('alice', 'acme GmbH')?->getId());
	}

	public function testAPartnerByNameFindsNoneWhenOnlyAnAccentVariantMatches(): void {
		$this->rows = [self::partner(1, 'Muller')];

		$this->assertNull($this->partners()->findByName('alice', 'Müller'));
	}

	public function testAPartnerByTripTextSkipsATripOfACaseVariant(): void {
		$this->rows = [
			self::partner(1, 'ACME GmbH') + ['trip_partner' => 'ACME GmbH'],
			self::partner(2, 'Acme') + ['trip_partner' => 'acme GmbH'],
		];

		$this->assertSame(2, $this->partners()->findLinkedByText('alice', ['acme GmbH'])?->getId());
	}

	/** A renamed record keeps many trips of its old spelling's variants; the lookup reads past them. */
	public function testAPartnerByTripTextReadsPastAPageOfVariants(): void {
		$this->rows = array_fill(0, 250, self::partner(1, 'ACME GmbH') + ['trip_partner' => 'ACME GmbH']);
		$this->rows[] = self::partner(2, 'Acme') + ['trip_partner' => 'acme GmbH'];

		$this->assertSame(2, $this->partners()->findLinkedByText('alice', ['acme GmbH'])?->getId());
	}

	/** The owner's uid matches byte for byte too: another account may differ from it in case alone. */
	public function testEveryLookupSkipsTheListOfAnOwnerWhoseUidDiffersInCase(): void {
		$this->rows = [['user_id' => 'Alice'] + self::partner(1, 'Acme')];
		$this->assertNull($this->partners()->findByName('alice', 'Acme'));
		$this->rows[0]['trip_partner'] = 'Acme';
		$this->assertNull($this->partners()->findLinkedByText('alice', ['Acme']));

		$this->rows = [['user_id' => 'Alice'] + self::centre(1, 'Vertrieb', 'KS-1')];
		$this->assertNull($this->centres()->findBy('alice', 'name', 'Vertrieb'));
		$this->assertNull($this->centres()->findBy('alice', 'code', 'KS-1'));
	}

	public function testAPartnerByTripTextFindsNoneWhenOnlyVariantsMatch(): void {
		$this->rows = [self::partner(1, 'ACME GmbH') + ['trip_partner' => 'ACME GmbH']];

		$this->assertNull($this->partners()->findLinkedByText('alice', ['acme GmbH']));
	}

	public function testACostCentreByNameSkipsACaseVariant(): void {
		$this->rows = [self::centre(1, 'Vertrieb', null), self::centre(2, 'vertrieb', null)];

		$this->assertSame(2, $this->centres()->findBy('alice', 'name', 'vertrieb')?->getId());
	}

	public function testACostCentreByCodeSkipsACaseVariant(): void {
		$this->rows = [self::centre(1, 'Vertrieb', 'ks-1'), self::centre(2, 'Service', 'KS-1')];

		$this->assertSame(2, $this->centres()->findBy('alice', 'code', 'KS-1')?->getId());
	}

	public function testACostCentreByCodeFindsNoneWhenOnlyAVariantMatches(): void {
		$this->rows = [self::centre(1, 'Vertrieb', 'ks-1')];

		$this->assertNull($this->centres()->findBy('alice', 'code', 'KS-1'));
	}
}
