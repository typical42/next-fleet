<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Controller;

use OCA\NextFleet\Controller\EntryAnswers;
use OCA\NextFleet\Controller\SessionUser;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Whose request it is, read the same way by every controller, and the refusals a service throws
 * answered the same way by every internal one.
 */
class SessionUserTest extends TestCase {
	public function testTheUserIsTheSessionsOne(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$this->assertSame('alice', self::reader($session)->uid());
	}

	/** Every route requires a login, so no user is a broken container, not a 403. */
	public function testNoUserInTheSessionIsAnError(): void {
		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No user in session');

		self::reader($this->createMock(IUserSession::class))->uid();
	}

	/**
	 * @return \Generator<string, array{class-string}>
	 */
	public static function controllers(): \Generator {
		$files = [...(glob(__DIR__ . '/../../../lib/Controller/*Controller.php') ?: []), ...(glob(__DIR__ . '/../../../lib/Controller/Ocs/*Controller.php') ?: [])];
		foreach ($files as $file) {
			$ocs = basename(dirname($file)) === 'Ocs';
			/** @var class-string $class */
			$class = 'OCA\\NextFleet\\Controller\\' . ($ocs ? 'Ocs\\' : '') . basename($file, '.php');
			yield $class => [$class];
		}
	}

	/**
	 * Through the shared traits, so a change to either reaches every route.
	 *
	 * @dataProvider controllers
	 * @param class-string $class
	 */
	public function testNoControllerSpellsOutItsOwnUserOrAnswers(string $class): void {
		$reflection = new ReflectionClass($class);
		$own = [];
		foreach (['userId' => SessionUser::class, 'answer' => EntryAnswers::class] as $method => $trait) {
			if ($reflection->hasMethod($method)
				&& $reflection->getMethod($method)->getFileName() !== (new ReflectionClass($trait))->getFileName()) {
				$own[] = $method;
			}
		}

		$this->assertSame([], $own, $class);
	}

	private static function reader(IUserSession $session): object {
		return new class($session) {
			use SessionUser;

			public function __construct(
				private IUserSession $session,
			) {
			}

			public function uid(): string {
				return $this->userId();
			}
		};
	}
}
