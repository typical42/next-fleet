<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * tools/release.sh runs in a throwaway git repository that holds the script and the files it
 * edits. composer, npm, docker and tools/upgrade-check.sh are stubs that log what they are asked;
 * git, tar and openssl are real.
 */
class ReleaseScriptTest extends TestCase {
	private const SCRIPT = __DIR__ . '/../../tools/release.sh';

	private string $dir;
	private string $repo;
	private string $log;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/nextfleet-release-' . bin2hex(random_bytes(4));
		$this->repo = $this->dir . '/repo';
		$this->log = $this->dir . '/log';
		mkdir($this->dir . '/bin', 0700, true);
		touch($this->log);

		$this->put('tools/release.sh', (string)file_get_contents(self::SCRIPT));
		$this->put('tools/upgrade-check.sh', "echo \"upgrade-check \$*\" >>\"\$RELEASE_LOG\"\n");
		$this->put('appinfo/info.xml', "<info>\n\t<version>1.2.3</version>\n</info>\n");
		$this->put('CHANGELOG.md', "# Changelog\n\n## 1.2.3 — not released\n\nNews.\n\n## 1.2.2 — 2026-01-02\n");
		$this->put('openapi.json', "{\"paths\": {\"new\": {}}}\n");
		$this->put('tests/Api/v1-baseline.json', "{\"paths\": {}}\n");
		$this->put('.gitignore', "/build/\n");
		$this->git('init', '-q');
		$this->git('add', '-A');
		$this->git('commit', '-qm', 'init');

		// $RELEASE_FAIL names the one call that fails, as it is logged.
		$logged = "echo \"\$(basename \"\$0\") \$*\" >>\"\$RELEASE_LOG\"\n"
			. "[ \"\$(basename \"\$0\") \$*\" != \"\${RELEASE_FAIL:-}\" ] || exit 1\n";
		$this->stub('composer', $logged);
		$this->stub('npm', $logged . <<<'SH'
			if [ "$*" = 'run package' ]; then
				mkdir -p build/stage/nextfleet/appinfo build/artifacts
				cp appinfo/info.xml build/stage/nextfleet/appinfo/
				tar -czf build/artifacts/nextfleet-1.2.3.tar.gz -C build/stage nextfleet
			fi
			SH);
		// The signing writes signature.json in the container; copying it out writes the stub's.
		// $RELEASE_UNSIGNED makes the integrity check find errors.
		$this->stub('docker', $logged . <<<'SH'
			case $* in
				*integrity:sign-app*) cat >"$RELEASE_LOG.stdin" ;;
				*'cp app34:'*signature.json*)
					for last; do :; done
					echo '{"signed": true}' >"$last"
					;;
				*integrity:check-app*)
					case ${RELEASE_UNSIGNED:-} in
						errors)
							echo 'nextfleet: 1 errors found:'
							exit 1
							;;
						skipped) echo 'nextfleet: App signature not found, skipping app integrity check' ;;
						*) echo 'No errors found' ;;
					esac
					;;
			esac
			SH);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testItRefusesAnUnknownPhase(): void {
		[$status, $output] = $this->release('publish');

		$this->assertSame(2, $status);
		$this->assertStringContainsString('usage:', $output);
	}

	/**
	 * What prepare edits must be the only change in its commit, and build must pack what is
	 * committed. An untracked file counts: the review lists it, so it is part of the release.
	 */
	public function testEitherPhaseRefusesATreeWithChanges(): void {
		$this->put('notes.txt', "draft\n");

		foreach ([['prepare', '2026-10-20'], ['build', '--key', $this->key(), '--cert', $this->cert()]] as $args) {
			[$status, $output] = $this->release(...$args);

			$this->assertSame(1, $status, $args[0]);
			$this->assertStringContainsString('uncommitted changes', $output, $args[0]);
		}
		$this->assertSame('', (string)file_get_contents($this->log), 'a phase ran a step');
	}

	public function testPrepareDatesTheSectionPromisesTheApiAndRunsTheChecks(): void {
		[$status, $output] = $this->release('prepare', '2026-10-20');

		$this->assertSame(0, $status, $output);
		$this->assertStringContainsString("\n## 1.2.3 — 2026-10-20\n\nNews.\n\n## 1.2.2 — 2026-01-02\n", $this->read('CHANGELOG.md'));
		$this->assertSame($this->read('openapi.json'), $this->read('tests/Api/v1-baseline.json'));
		// The build first: LicensingTest reads the bundle and skips without one.
		$this->assertSame(['npm run build', 'composer test', 'composer lint', 'npm test', 'npm run lint'], $this->logged());
		$this->assertStringContainsString('commit now', $output);
		$this->assertStringContainsString('Release 1.2.3', $output);
	}

	/** The store and InfoXmlTest read the date as YYYY-MM-DD. */
	public function testPrepareRefusesADateInAnotherForm(): void {
		foreach (['20.10.2026', '2026-10-20x', '2026-13-01', ''] as $date) {
			[$status, $output] = $this->release('prepare', $date);

			$this->assertNotSame(0, $status, $date);
			$this->assertStringContainsString('YYYY-MM-DD', $output, $date);
		}
		$this->assertStringContainsString('## 1.2.3 — not released', $this->read('CHANGELOG.md'));
		$this->assertSame([], $this->logged());
	}

	public function testPrepareStopsAtTheFirstCheckThatFails(): void {
		[$status, $output] = $this->releaseWith(['RELEASE_FAIL' => 'composer lint'], 'prepare', '2026-10-20');

		$this->assertNotSame(0, $status);
		$this->assertSame(['npm run build', 'composer test', 'composer lint'], $this->logged());
		$this->assertStringNotContainsString('commit now', $output);
		// Its edits stay, and a rerun refuses the changed tree, so it says how to start over.
		$this->assertStringContainsString('git checkout CHANGELOG.md tests/Api/v1-baseline.json', $output);
	}

	/** A dated section is a released version: preparing it again would re-date it. */
	public function testPrepareRefusesAVersionWhoseSectionIsDated(): void {
		$this->put('CHANGELOG.md', "# Changelog\n\n## 1.2.3 — 2026-10-01\n");
		$this->git('commit', '-qam', 'released');

		[$status, $output] = $this->release('prepare', '2026-10-20');

		$this->assertSame(1, $status);
		$this->assertStringContainsString("no line '## 1.2.3 — not released'", $output);
		$this->assertSame([], $this->logged());
	}

	public function testBuildRefusesWithoutAKeyAndACertificate(): void {
		$this->prepared();
		$missing = $this->dir . '/missing.key';

		foreach ([
			[2, 'usage:', ['build']],
			[2, 'usage:', ['build', '--key', $this->key()]],
			[2, 'usage:', ['build', '--cert', $this->cert()]],
			[2, 'usage:', ['build', '--key', $this->key(), '--cert', $this->cert(), '--force']],
			[1, "no file $missing", ['build', '--key', $missing, '--cert', $this->cert()]],
			[1, "no file $missing", ['build', '--key', $this->key(), '--cert', $missing]],
		] as [$expected, $says, $args]) {
			[$status, $output] = $this->release(...$args);

			$this->assertSame($expected, $status, implode(' ', $args));
			$this->assertStringContainsString($says, $output, implode(' ', $args));
		}
		$this->assertSame([], $this->logged());
	}

	/**
	 * The key stays offline (docs/security.md#supply-chain). One inside the tree is a git add
	 * away from the repository, and the build copies the tree into its stage.
	 */
	public function testBuildRefusesAKeyInsideTheRepository(): void {
		$this->prepared();
		copy($this->key(), $this->repo . '/build/nextfleet.key');

		[$status, $output] = $this->release('build', '--key', $this->repo . '/build/nextfleet.key', '--cert', $this->cert());

		$this->assertSame(1, $status);
		$this->assertStringContainsString('inside the repository', $output);
		$this->assertSame([], $this->logged());
	}

	/** Build signs what is committed, so what prepare writes must be in the commit. */
	public function testBuildRefusesATreePrepareHasNotRunOn(): void {
		[$status, $output] = $this->release('build', '--key', $this->key(), '--cert', $this->cert());
		$this->assertSame(1, $status);
		$this->assertStringContainsString('release -- prepare', $output);

		$this->put('CHANGELOG.md', "# Changelog\n\n## 1.2.3 — 2026-10-20\n");
		$this->git('commit', '-qam', 'dated only');
		[$status, $output] = $this->release('build', '--key', $this->key(), '--cert', $this->cert());
		$this->assertSame(1, $status);
		$this->assertStringContainsString('release -- prepare', $output);

		$this->assertSame([], $this->logged());
	}

	public function testBuildSignsChecksAndPrintsTheStoreSignature(): void {
		$this->prepared();
		$tarball = $this->repo . '/build/artifacts/nextfleet-1.2.3.tar.gz';

		[$status, $output] = $this->release('build', '--key', $this->key(), '--cert', $this->cert());

		$this->assertSame(0, $status, $output);
		$log = $this->logged();
		$this->assertSame('npm run package', $log[0]);

		$sign = $this->lineOf('integrity:sign-app');
		$this->assertStringContainsString('--privateKey=php://stdin', $log[$sign]);
		$this->assertSame(file_get_contents($this->key()), file_get_contents($this->log . '.stdin'), 'the key does not come on stdin');
		$this->assertStringNotContainsString($this->key(), implode("\n", $log));
		exec('grep -rlF "PRIVATE KEY" ' . escapeshellarg($this->repo), $holders);
		$this->assertSame([], $holders, 'the key landed in the repository');
		$this->assertFileEquals($this->cert(), $this->repo . '/build/nextfleet.crt');
		exec('tar -xOzf ' . escapeshellarg($tarball) . ' nextfleet/appinfo/signature.json', $signature);
		$this->assertSame(['{"signed": true}'], $signature);

		$check = $this->lineOf('integrity:check-app');
		$this->assertGreaterThan($sign, $check);
		$this->assertSame([
			'upgrade-check build/artifacts/nextfleet-1.2.3.tar.gz 9056da5',
			'upgrade-check build/artifacts/nextfleet-1.2.3.tar.gz e7bdbe1',
			'upgrade-check --db pgsql build/artifacts/nextfleet-1.2.3.tar.gz 9056da5',
			'upgrade-check --db pgsql build/artifacts/nextfleet-1.2.3.tar.gz e7bdbe1',
			'upgrade-check --db oracle build/artifacts/nextfleet-1.2.3.tar.gz 9056da5',
		], array_values(array_filter($log, static fn (string $line): bool => str_starts_with($line, 'upgrade-check '))));
		$this->assertGreaterThan($check, $this->lineOf('upgrade-check '));

		$this->assertSame(1, preg_match('/^([A-Za-z0-9+\/=\n]{300,})$/m', $output, $match), $output);
		$private = openssl_pkey_get_private((string)file_get_contents($this->key()));
		$this->assertNotFalse($private);
		$public = openssl_pkey_get_details($private);
		$this->assertIsArray($public);
		$this->assertSame(1, openssl_verify((string)file_get_contents($tarball), (string)base64_decode($match[1]), $public['key'], OPENSSL_ALGO_SHA512));
	}

	/**
	 * check-app exits 0 for an app without signature.json, so its exit status alone would pass
	 * an unsigned tarball.
	 *
	 * @dataProvider checksThatFail
	 */
	public function testBuildStopsWhenTheSignatureDoesNotCheckOut(string $unsigned, string $says): void {
		$this->prepared();

		[$status, $output] = $this->releaseWith(['RELEASE_UNSIGNED' => $unsigned], 'build', '--key', $this->key(), '--cert', $this->cert());

		$this->assertSame(1, $status);
		$this->assertStringContainsString('the signed tarball does not check out: ' . $says, $output);
		$this->assertSame([], array_filter($this->logged(), static fn (string $line): bool => str_starts_with($line, 'upgrade-check ')));
		$log = $this->logged();
		$this->assertStringContainsString(' down ', end($log), 'the throwaway server is left running');
	}

	/** @return array<string, array{string, string}> */
	public static function checksThatFail(): array {
		return [
			'errors found' => ['errors', 'nextfleet: 1 errors found'],
			'no signature' => ['skipped', 'nextfleet: App signature not found'],
		];
	}

	private function lineOf(string $needle): int {
		foreach ($this->logged() as $index => $line) {
			if (str_contains($line, $needle)) {
				return $index;
			}
		}
		$this->fail("nothing logged '$needle'");
	}

	/** The commit prepare asks for, as if the maintainer had made it. */
	private function prepared(): void {
		$this->put('CHANGELOG.md', "# Changelog\n\n## 1.2.3 — 2026-10-20\n\nNews.\n");
		$this->put('tests/Api/v1-baseline.json', $this->read('openapi.json'));
		$this->git('commit', '-qam', 'Release 1.2.3');
		mkdir($this->repo . '/build');
	}

	/** @return list<string> */
	private function logged(): array {
		return file($this->log, FILE_IGNORE_NEW_LINES) ?: [];
	}

	private function read(string $path): string {
		return (string)file_get_contents($this->repo . '/' . $path);
	}

	private function stub(string $name, string $body): void {
		file_put_contents($this->dir . '/bin/' . $name, "#!/bin/sh\n" . $body);
		chmod($this->dir . '/bin/' . $name, 0700);
	}

	private function key(): string {
		$key = $this->dir . '/nextfleet.key';
		if (!file_exists($key)) {
			exec('openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out ' . escapeshellarg($key) . ' 2>&1', $output, $status);
			$this->assertSame(0, $status, implode("\n", $output));
		}

		return $key;
	}

	/** Only the signing reads it, and the docker stub signs nothing. */
	private function cert(): string {
		$cert = $this->dir . '/nextfleet.crt';
		file_put_contents($cert, "-----BEGIN CERTIFICATE-----\nnot one\n-----END CERTIFICATE-----\n");

		return $cert;
	}

	private function put(string $path, string $content): void {
		$file = $this->repo . '/' . $path;
		if (!is_dir(dirname($file))) {
			mkdir(dirname($file), 0700, true);
		}
		file_put_contents($file, $content);
	}

	private function git(string ...$args): void {
		$command = 'git -c user.name=t -c user.email=t@example.com -c commit.gpgsign=false -C '
			. escapeshellarg($this->repo) . ' ' . implode(' ', array_map('escapeshellarg', $args));
		exec($command . ' 2>&1', $output, $status);
		$this->assertSame(0, $status, implode("\n", $output));
	}

	/**
	 * @param array<string, string> $env
	 * @return array{int, string} the exit status and stdout with stderr
	 */
	private function release(string ...$args): array {
		return $this->releaseWith([], ...$args);
	}

	/**
	 * @param array<string, string> $env
	 * @return array{int, string}
	 */
	private function releaseWith(array $env, string ...$args): array {
		$command = 'sh ' . escapeshellarg($this->repo . '/tools/release.sh') . ' '
			. implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1';
		$env += [
			'PATH' => $this->dir . '/bin:' . getenv('PATH'),
			'HOME' => (string)getenv('HOME'),
			'RELEASE_LOG' => $this->log,
		];
		$process = proc_open($command, [1 => ['pipe', 'w']], $pipes, $this->dir, $env);
		$this->assertIsResource($process);
		$output = (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		return [proc_close($process), $output];
	}
}
