<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\Command\PendingCommand;
use OCA\NextFleet\Service\Pending;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:pending`: what Service\Pending keeps marked, and finishing it now.
 *
 * It writes to the instance it runs against (docs/development.md#testing), and `--finish` finishes
 * every mark there, as PendingJob would.
 */
class PendingCommandTest extends TestCase {
	/** No account and no group, so finishing either touches no row. */
	private const GHOST = 'nextfleet-test-pending-ghost';
	private const GONE = 'nextfleet-test-pending-gone';

	private Pending $pending;
	private CommandTester $command;

	protected function setUp(): void {
		$this->pending = \OCP\Server::get(Pending::class);
		$this->command = new CommandTester(\OCP\Server::get(PendingCommand::class));
		$this->forget();
	}

	protected function tearDown(): void {
		$this->forget();
	}

	private function forget(): void {
		$this->pending->end(Pending::ERASURE, self::GHOST);
		$this->pending->end(Pending::GROUP, self::GONE);
	}

	public function testItListsEachMarkWithWhenItWasMarked(): void {
		$before = gmdate('Y-m-d\TH:i:s\Z');
		$this->pending->begin(Pending::ERASURE, self::GHOST);
		$this->pending->begin(Pending::GROUP, self::GONE);
		$after = gmdate('Y-m-d\TH:i:s\Z');

		$this->assertSame(0, $this->command->execute(['--output' => 'json']), $this->command->getDisplay());

		$listed = $this->ours();
		$this->assertSame([[Pending::ERASURE, self::GHOST], [Pending::GROUP, self::GONE]], array_map(static fn (array $row): array => [$row['kind'], $row['id']], $listed));
		$this->assertSame(['kind', 'id', 'marked_at'], array_keys($listed[0]));
		foreach ($listed as $row) {
			$this->assertGreaterThanOrEqual($before, $row['marked_at']);
			$this->assertLessThanOrEqual($after, $row['marked_at']);
		}
	}

	/** An erasure of a uid with no account and a group's revokes with no grant left both finish at once. */
	public function testFinishFinishesThemAndListsWhatIsLeft(): void {
		$this->pending->begin(Pending::ERASURE, self::GHOST);
		$this->pending->begin(Pending::GROUP, self::GONE);

		// A mark another suite left that fails again would exit 1; stderr names it.
		$this->assertSame(0, $this->command->execute(['--finish' => true, '--output' => 'json'], ['capture_stderr_separately' => true]), $this->command->getErrorOutput());

		$this->assertSame([], $this->ours());
		$this->assertNotContains(self::GHOST, array_column($this->pending->of(Pending::ERASURE), 'id'));
		$this->assertNotContains(self::GONE, array_column($this->pending->of(Pending::GROUP), 'id'));
	}

	public function testPlainIsATable(): void {
		$this->pending->begin(Pending::GROUP, self::GONE);

		$this->assertSame(0, $this->command->execute([]));

		$display = $this->command->getDisplay();
		$this->assertMatchesRegularExpression('/\|\s*kind\s*\|\s*id\s*\|\s*marked_at\s*\|/', $display);
		$this->assertMatchesRegularExpression('/\|\s*group\s*\|\s*' . self::GONE . '\s*\|/', $display);
	}

	public function testAnUnknownOutputIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['--output' => 'xml']));
	}

	/**
	 * The rows this suite marked: the instance may hold others.
	 *
	 * @return list<array<string, string>>
	 */
	private function ours(): array {
		/** @var list<array<string, string>> $listed */
		$listed = json_decode($this->command->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

		return array_values(array_filter($listed, static fn (array $row): bool => in_array($row['id'], [self::GHOST, self::GONE], true)));
	}
}
