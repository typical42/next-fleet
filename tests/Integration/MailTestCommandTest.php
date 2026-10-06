<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Integration;

use OCA\NextFleet\AppInfo\Application;
use OCA\NextFleet\Command\MailTestCommand;
use OCA\NextFleet\Db\OdoReadingMapper;
use OCA\NextFleet\Db\ReminderMapper;
use OCA\NextFleet\Db\ReminderReceiptMapper;
use OCA\NextFleet\Db\VehicleMapper;
use OCA\NextFleet\Service\MailService;
use OCA\NextFleet\Service\UserZone;
use OCA\NextFleet\Service\VehicleAccess;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `occ nextfleet:mail-test`: one test mail to the address the digest would use, read back from
 * Mailpit as ReminderMailTest does.
 *
 * It empties Mailpit (docs/development.md#testing).
 */
class MailTestCommandTest extends TestCase {
	use Accounts;

	private const USER = 'nextfleet-test-mailtest';

	private CommandTester $command;

	public static function setUpBeforeClass(): void {
		self::deleteAccounts([self::USER]);
		\OCP\Server::get(IUserManager::class)->createUser(self::USER, bin2hex(random_bytes(16)))?->setSystemEMailAddress(self::USER . '@example.org');
	}

	public static function tearDownAfterClass(): void {
		self::deleteAccounts([self::USER]);
	}

	protected function setUp(): void {
		$this->command = new CommandTester(\OCP\Server::get(MailTestCommand::class));
		$this->mailpit('DELETE', '/messages');
	}

	protected function tearDown(): void {
		$user = \OCP\Server::get(IUserManager::class)->get(self::USER);
		$user?->setEnabled(true);
		$user?->setSystemEMailAddress(self::USER . '@example.org');
	}

	/** And the day's digest still goes: its mark is untouched. */
	public function testItMailsTheDigestsAddress(): void {
		$this->assertSame(0, $this->command->execute(['uid' => self::USER]), $this->command->getDisplay());

		$this->assertStringContainsString(self::USER . '@example.org', $this->command->getDisplay());
		$mails = $this->mails();
		$this->assertCount(1, $mails);
		$this->assertSame('NextFleet test mail', $mails[0]['Subject']);
		$this->assertSame('', \OCP\Server::get(IConfig::class)->getUserValue(self::USER, Application::APP_ID, MailService::CHECKED));
	}

	public function testAUserWithoutAnAddressIsRefused(): void {
		\OCP\Server::get(IUserManager::class)->get(self::USER)?->setSystemEMailAddress('');

		$this->assertSame(1, $this->command->execute(['uid' => self::USER], ['capture_stderr_separately' => true]));

		$this->assertStringContainsString('no email address', $this->command->getErrorOutput());
		$this->assertSame([], $this->mails());
	}

	/** The digest skips a disabled account, so a test mail would prove nothing. */
	public function testADisabledUserIsRefused(): void {
		\OCP\Server::get(IUserManager::class)->get(self::USER)?->setEnabled(false);

		$this->assertSame(1, $this->command->execute(['uid' => self::USER], ['capture_stderr_separately' => true]));

		$this->assertStringContainsString('disabled', $this->command->getErrorOutput());
		$this->assertSame([], $this->mails());
	}

	public function testAUidWithNoAccountIsBadInput(): void {
		$this->assertSame(2, $this->command->execute(['uid' => 'nextfleet-test-mailtest-nobody'], ['capture_stderr_separately' => true]));

		$this->assertSame('', $this->command->getDisplay());
		$this->assertStringContainsString('nextfleet-test-mailtest-nobody', $this->command->getErrorOutput());
	}

	public function testTheMailersFailureIsPrinted(): void {
		$command = $this->commandWith(static fn () => throw new \RuntimeException('Connection could not be established with host "mail:25"'));

		$this->assertSame(1, $command->execute(['uid' => self::USER], ['capture_stderr_separately' => true]));

		$this->assertSame('', $command->getDisplay());
		$this->assertStringContainsString('Connection could not be established with host "mail:25"', $command->getErrorOutput());
	}

	/** Nextcloud's mailer answers a refusal with the failed addresses rather than throwing. */
	public function testARefusalIsPrinted(): void {
		$command = $this->commandWith(static fn (): array => [self::USER . '@example.org']);

		$this->assertSame(1, $command->execute(['uid' => self::USER], ['capture_stderr_separately' => true]));

		$this->assertStringContainsString('refused', $command->getErrorOutput());
	}

	/**
	 * The command over a MailService whose mailer sends with $send: the real mailer builds the
	 * message, $send answers in its place.
	 *
	 * @param \Closure(IMessage): list<string> $send
	 */
	private function commandWith(\Closure $send): CommandTester {
		$real = \OCP\Server::get(IMailer::class);
		$mailer = $this->createMock(IMailer::class);
		$mailer->method('createMessage')->willReturnCallback(static fn () => $real->createMessage());
		$mailer->method('createEMailTemplate')->willReturnCallback(static fn (string $id, array $data = []) => $real->createEMailTemplate($id, $data));
		$mailer->method('send')->willReturnCallback($send);

		return new CommandTester(new MailTestCommand(new MailService(
			\OCP\Server::get(VehicleMapper::class),
			\OCP\Server::get(ReminderMapper::class),
			\OCP\Server::get(ReminderReceiptMapper::class),
			\OCP\Server::get(OdoReadingMapper::class),
			\OCP\Server::get(VehicleAccess::class),
			$mailer,
			\OCP\Server::get(IUserManager::class),
			\OCP\Server::get(IFactory::class),
			\OCP\Server::get(IConfig::class),
			\OCP\Server::get(UserZone::class),
			\OCP\Server::get(IURLGenerator::class),
			\OCP\Server::get(ITimeFactory::class),
			\OCP\Server::get(IDBConnection::class),
			\OCP\Server::get(LoggerInterface::class),
		), \OCP\Server::get(IUserManager::class)));
	}

	/**
	 * What reached the user's address.
	 *
	 * @return list<array{Subject: string, Text: string}>
	 */
	private function mails(): array {
		$list = $this->mailpit('GET', '/search?query=' . rawurlencode('to:' . self::USER . '@example.org'));

		return array_map(fn (array $one): array => $this->mailpit('GET', '/message/' . $one['ID']), $list['messages']);
	}

	private function mailpit(string $method, string $path): array {
		$response = \OCP\Server::get(IClientService::class)->newClient()->request($method, Endpoints::mailpit() . $path, [
			'nextcloud' => ['allow_local_address' => true],
		]);

		return json_decode((string)$response->getBody(), true) ?? [];
	}
}
