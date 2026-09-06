<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * `actionlint` says the workflow is valid YAML that GitHub will run. It cannot say the
 * matrix is the one docs/development.md#testing asks for - and that matrix is the whole
 * point of the file, so it is checked here.
 */
class CiWorkflowTest extends TestCase {
	private const FILE = __DIR__ . '/../../.github/workflows/ci.yml';

	/**
	 * The PHP range each Nextcloud major accepts, from its own `lib/versioncheck.php`.
	 * Refresh it when the supported range in appinfo/info.xml moves.
	 *
	 * @var array<string, list<string>>
	 */
	private const SUPPORTED_PHP = [
		'stable31' => ['8.1', '8.2', '8.3', '8.4'],
		'stable32' => ['8.1', '8.2', '8.3', '8.4'],
		'stable33' => ['8.2', '8.3', '8.4', '8.5'],
		'stable34' => ['8.2', '8.3', '8.4', '8.5'],
	];

	/** @var array<string, mixed> */
	private static array $workflow;

	public static function setUpBeforeClass(): void {
		self::$workflow = (array)Yaml::parseFile(self::FILE);
	}

	/** @return array<string, mixed> */
	private function triggers(): array {
		return (array)(self::$workflow['on'] ?? []);
	}

	/** @return array<string, mixed> */
	private function job(string $name): array {
		$jobs = (array)(self::$workflow['jobs'] ?? []);
		$this->assertArrayHasKey($name, $jobs, "the workflow declares no '$name' job");

		return (array)$jobs[$name];
	}

	/**
	 * @return list<array{nextcloud: string, php: string, db: string}>
	 */
	private function combinations(string $event): array {
		$env = (array)($this->job('plan')['env'] ?? []);
		$key = 'ON_' . strtoupper($event);
		$this->assertArrayHasKey($key, $env, "the plan job holds no combinations for '$event'");

		/** @var list<array{nextcloud: string, php: string, db: string}> $decoded */
		$decoded = json_decode((string)$env[$key], true, 512, JSON_THROW_ON_ERROR);

		return $decoded;
	}

	/**
	 * A compromised action owns the release (docs/security.md#supply-chain). A moving tag
	 * is the whole attack, so a version is a comment and the reference is a commit.
	 */
	public function testEveryActionIsPinnedToACommitSha(): void {
		$loose = array_filter(
			$this->everyUses(),
			static fn (string $uses): bool => preg_match('{^[\w.-]+/[\w.-]+@[0-9a-f]{40}$}', $uses) !== 1,
		);

		$this->assertSame([], array_values($loose), 'not pinned to a commit: ' . implode(', ', $loose));
	}

	/**
	 * Steps and jobs both take `uses` - the second form calls a whole reusable workflow, so
	 * an unpinned one is the larger hole of the two.
	 *
	 * @return list<string>
	 */
	private function everyUses(): array {
		$uses = [];
		foreach ((array)(self::$workflow['jobs'] ?? []) as $job) {
			foreach ([...(array)($job['steps'] ?? []), $job] as $caller) {
				if (isset($caller['uses'])) {
					$uses[] = (string)$caller['uses'];
				}
			}
		}

		$this->assertNotEmpty($uses, 'the workflow uses no action at all, so this proves nothing');

		return $uses;
	}

	/** Least-privilege `GITHUB_TOKEN`, per docs/security.md#supply-chain. */
	public function testTheTokenCanOnlyRead(): void {
		$this->assertSame(['contents' => 'read'], (array)(self::$workflow['permissions'] ?? []));
	}

	/** The three moments the matrix is cut for, and nothing else. */
	public function testTheWorkflowRunsOnPullRequestsOnMainAndWeekly(): void {
		$triggers = $this->triggers();

		$this->assertSame(['pull_request', 'push', 'schedule'], array_keys($triggers));
		$this->assertSame(['main'], (array)$triggers['push']['branches']);
		$this->assertNotEmpty($triggers['schedule'][0]['cron'] ?? null);
	}

	/**
	 * Two: the oldest combination is where breakage hides, so it belongs on every pull
	 * request rather than in a nightly nobody reads.
	 */
	public function testEveryPullRequestRunsTheOldestAndTheNewestCombination(): void {
		$this->assertSame(
			[
				['nextcloud' => 'stable31', 'php' => '8.1', 'db' => 'mariadb'],
				['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'mariadb'],
			],
			$this->combinations('pull_request'),
		);
	}

	/** "Merge to main: add PostgreSQL and SQLite, on NC 34." */
	public function testMergingToMainAddsPostgresqlAndSqliteOnNextcloud34(): void {
		$added = $this->added($this->combinations('pull_request'), $this->combinations('push'));

		$this->assertSame(
			[
				['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'pgsql'],
				['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'sqlite'],
			],
			$added,
		);
	}

	/**
	 * "Weekly: the fuller matrix, allowed to fail loudly without blocking anyone." Fuller
	 * means it drops nothing, and loudly means it stays red: `continue-on-error` concludes
	 * the job green, so a weekly break would be reported to nobody.
	 */
	public function testTheWeeklyRunIsFullerThanMainAndFailsLoudly(): void {
		$this->assertSame([], $this->added($this->combinations('schedule'), $this->combinations('push')));
		$this->assertGreaterThan(count($this->combinations('push')), count($this->combinations('schedule')));

		$this->assertArrayNotHasKey('continue-on-error', $this->job('server'));
	}

	/**
	 * The trim happens on the PHP and database axes, never on Nextcloud - so every major
	 * the app claims must appear somewhere in the week.
	 */
	public function testTheWeeklyRunCoversEverySupportedNextcloudMajor(): void {
		$covered = array_unique(array_column($this->combinations('schedule'), 'nextcloud'));
		sort($covered);

		$this->assertSame(array_keys(self::SUPPORTED_PHP), $covered);
	}

	/**
	 * A PHP a major refuses is a job that dies in `versioncheck.php` before it reaches a
	 * test, and the failure names neither the matrix nor the version.
	 */
	public function testEveryCombinationRunsAPhpItsNextcloudMajorSupports(): void {
		foreach (['pull_request', 'push', 'schedule'] as $event) {
			foreach ($this->combinations($event) as $combination) {
				['nextcloud' => $major, 'php' => $php] = $combination;

				$this->assertArrayHasKey($major, self::SUPPORTED_PHP, "$event: unknown major $major");
				$this->assertContains($php, self::SUPPORTED_PHP[$major], "$event: $major does not run PHP $php");
			}
		}
	}

	/**
	 * The server job is the only one with a database, so both suites belong to it: without
	 * the second the migration is never measured against a real schema, and postgres and
	 * sqlite are never exercised at all.
	 */
	public function testTheServerJobRunsBothTestSuites(): void {
		$script = '';
		foreach ((array)$this->job('server')['steps'] as $step) {
			$script .= (string)($step['run'] ?? '') . "\n";
		}

		// The suite, not a prefix of the other one: `composer run test:integration` alone
		// satisfies a bare `composer run test`.
		$this->assertMatchesRegularExpression('/^composer run test$/m', $script);
		$this->assertMatchesRegularExpression('/^composer run test:integration$/m', $script);
	}

	/**
	 * The E2E job is the only one that runs a browser, and it is the only one where the two
	 * things it depends on are invisible when they go missing: without a bundle the Vue root
	 * stays empty, and without `--wait` "the stack is up" means apache answered rather than
	 * Nextcloud installed (tests/Unit/ComposeStackTest.php).
	 */
	public function testTheEndToEndJobBuildsTheBundleAndWaitsForTheStack(): void {
		$script = [];
		foreach ((array)$this->job('e2e')['steps'] as $step) {
			$script[] = (string)($step['run'] ?? '');
		}
		$script = implode("\n", $script);

		$this->assertStringContainsString('--wait', $script, 'apache answers before Nextcloud is installed');

		$build = strpos($script, 'npm run build');
		$run = strpos($script, 'npm run test:e2e');
		$this->assertIsInt($build, 'the job never builds the bundle it asserts against');
		$this->assertIsInt($run, 'the job never runs the E2E');
		$this->assertLessThan($run, $build, 'the bundle is built after the run that needs it');
	}

	/**
	 * The demo fleet is a fixture, not decoration: tests/e2e/demo-fleet.spec.js asserts rows only
	 * `occ nextfleet:seed` writes, and a run that skips it fails on missing data while pointing at
	 * the app.
	 */
	public function testTheEndToEndJobSeedsTheDemoFleetBeforeItRuns(): void {
		$script = [];
		foreach ((array)$this->job('e2e')['steps'] as $step) {
			$script[] = (string)($step['run'] ?? '');
		}
		$script = implode("\n", $script);

		$seed = strpos($script, 'nextfleet:seed');
		$run = strpos($script, 'npm run test:e2e');
		$this->assertIsInt($seed, 'the job never seeds the fleet its specs read');
		$this->assertIsInt($run, 'the job never runs the E2E');
		$this->assertLessThan($run, $seed, 'the fleet is seeded after the run that reads it');
	}

	/**
	 * The lists are data the job reads through the environment; a renamed variable leaves
	 * the data in place and silently selects nothing.
	 */
	public function testThePlanJobReadsEveryListItHolds(): void {
		$script = '';
		foreach ((array)$this->job('plan')['steps'] as $step) {
			$script .= (string)($step['run'] ?? '');
		}

		foreach (array_keys((array)$this->job('plan')['env']) as $variable) {
			$this->assertStringContainsString((string)$variable, $script);
		}
	}

	/**
	 * @param list<array<string, string>> $before
	 * @param list<array<string, string>> $after
	 * @return list<array<string, string>>
	 */
	private function added(array $before, array $after): array {
		return array_values(array_filter($after, static fn (array $c): bool => !in_array($c, $before, true)));
	}
}
