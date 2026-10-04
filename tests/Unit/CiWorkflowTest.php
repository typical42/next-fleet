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
	 * The oldest combination is where breakage hides, so it belongs on every pull request rather
	 * than in a nightly nobody reads. SQLite too: it is the database a first try runs on, and it
	 * is the one that accepts the least.
	 */
	public function testEveryPullRequestRunsTheOldestAndTheNewestCombinationAndSqlite(): void {
		$this->assertSame(
			[
				['nextcloud' => 'stable31', 'php' => '8.1', 'db' => 'mariadb'],
				['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'mariadb'],
				['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'sqlite'],
			],
			$this->combinations('pull_request'),
		);
	}

	/** "Merge to main: add PostgreSQL, on NC 34." */
	public function testMergingToMainAddsPostgresqlOnNextcloud34(): void {
		$added = $this->added($this->combinations('pull_request'), $this->combinations('push'));

		$this->assertSame([['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'pgsql']], $added);
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
	 * The server job is the only one with a database, so every server suite belongs to it:
	 * without the integration suite the migration is never measured against a real schema, and
	 * postgres and sqlite are never exercised at all; without the API suite nothing signs in
	 * with an app password the way a client does.
	 */
	public function testTheServerJobRunsEveryTestSuite(): void {
		$script = $this->script('server');

		// The suite, not a prefix of the other one: `composer run test:integration` alone
		// satisfies a bare `composer run test`.
		$this->assertMatchesRegularExpression('/^composer run test$/m', $script);
		$this->assertMatchesRegularExpression('/^composer run test:integration$/m', $script);
		$this->assertMatchesRegularExpression('/^composer run test:api$/m', $script);
	}

	/**
	 * The API suite speaks HTTP, and a checkout of the server is not a web server. The one it is
	 * given must be up before the suite runs, or every case fails on a refused connection.
	 */
	public function testTheServerJobServesNextcloudBeforeTheApiSuite(): void {
		$script = $this->script('server');

		$serve = strpos($script, 'php -S localhost:8080');
		$this->assertIsInt($serve, 'the server job starts no web server');
		$this->assertLessThan(strpos($script, 'composer run test:api'), $serve);
	}

	/**
	 * The API suite sends two requests at once (ClientTest's race). PHP's server takes one at a
	 * time unless told to fork workers, and then the two would simply queue.
	 */
	public function testTheServerServesMoreThanOneRequestAtOnce(): void {
		$serving = array_values(array_filter(
			(array)$this->job('server')['steps'],
			static fn (mixed $step): bool => str_contains((string)(((array)$step)['run'] ?? ''), 'php -S'),
		));
		$this->assertCount(1, $serving, 'the server job starts no web server');

		$this->assertGreaterThanOrEqual(2, (int)(((array)($serving[0]['env'] ?? []))['PHP_CLI_SERVER_WORKERS'] ?? 1));
	}

	/** UserMigrationTest skips without user_migration, so somewhere it must not: weekly. */
	public function testTheWeeklyServerJobInstallsUserMigrationBeforeTheIntegrationSuite(): void {
		$installs = array_values(array_filter(
			(array)$this->job('server')['steps'],
			static fn (mixed $step): bool => str_contains((string)(((array)$step)['run'] ?? ''), 'php occ app:install user_migration'),
		));
		$this->assertCount(1, $installs, 'the server job never installs user_migration');
		$this->assertSame("github.event_name == 'schedule'", $installs[0]['if'] ?? null);

		$script = $this->script('server');
		$this->assertLessThan(strpos($script, 'composer run test:integration'), strpos($script, 'php occ app:install user_migration'));
	}

	/**
	 * openapi.json is the contract a client is built against (docs/api.md). Generated from the
	 * code, it drifts the moment somebody forgets to regenerate it, so CI regenerates it and fails
	 * on any difference from the committed one.
	 */
	public function testTheStaticJobFailsOnAStaleOpenApiDocument(): void {
		$script = $this->script('static');

		$generate = strpos($script, "composer run openapi\n");
		$this->assertIsInt($generate, 'the static job does not regenerate openapi.json');
		$compare = strpos($script, 'git diff --exit-code -- openapi.json');
		$this->assertIsInt($compare, 'the static job does not compare openapi.json');
		$this->assertLessThan($compare, $generate);
	}

	/**
	 * Oracle is supported in code and measured weekly, on the stack docs/development.md#oracle
	 * describes: only there does the oci8 image install, migrate, seed and run the integration
	 * suite. Weekly only - the image build fetches Oracle's client and takes minutes.
	 */
	public function testOracleRunsWeeklyThroughTheIntegrationSuite(): void {
		$this->assertSame("github.event_name == 'schedule'", $this->job('oracle')['if'] ?? null);
		$script = $this->script('oracle');

		$steps = [
			'docker compose -f .docker/oracle/compose.yml up -d --build --wait',
			'--database=oci',
			'php occ app:enable nextfleet',
			'php occ nextfleet:seed admin',
			'php vendor/bin/phpunit -c phpunit.integration.xml',
		];
		$at = -1;
		foreach ($steps as $step) {
			$found = strpos($script, $step);
			$this->assertIsInt($found, "the oracle job never runs '$step'");
			$this->assertGreaterThan($at, $found, "'$step' runs out of order");
			$at = $found;
		}
	}

	/**
	 * The E2E job meets 31 and 34 only, so a component the bundle needs that 32 or 33 lacks would
	 * reach users unseen. Weekly, a smoke run there: the app loads, and a vehicle and a reading go
	 * in through the screen, on the stack docs/development.md#testing describes.
	 */
	public function testTheWeeklyRunSmokesTheEndToEndOnNextcloud32And33(): void {
		$job = $this->job('e2e-weekly');
		$this->assertSame("github.event_name == 'schedule'", $job['if'] ?? null);
		$script = $this->script('e2e-weekly');

		$steps = [
			'npm run build',
			'docker compose -f .docker/weekly/compose.yml up -d --wait',
			'php occ app:enable nextfleet',
			'npx playwright test tests/e2e/m0-gate.spec.js tests/e2e/m1-slice.spec.js --project nc32 --project nc33',
		];
		$at = -1;
		foreach ($steps as $step) {
			$found = strpos($script, $step);
			$this->assertIsInt($found, "the weekly E2E job never runs '$step'");
			$this->assertGreaterThan($at, $found, "'$step' runs out of order");
			$at = $found;
		}
		$this->assertStringContainsString('for service in app32 app33', $script, 'the app is not enabled on both majors');
	}

	/**
	 * The upgrade check is the one test of what users keep across a release. Run only by hand at
	 * release time, a change that breaks it would be found then; weekly, it is found that week, on
	 * both databases the script knows.
	 */
	public function testTheWeeklyRunChecksTheUpgradeOnMariadbAndPostgresql(): void {
		$job = $this->job('upgrade');
		$this->assertSame("github.event_name == 'schedule'", $job['if'] ?? null);
		$this->assertSame(['mariadb', 'pgsql'], $job['strategy']['matrix']['db'] ?? null);
		// 0.2.0 and 0.3.0, the two a user may run from source (CHANGELOG, "For apps, scripts and admins").
		$this->assertSame(['27142b4', 'a0ca1f0'], $job['strategy']['matrix']['base'] ?? null);

		$checkout = $job['steps'][0] ?? [];
		$this->assertStringStartsWith('actions/checkout@', (string)($checkout['uses'] ?? ''));
		$this->assertSame(0, $checkout['with']['fetch-depth'] ?? null, 'the base commit is not in a shallow clone');

		$script = $this->script('upgrade');
		$package = strpos($script, 'npm run package');
		$check = strpos($script, 'npm run upgrade-check -- --db "$DB" build/artifacts/nextfleet-*.tar.gz "$BASE"');
		$this->assertIsInt($package, 'the upgrade job builds no package');
		$this->assertIsInt($check, 'the upgrade job never runs the check on the matrix database');
		$this->assertLessThan($check, $package);
	}

	/**
	 * A runner's clock is UTC, where a local date and a UTC date are the same day, so a spec that
	 * mixes them passes there. Los Angeles is behind UTC all year, so a run there shows the mix.
	 */
	public function testTheFrontendJobAlsoRunsVitestBehindUtc(): void {
		$zones = [];
		foreach ((array)$this->job('frontend')['steps'] as $step) {
			if (($step['run'] ?? null) === 'npm test') {
				$zones[] = $step['env']['TZ'] ?? 'the runner\'s';
			}
		}

		$this->assertSame(['the runner\'s', 'America/Los_Angeles'], $zones);
	}

	private function script(string $job): string {
		$script = '';
		foreach ((array)$this->job($job)['steps'] as $step) {
			$script .= (string)($step['run'] ?? '') . "\n";
		}

		return $script;
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
