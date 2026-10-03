<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Service;

use OCA\NextFleet\Service\SyncCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a sync cursor carries from one call to the next (docs/api.md#sync): opaque to the client,
 * and refused whole when it is not one the route handed out.
 */
class SyncCursorTest extends TestCase {
	public function testAFirstCallHoldsNothingAndStandsBeforeEveryRow(): void {
		$cursor = SyncCursor::first('k3');

		$this->assertSame('k3', $cursor->epoch);
		$this->assertSame([], $cursor->held);
		$this->assertSame([], $cursor->listed);
		$this->assertFalse($cursor->inRun());
		$this->assertSame([-1, null], $cursor->after('trips'));
	}

	public function testAPageInARunComesBackAsItWasHandedOut(): void {
		$cursor = SyncCursor::first('k3')
			->finished(['v-1'], [7], 1000)
			->paged(['v-1', 'v-2'], [7, 9], 1500, [1490, 'trips', 42]);

		$back = SyncCursor::decode($cursor->encode());

		$this->assertEquals($cursor, $back);
		$this->assertTrue($back->inRun());
		$this->assertSame(1000, $back->since);
		$this->assertSame([7], $back->held);
		$this->assertSame(['v-1', 'v-2'], $back->listed);
		$this->assertSame([7, 9], $back->run);
		$this->assertSame(1500, $back->mark);
	}

	/** The end of a run: what it covered is held, through the mark it started at. */
	public function testAFinishedRunHoldsWhatItCoveredThroughItsMark(): void {
		$cursor = SyncCursor::first('k3')
			->paged(['v-1', 'v-2'], [7, 9], 1500, [1490, 'trips', 42])
			->finished(['v-1', 'v-2'], [7, 9], 1500);

		$this->assertFalse($cursor->inRun());
		$this->assertSame(1500, $cursor->since);
		$this->assertSame([7, 9], $cursor->held);
		$this->assertSame([-1, null], $cursor->after('trips'));
	}

	/** After an erasure nothing is held, run or not, but what was listed is still owed an answer. */
	public function testACursorFromAnOlderEpochStartsOverButKeepsWhatItListed(): void {
		$cursor = SyncCursor::first('k3')
			->finished(['v-1'], [7], 1000)
			->paged(['v-1', 'v-2'], [7, 9], 1500, [1490, 'trips', 42])
			->anew('k4');

		$this->assertSame('k4', $cursor->epoch);
		$this->assertSame([], $cursor->held);
		$this->assertSame(['v-1', 'v-2'], $cursor->listed);
		$this->assertFalse($cursor->inRun());
		$this->assertSame([-1, null], $cursor->after('trips'));
	}

	/** A url-safe string, since it travels in a query. */
	public function testItEncodesWithoutCharactersAQueryWouldMangle(): void {
		$encoded = SyncCursor::first('k3')->paged(['a+/=?&'], [1], 99, [98, 'grants', 1])->encode();

		$this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $encoded);
	}

	/**
	 * Paging is by (`updated_at`, table, `id`): a table before the page's last row's starts after
	 * its instant, the same table after its row, a table after it at its instant.
	 *
	 * @return array<string, array{string, array{int, ?int}}>
	 */
	public static function tables(): array {
		return [
			'an earlier table' => ['readings', [1490, null]],
			'the same table' => ['trips', [1490, 42]],
			'a later table' => ['grants', [1490, 0]],
		];
	}

	/** @param array{int, ?int} $after */
	#[DataProvider('tables')]
	public function testEachTableResumesAfterThePagesLastRowInItsOwnTerms(string $table, array $after): void {
		$cursor = SyncCursor::first('k3')->paged([], [], 1500, [1490, 'trips', 42]);

		$this->assertSame($after, $cursor->after($table));
	}

	/** @return array<string, array{string}> */
	public static function forged(): array {
		$encode = static fn (mixed $value): string => rtrim(strtr(base64_encode((string)json_encode($value)), '+/', '-_'), '=');
		$valid = ['e' => 'k1', 's' => 0, 'h' => [], 'l' => []];

		return [
			'not base64' => ['***'],
			'not json' => [rtrim(strtr(base64_encode('{nope'), '+/', '-_'), '=')],
			'a list' => [$encode([1, 2])],
			'no epoch' => [$encode(['s' => 0, 'h' => [], 'l' => []])],
			'a numbered epoch' => [$encode(['e' => 1] + $valid)],
			'a string since' => [$encode(['s' => '0'] + $valid)],
			'a held uuid' => [$encode(['h' => ['v-1']] + $valid)],
			'a listed id' => [$encode(['l' => [7]] + $valid)],
			'half a run' => [$encode($valid + ['m' => 1])],
			'an unknown table' => [$encode($valid + ['m' => 1, 'r' => [], 'p' => [1, 'fleet_vehicles', 1]])],
			'a short position' => [$encode($valid + ['m' => 1, 'r' => [], 'p' => [1, 'trips']])],
		];
	}

	#[DataProvider('forged')]
	public function testACursorTheRouteDidNotHandOutIsRefused(string $raw): void {
		$this->expectExceptionObject(new \InvalidArgumentException('cursor is not one this route hands out'));
		SyncCursor::decode($raw);
	}
}
