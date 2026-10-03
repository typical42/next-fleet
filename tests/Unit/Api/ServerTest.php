<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Api;

use OCA\NextFleet\Tests\Api\Server;
use PHPUnit\Framework\TestCase;

/**
 * The API suite parses what occ prints - `user:list` as JSON, the app password off its last line -
 * so a deprecation notice the server writes to stderr must not land in it. A failed call fails
 * with the reason occ gave, which it writes to stderr.
 */
class ServerTest extends TestCase {
	private string $root = '';
	private string|false $was = false;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/nextfleet-occ-' . bin2hex(random_bytes(4));
		mkdir($this->root);
		// Prints its first argument, complains on stderr, and exits with its second.
		file_put_contents($this->root . '/occ', '<?php echo $argv[1]; fwrite(STDERR, "Deprecated: somewhere\n"); exit((int)$argv[2]);');
		$this->was = getenv('NEXTCLOUD_ROOT');
		putenv('NEXTCLOUD_ROOT=' . $this->root);
	}

	protected function tearDown(): void {
		putenv($this->was === false ? 'NEXTCLOUD_ROOT' : 'NEXTCLOUD_ROOT=' . $this->was);
		unlink($this->root . '/occ');
		rmdir($this->root);
	}

	public function testItAnswersWhatOccPrintedToStdoutAlone(): void {
		$this->assertSame('{"anna":"Anna"}', Server::fromEnvironment()->occ('{"anna":"Anna"}', '0'));
	}

	/** Stdout too: many commands print their refusal there, as `<error>` lines. */
	public function testAFailedCallFailsWithWhatOccWroteToStderr(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessageMatches('/^occ user:delete 3 exited 3:\nDeprecated: somewhere\nuser:delete$/');

		Server::fromEnvironment()->occ('user:delete', '3');
	}
}
