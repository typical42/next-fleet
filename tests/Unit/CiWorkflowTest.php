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
 * `actionlint` says GitHub will run the workflow, not that its matrix is the one
 * docs/development.md#testing asks for. The matrix is the point of the file.
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

	/** The gate of every weekly job and step: the schedule, or a run started by hand. */
	private const WEEKLY = "github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'";

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
		$key = $this->variableFor($event);
		$this->assertArrayHasKey($key, $env, "the plan job holds no combinations for '$event'");

		/** @var list<array{nextcloud: string, php: string, db: string}> $decoded */
		$decoded = json_decode((string)$env[$key], true, 512, JSON_THROW_ON_ERROR);

		return $decoded;
	}

	/**
	 * The variable the plan step's `case` reads for `$event`, picked as bash picks the arm: the
	 * first whose patterns name the event, else the first `*`.
	 */
	private function variableFor(string $event): string {
		preg_match_all('/^\s*([\w|*]+)\) combinations="\$(\w+)" ;;$/m', $this->script('plan'), $arms, PREG_SET_ORDER);
		foreach ($arms as [, $patterns, $variable]) {
			$names = explode('|', $patterns);
			if (in_array($event, $names, true) || in_array('*', $names, true)) {
				return $variable;
			}
		}

		$this->fail("the plan step picks no list for '$event'");
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

	public function testTheWorkflowRunsOnPullRequestsOnMainWeeklyAndOnDemand(): void {
		$triggers = $this->triggers();

		$this->assertSame(['pull_request', 'push', 'schedule', 'workflow_dispatch'], array_keys($triggers));
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

	public function testMergingToMainAddsPostgresqlOnNextcloud34(): void {
		$added = $this->added($this->combinations('pull_request'), $this->combinations('push'));

		$this->assertSame([['nextcloud' => 'stable34', 'php' => '8.5', 'db' => 'pgsql']], $added);
	}

	/**
	 * Loudly means it stays red: `continue-on-error` concludes the job green, so a weekly break
	 * would be reported to nobody.
	 */
	public function testTheWeeklyRunIsFullerThanMainAndFailsLoudly(): void {
		$this->assertSame([], $this->added($this->combinations('schedule'), $this->combinations('push')));
		$this->assertGreaterThan(count($this->combinations('push')), count($this->combinations('schedule')));

		$this->assertArrayNotHasKey('continue-on-error', $this->job('server'));
	}

	/**
	 * Started by hand, the run is the weekly one: before a release, or to see a fix to a weekly
	 * job without waiting for Monday.
	 */
	public function testARunStartedByHandRunsTheWeeklyMatrix(): void {
		$this->assertSame($this->combinations('schedule'), $this->combinations('workflow_dispatch'));
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
	 * The server job is the only one with a database. Without the integration suite no migration
	 * meets a real schema; without the API suite nothing signs in with an app password.
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
	 * Both suites speak HTTP - the API suite throughout, the integration suite to read what the
	 * server's own routes answer - and a checkout of the server is not a web server. The one it is
	 * given must be up before either runs, and each must be told where it listens.
	 */
	public function testTheServerJobServesNextcloudBeforeBothSuitesThatCallIt(): void {
		$script = $this->script('server');

		$serve = strpos($script, 'php -S localhost:8080');
		$this->assertIsInt($serve, 'the server job starts no web server');
		$this->assertLessThan(strpos($script, 'composer run test:integration'), $serve);
		$this->assertLessThan(strpos($script, 'composer run test:api'), $serve);

		$this->assertSame('http://localhost:8080', $this->env('server', 'composer run test:integration')['NEXTFLEET_SERVER_URL'] ?? null);
		$this->assertSame('http://localhost:8080', $this->env('server', 'composer run test:api')['NEXTFLEET_API_URL'] ?? null);
	}

	/**
	 * The reminder digest and `occ nextfleet:mail-test` are proven by the mail Mailpit caught, as
	 * on the dev stack (.docker/compose.yml). The image carries its digest: a tag can move.
	 */
	public function testTheServerJobSendsMailToMailpitBeforeTheIntegrationSuite(): void {
		$services = (array)($this->job('server')['services'] ?? []);
		$this->assertArrayHasKey('mailpit', $services, 'the server job runs no Mailpit');
		$this->assertMatchesRegularExpression('{^axllent/mailpit:v[\d.]+@sha256:[0-9a-f]{64}$}', (string)($services['mailpit']['image'] ?? ''));
		$this->assertEqualsCanonicalizing(['1025:1025', '8025:8025'], (array)($services['mailpit']['ports'] ?? []));

		$script = $this->script('server');
		$suite = strpos($script, 'composer run test:integration');
		foreach ([
			'mail_smtpmode --value=smtp',
			'mail_smtphost --value=localhost',
			'mail_smtpport --value=1025',
			'mail_from_address --value=nextfleet',
			'mail_domain --value=example.org',
		] as $setting) {
			$at = strpos($script, 'php occ config:system:set ' . $setting);
			$this->assertIsInt($at, "the server job never sets $setting");
			$this->assertLessThan($suite, $at, "$setting is set after the integration suite");
		}

		$this->assertSame('http://localhost:8025', $this->env('server', 'composer run test:integration')['NEXTFLEET_MAILPIT_URL'] ?? null);
	}

	/**
	 * A notice is stored, counted and read back by the notifications app, which a release and the
	 * image ship and a checkout of the server does not. It comes from the same branch as the server.
	 */
	public function testTheServerJobRunsTheNotificationsAppBeforeTheIntegrationSuite(): void {
		$steps = (array)$this->job('server')['steps'];
		$checkouts = array_keys(array_filter(
			$steps,
			static fn (mixed $step): bool => (((array)$step)['with']['repository'] ?? null) === 'nextcloud/notifications',
		));
		$this->assertCount(1, $checkouts, 'the server job never checks out the notifications app');
		$checkout = (array)$steps[$checkouts[0]];
		$this->assertSame('${{ matrix.nextcloud }}', $checkout['with']['ref'] ?? null);
		$this->assertSame('apps/notifications', $checkout['with']['path'] ?? null);

		// Its lib/ uses the web-push classes `composer install` builds into lib/Vendor, which
		// need gmp.
		$installs = array_keys(array_filter(
			$steps,
			static fn (mixed $step): bool => (((array)$step)['working-directory'] ?? null) === 'apps/notifications'
				&& str_starts_with((string)(((array)$step)['run'] ?? ''), 'composer install --no-dev'),
		));
		$this->assertCount(1, $installs, 'the notifications app\'s dependencies are never installed');
		$this->assertGreaterThan($checkouts[0], $installs[0]);
		// The install enables it, and on 34 it fails to load without lib/Vendor.
		$install = array_key_first(array_filter(
			$steps,
			static fn (mixed $step): bool => str_contains((string)(((array)$step)['run'] ?? ''), 'php occ maintenance:install'),
		));
		$this->assertIsInt($install, 'the server job never installs Nextcloud');
		$this->assertLessThan($install, $installs[0], 'the notifications app is enabled before its dependencies are installed');
		$this->assertContains('gmp', $this->phpExtensions());

		$script = $this->script('server');
		$enable = strpos($script, 'php occ app:enable notifications');
		$this->assertIsInt($enable, 'the server job never enables the notifications app');
		$this->assertLessThan(strpos($script, 'composer run test:integration'), $enable);
	}

	/**
	 * The import's remembered answer and the names of a vehicle's files live in the distributed
	 * cache. Without a memcache that is a NullCache, which remembers nothing. The dev stack's
	 * image sets APCu, which the integration suite reaches from the command line.
	 */
	public function testTheServerJobCachesInApcuAsTheDevStackDoes(): void {
		$this->assertContains('apcu', $this->phpExtensions());
		$this->assertStringContainsString('apc.enable_cli=1', (string)($this->setupPhp()['with']['ini-values'] ?? ''));

		$script = $this->script('server');
		$cache = strpos($script, "php occ config:system:set memcache.local --value='\\OC\\Memcache\\APCu'");
		$this->assertIsInt($cache, 'the server job sets no local memcache');
		$this->assertLessThan(strpos($script, 'composer run test:integration'), $cache);
	}

	/** @return array<string, mixed> the server job's setup-php step */
	private function setupPhp(): array {
		$steps = array_values(array_filter(
			(array)$this->job('server')['steps'],
			static fn (mixed $step): bool => str_starts_with((string)(((array)$step)['uses'] ?? ''), 'shivammathur/setup-php@'),
		));
		$this->assertCount(1, $steps, 'the server job sets PHP up not exactly once');

		return (array)$steps[0];
	}

	/** @return list<string> */
	private function phpExtensions(): array {
		return array_map('trim', explode(',', (string)($this->setupPhp()['with']['extensions'] ?? '')));
	}

	/**
	 * The environment of the one step of `$job` that runs `$command`.
	 *
	 * @return array<string, mixed>
	 */
	private function env(string $job, string $command): array {
		$steps = array_values(array_filter(
			(array)$this->job($job)['steps'],
			static fn (mixed $step): bool => trim((string)(((array)$step)['run'] ?? '')) === $command,
		));
		$this->assertCount(1, $steps, "no single step of '$job' runs '$command'");

		return (array)($steps[0]['env'] ?? []);
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
		$this->assertSame(self::WEEKLY, $installs[0]['if'] ?? null);

		$script = $this->script('server');
		$this->assertLessThan(strpos($script, 'composer run test:integration'), strpos($script, 'php occ app:install user_migration'));
	}

	/**
	 * openapi.json is the contract a client is built against (docs/api.md). Generated from the
	 * code, it drifts the moment somebody forgets to regenerate it.
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
		$this->assertSame(self::WEEKLY, $this->job('oracle')['if'] ?? null);
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
		$this->assertSame(self::WEEKLY, $job['if'] ?? null);
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
	 * The upgrade check is the one test of what users keep across a release. Weekly, a change that
	 * breaks it is found that week rather than at release time.
	 */
	public function testTheWeeklyRunChecksTheUpgradeOnMariadbAndPostgresql(): void {
		$job = $this->job('upgrade');
		$this->assertSame(self::WEEKLY, $job['if'] ?? null);
		$this->assertSame(['mariadb', 'pgsql'], $job['strategy']['matrix']['db'] ?? null);
		// 0.2.0 and 0.3.0, the two a user may run from source (CHANGELOG, "For apps, scripts and admins").
		$this->assertSame(['9056da5', 'e7bdbe1'], $job['strategy']['matrix']['base'] ?? null);

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

	/**
	 * Only after a build can LicensingTest read which packages the bundle carries. It skips
	 * without one, and here that skip must fail: no `.license` file means the check saw nothing.
	 */
	public function testTheFrontendJobChecksTheBundlesLicencesAfterTheBuild(): void {
		$script = $this->script('frontend');

		$build = strpos($script, 'npm run build');
		$check = strpos($script, 'vendor/bin/phpunit --fail-on-skipped --filter LicensingTest');
		$this->assertIsInt($build, 'the frontend job builds no bundle');
		$this->assertIsInt($check, 'the frontend job never checks the bundle\'s licences');
		$this->assertLessThan($check, $build, 'the licences are checked before the build');
	}

	/** One gate, on the packages the bundle ships; the step's comment says why not the dev tools. */
	public function testTheFrontendJobAuditsTheShippedPackages(): void {
		$audits = array_values(array_filter(
			explode("\n", $this->script('frontend')),
			static fn (string $line): bool => str_starts_with($line, 'npm audit'),
		));

		$this->assertSame(['npm audit --omit=dev --audit-level moderate'], $audits);
	}

	private function script(string $job): string {
		$script = '';
		foreach ((array)$this->job($job)['steps'] as $step) {
			$script .= (string)($step['run'] ?? '') . "\n";
		}

		return $script;
	}

	/**
	 * Both go missing unseen: without a bundle the Vue root stays empty, and without `--wait`
	 * "the stack is up" means apache answered, not that Nextcloud installed (ComposeStackTest).
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
	 * A runner is gone when the job ends, and a red E2E is read from its traces, not its log.
	 * Only a failure uploads, and only for days: a green run's evidence answers nothing. A job
	 * past its `timeout-minutes` ends cancelled, not failed, and a stalled spec is the one to read.
	 */
	public function testEveryEndToEndJobKeepsPlaywrightsEvidenceWhenItFails(): void {
		$names = [];
		foreach ([
			'e2e' => 'npm run test:e2e',
			'e2e-weekly' => 'npx playwright test tests/e2e/m0-gate.spec.js tests/e2e/m1-slice.spec.js --project nc32 --project nc33',
		] as $job => $command) {
			$steps = array_values((array)$this->job($job)['steps']);
			$run = array_search($command, array_map(static fn (mixed $step): string => (string)(((array)$step)['run'] ?? ''), $steps), true);
			$this->assertIsInt($run, "'$job' never runs the E2E");

			$uploads = array_filter(
				$steps,
				static fn (mixed $step): bool => str_starts_with((string)(((array)$step)['uses'] ?? ''), 'actions/upload-artifact@'),
			);
			$this->assertCount(1, $uploads, "'$job' uploads its evidence not exactly once");
			$at = array_key_first($uploads);
			$upload = (array)$uploads[$at];

			$this->assertGreaterThan($run, $at, "'$job' uploads the evidence before the run that writes it");
			$this->assertSame('failure() || cancelled()', $upload['if'] ?? null);
			$this->assertSame('test-results/', $upload['with']['path'] ?? null);
			$this->assertLessThanOrEqual(7, (int)($upload['with']['retention-days'] ?? 90));
			$names[] = $upload['with']['name'] ?? null;
		}

		// One run holds both, and an artifact's name is unique within a run.
		$this->assertSame($names, array_unique($names));
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
