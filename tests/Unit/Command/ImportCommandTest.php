<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Command;

use OCA\NextFleet\Command\ImportCommand;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\UserZone;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * An import from a script: the request the screen would send, built from the command line and
 * handed to the same service (docs/architecture.md#import).
 */
class ImportCommandTest extends TestCase {
	private const USER = 'alice';
	private const VEHICLE = '0c5e2b1a-7a0e-4c39-9d43-3f6d2a4e8b11';
	private const FILE_ID = 4711;

	/** What the service answers a preview of the LubeLogger fuel fixture. */
	private const PREVIEW = [
		'importer' => 'lubelogger',
		'record_type' => 'fuel',
		'columns' => [
			'placed' => [['header' => 'Date', 'field' => 'filled_at'], ['header' => 'FuelConsumed', 'field' => 'amount']],
			'ignored' => ['FuelEconomy', 'Notes'],
		],
		'questions' => [],
		'categories' => [],
		'category_defaults' => [],
		'counts' => ['new' => 2, 'duplicate' => 1, 'unreadable' => 2, 'creates' => 2],
		'reasons' => [['reason' => 'missing', 'count' => 1], ['reason' => 'date', 'count' => 1]],
		'proposals' => [],
		'etag' => 'etag-1',
	];

	private ImportService&MockObject $import;
	private IUserManager&MockObject $users;
	private Folder&MockObject $files;
	private IConfig&MockObject $config;

	protected function setUp(): void {
		$this->import = $this->createMock(ImportService::class);
		$this->users = $this->createMock(IUserManager::class);
		$this->users->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === self::USER);
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(self::FILE_ID);
		$folder = $this->createMock(Folder::class);
		$this->files = $this->createMock(Folder::class);
		$this->files->method('get')->willReturnCallback(static fn (string $path): Node => match ($path) {
			'exports/fuel.csv' => $file,
			'exports' => $folder,
			default => throw new NotFoundException($path),
		});
		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getUserValue')->willReturnCallback(static fn (string $uid, string $app, string $key, mixed $default): mixed => $app === 'core' && $key === 'timezone' ? 'Europe/Berlin' : $default);
		$this->config->method('getSystemValueString')->willReturnCallback(static fn (string $key, string $default): string => $default);
	}

	public function testADryRunPrintsThePreviewAndWritesNothing(): void {
		$this->import->expects($this->once())->method('preview')
			->with(self::USER, self::VEHICLE, [
				'file_id' => self::FILE_ID,
				'importer' => 'lubelogger',
				'record_type' => 'fuel',
				'units' => ['distance' => 'mi', 'volume' => 'us_gal'],
				'tz' => 'Europe/Berlin',
				'include_duplicates' => false,
			])
			->willReturn(self::PREVIEW);
		$this->import->expects($this->never())->method('import');

		$tester = $this->command(['--units' => 'mi,us_gal', '--dry-run' => true]);

		$this->assertSame(0, $tester->getStatusCode());
		$display = $tester->getDisplay();
		$this->assertStringContainsString('Date → filled_at', $display);
		$this->assertStringContainsString('FuelConsumed → amount', $display);
		$this->assertStringContainsString('Not placed: FuelEconomy, Notes', $display);
		$this->assertStringContainsString('2 new, 1 duplicate, 2 unreadable', $display);
		$this->assertStringContainsString('missing: 1', $display);
		$this->assertStringContainsString('date: 1', $display);
		$this->assertStringNotContainsString('Imported', $display);
	}

	/** A category the format gives a meaning is not asked, so the dry run says what it becomes. */
	public function testADryRunNamesWhatEachCategoryBecomesByDefault(): void {
		$this->import->method('preview')->willReturn([
			'categories' => ['6', '1'],
			'category_defaults' => [['text' => '6', 'meaning' => 'expense.tax'], ['text' => '1', 'meaning' => 'maintenance.service']],
		] + self::PREVIEW);

		$tester = $this->command(['--units' => 'km,l', '--dry-run' => true]);

		$this->assertStringContainsString('By default: 6 → expense.tax, 1 → maintenance.service', $tester->getDisplay());
	}

	/** Without `--dry-run` the import follows, sending the etag its preview answered. */
	public function testAnImportSendsThePreviewsEtag(): void {
		$this->import->method('preview')->willReturn(self::PREVIEW);
		$this->import->expects($this->once())->method('import')
			->with(self::USER, self::VEHICLE, $this->callback(static fn (array $fields): bool => $fields['etag'] === 'etag-1' && $fields['file_id'] === self::FILE_ID))
			->willReturn(['counts' => self::PREVIEW['counts'], 'created' => [['type' => 'energy', 'uuid' => 'a'], ['type' => 'energy', 'uuid' => 'b']]]);

		$tester = $this->command(['--units' => 'km,l']);

		$this->assertSame(0, $tester->getStatusCode());
		$this->assertStringContainsString('Imported 2 entries.', $tester->getDisplay());
		$this->assertStringNotContainsString('energy a', $tester->getDisplay());
	}

	/** Verbose, it names each entry it created: the list an undo through the API takes. */
	public function testVerboseItListsWhatItCreated(): void {
		$this->import->method('preview')->willReturn(self::PREVIEW);
		$this->import->method('import')->willReturn(['counts' => self::PREVIEW['counts'], 'created' => [['type' => 'energy', 'uuid' => 'a'], ['type' => 'odometer', 'uuid' => 'b']]]);

		$tester = $this->command(['--units' => 'km,l'], ['verbosity' => OutputInterface::VERBOSITY_VERBOSE]);

		$this->assertStringContainsString("energy a\nodometer b\n", $tester->getDisplay());
	}

	/** Each answer the screen asks for has its option; a category text may hold a colon. */
	public function testTheAnswersTravelAsTheScreenSendsThem(): void {
		$this->import->expects($this->once())->method('preview')
			->with(self::USER, self::VEHICLE, $this->callback(static fn (array $fields): bool => array_intersect_key($fields, array_flip(['date_order', 'energy', 'category_map', 'include_duplicates'])) === [
				'include_duplicates' => true,
				'date_order' => 'mdy',
				'energy' => 'diesel',
				'category_map' => ['Steuer' => 'expense.tax', 'Wäsche: innen' => 'skip'],
			]))
			->willReturn(self::PREVIEW);

		$tester = $this->command([
			'--units' => 'km,l',
			'--date-order' => 'mdy',
			'--energy' => 'diesel',
			'--map' => ['Steuer:expense.tax', 'Wäsche: innen:skip'],
			'--include-duplicates' => true,
			'--dry-run' => true,
		]);

		$this->assertSame(0, $tester->getStatusCode());
	}

	/** A user without a zone of their own reads dates in the server's. */
	public function testTheServersZoneStandsInForTheUsers(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(static fn (string $uid, string $app, string $key, mixed $default): mixed => $default);
		$config->method('getSystemValueString')->willReturnMap([['default_timezone', 'UTC', 'America/New_York']]);
		$this->config = $config;
		$this->import->expects($this->once())->method('preview')
			->with(self::USER, self::VEHICLE, $this->callback(static fn (array $fields): bool => $fields['tz'] === 'America/New_York'))
			->willReturn(self::PREVIEW);

		$this->command(['--dry-run' => true]);
	}

	/** An open question is printed with its choices, and the import it blocks fails the command. */
	public function testAnOpenQuestionFailsTheImport(): void {
		$this->import->method('preview')->willReturn(['questions' => [['name' => 'date_order', 'choices' => ['dmy', 'mdy']]]] + self::PREVIEW);
		$this->import->method('import')->willThrowException(new \InvalidArgumentException('Still to be answered: date_order'));

		$tester = $this->command(['--units' => 'km,l']);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('Still to be answered: date_order (dmy, mdy)', $tester->getDisplay());
	}

	public function testAFileTheImportWillNotReadFailsWithItsReason(): void {
		$this->import->method('preview')->willThrowException(new ImportRefusedException('line_too_long', 7));
		$this->import->expects($this->never())->method('import');

		$tester = $this->command(['--units' => 'km,l']);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('line_too_long at row 7', $tester->getDisplay());
	}

	/**
	 * What the command line itself gets wrong is refused before the service is asked.
	 *
	 * @param array<string, mixed> $options
	 */
	#[DataProvider('mistakes')]
	public function testAMistakeIsRefusedBeforeTheServiceIsAsked(array $options, string $says): void {
		$this->import->expects($this->never())->method('preview');

		$tester = $this->command($options);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString($says, $tester->getDisplay());
	}

	/** @return array<string, array{array<string, mixed>, string}> */
	public static function mistakes(): array {
		return [
			'no such user' => [['user' => 'mallory'], 'No such user: mallory'],
			'no such file' => [['path' => 'exports/gone.csv'], 'No such file in alice\'s Files: exports/gone.csv'],
			'a folder' => [['path' => 'exports'], 'No such file in alice\'s Files: exports'],
			'an unknown unit' => [['--units' => 'km,barrel'], 'not barrel'],
			'two units of one dimension' => [['--units' => 'km,l,mi'], 'not km and mi'],
			'a map without its choice' => [['--map' => ['Steuer']], '--map takes text:choice'],
		];
	}

	/**
	 * @param array<string, mixed> $options
	 * @param array{verbosity?: int} $settings
	 */
	private function command(array $options = [], array $settings = []): CommandTester {
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnMap([[self::USER, $this->files]]);
		$tester = new CommandTester(new ImportCommand($this->import, $this->users, $root, new UserZone($this->config)));
		$tester->execute($options + [
			'user' => self::USER,
			'vehicle' => self::VEHICLE,
			'path' => 'exports/fuel.csv',
			'--importer' => 'lubelogger',
			'--record-type' => 'fuel',
		], $settings);

		return $tester;
	}
}
