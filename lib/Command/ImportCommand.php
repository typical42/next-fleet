<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Command;

use OCA\NextFleet\Exception\AccessDeniedException;
use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\Exception\StaleUpdateException;
use OCA\NextFleet\Import\Values;
use OCA\NextFleet\Service\ImportService;
use OCA\NextFleet\Service\UserZone;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IUserManager;
use OCP\Lock\LockedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The Import screen for scripts (docs/architecture.md#import): the request the screen would send,
 * built from the command line and run as that user, so every check the routes make holds here too.
 * A preview first, always; the import then sends its etag, as the screen does.
 *
 * @psalm-import-type NextFleetImportPreview from \OCA\NextFleet\ResponseDefinitions as Preview
 */
class ImportCommand extends Command {
	/** The dimensions a user states units for; the others have one unit each. */
	private const ASKED_UNITS = ['distance', 'volume'];

	public function __construct(
		private ImportService $import,
		private IUserManager $users,
		private IRootFolder $root,
		private UserZone $zones,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('nextfleet:import')
			->setDescription('Import a CSV export from a user\'s own Files into one of their vehicles')
			->addArgument('user', InputArgument::REQUIRED, 'the uid that imports, and whose Files hold the file')
			->addArgument('vehicle', InputArgument::REQUIRED, 'the vehicle\'s uuid')
			->addArgument('path', InputArgument::REQUIRED, 'the file\'s path in the user\'s Files')
			->addOption('importer', null, InputOption::VALUE_REQUIRED, 'lubelogger or spritmonitor')
			->addOption('record-type', null, InputOption::VALUE_REQUIRED, 'what the file holds, e.g. fuel')
			->addOption('units', null, InputOption::VALUE_REQUIRED, 'the file\'s distance and volume units, e.g. km,l or mi,us_gal')
			->addOption('date-order', null, InputOption::VALUE_REQUIRED, 'dmy or mdy, when every date fits both')
			->addOption('energy', null, InputOption::VALUE_REQUIRED, 'the energy of the fill-ups, when the vehicle takes several')
			->addOption('map', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'a cost category text and what it becomes, e.g. "Steuer:expense.tax" or "Wäsche:skip"')
			->addOption('include-duplicates', null, InputOption::VALUE_NONE, 'import the rows already there as well')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'print the preview and write nothing');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = (string)$input->getArgument('user');
		$vehicleUuid = (string)$input->getArgument('vehicle');
		try {
			if (!$this->users->userExists($userId)) {
				throw new \InvalidArgumentException('No such user: ' . $userId);
			}
			$fields = $this->request($input, $userId);
			$preview = $this->import->preview($userId, $vehicleUuid, $fields);
			$this->describe($preview, $output);
			// The service ignores a text the file does not hold; here it can only be a typo.
			/** @var array<string, string> $mapped */
			$mapped = $fields['category_map'] ?? [];
			$unknown = array_diff(array_map('strval', array_keys($mapped)), $preview['categories']);
			if ($unknown !== []) {
				throw new \InvalidArgumentException('--map names ' . implode(', ', $unknown) . ', which the file does not hold');
			}
			if ($input->getOption('dry-run') === true) {
				return self::SUCCESS;
			}
			$result = $this->import->import($userId, $vehicleUuid, $fields + ['etag' => $preview['etag']]);
		} catch (\InvalidArgumentException|DoesNotExistException|AccessDeniedException|FileChangedException|LockedException $e) {
			$output->writeln('<error>' . $e->getMessage() . '</error>');

			return self::FAILURE;
		} catch (ImportRefusedException $e) {
			$output->writeln('<error>The file is not one an import reads: ' . $e->getMessage() . '</error>');

			return self::FAILURE;
		} catch (StaleUpdateException) {
			$output->writeln('<error>Another write to the vehicle raced the import; nothing was written. Run it again.</error>');

			return self::FAILURE;
		}

		$output->writeln('Imported ' . count($result['created']) . ' entries.');
		// The list the undo route takes; nothing else records it.
		foreach ($result['created'] as $entry) {
			$output->writeln($entry['type'] . ' ' . $entry['uuid'], OutputInterface::VERBOSITY_VERBOSE);
		}

		return self::SUCCESS;
	}

	/**
	 * What the screen would post. The zone is the user's own, else the server's, since a date
	 * without a time is noon where the user lives.
	 *
	 * @return array<string, mixed>
	 * @throws \InvalidArgumentException
	 */
	private function request(InputInterface $input, string $userId): array {
		$fields = [
			'file_id' => $this->file($userId, (string)$input->getArgument('path'))->getId(),
			'importer' => $input->getOption('importer'),
			'record_type' => $input->getOption('record-type'),
			'units' => self::units($input->getOption('units')),
			'tz' => $this->zones->of($userId)->getName(),
			'include_duplicates' => $input->getOption('include-duplicates') === true,
		];
		foreach (['date-order' => 'date_order', 'energy' => 'energy'] as $option => $field) {
			if ($input->getOption($option) !== null) {
				$fields[$field] = $input->getOption($option);
			}
		}
		/** @var list<string> $map */
		$map = $input->getOption('map');
		if ($map !== []) {
			$fields['category_map'] = self::categories($map);
		}

		return $fields;
	}

	/**
	 * The file at that path. Whether it is the user's own, and not a share, is the service's to
	 * decide, as it is for the screen's picker.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function file(string $userId, string $path): File {
		try {
			$node = $this->root->getUserFolder($userId)->get($path);
		} catch (NotFoundException) {
			$node = null;
		}
		if (!$node instanceof File) {
			throw new \InvalidArgumentException('No such file in ' . $userId . '\'s Files: ' . $path);
		}

		return $node;
	}

	/**
	 * `km,l` as the screen's `{distance: km, volume: l}`: each unit names its own dimension.
	 *
	 * @return array<string, string>
	 * @throws \InvalidArgumentException
	 */
	private static function units(mixed $option): array {
		$dimensions = [];
		foreach (self::ASKED_UNITS as $dimension) {
			$dimensions += array_fill_keys(array_keys(Values::UNITS[$dimension]), $dimension);
		}
		$units = [];
		foreach ($option === null ? [] : explode(',', (string)$option) as $unit) {
			$unit = trim($unit);
			if (!isset($dimensions[$unit])) {
				throw new \InvalidArgumentException('--units takes ' . implode(', ', array_keys($dimensions)) . ', not ' . $unit);
			}
			// A second unit for one dimension is a typo, not a correction: `km,mi` must not read km as miles.
			if (isset($units[$dimensions[$unit]])) {
				throw new \InvalidArgumentException('--units names one ' . $dimensions[$unit] . ' unit, not ' . $units[$dimensions[$unit]] . ' and ' . $unit);
			}
			$units[$dimensions[$unit]] = $unit;
		}

		return $units;
	}

	/**
	 * `text:choice` pairs as the screen's `category_map`. Split at the last colon, since a
	 * category text may hold one and a choice never does.
	 *
	 * @param list<string> $pairs
	 * @return array<string, string>
	 * @throws \InvalidArgumentException
	 */
	private static function categories(array $pairs): array {
		$map = [];
		foreach ($pairs as $pair) {
			$colon = strrpos($pair, ':');
			if ($colon === false || $colon === 0) {
				throw new \InvalidArgumentException('--map takes text:choice, e.g. "Steuer:expense.tax"');
			}
			$map[substr($pair, 0, $colon)] = substr($pair, $colon + 1);
		}

		return $map;
	}

	/**
	 * The preview as lines: the columns, what is still open, and the rows by outcome.
	 *
	 * @param Preview $preview
	 */
	private function describe(array $preview, OutputInterface $output): void {
		$output->writeln('Placed: ' . implode(', ', array_map(
			static fn (array $column): string => $column['header'] . ' → ' . $column['field'],
			$preview['columns']['placed'],
		)));
		if ($preview['columns']['ignored'] !== []) {
			$output->writeln('Not placed: ' . implode(', ', $preview['columns']['ignored']));
		}
		if ($preview['category_defaults'] !== []) {
			$output->writeln('By default: ' . implode(', ', array_map(
				static fn (array $default): string => $default['text'] . ' → ' . $default['meaning'],
				$preview['category_defaults'],
			)));
		}
		foreach ($preview['questions'] as $question) {
			$output->writeln('Still to be answered: ' . $question['name'] . ' (' . implode(', ', $question['choices']) . ')');
		}
		$counts = $preview['counts'];
		$output->writeln(sprintf(
			'Rows: %d new, %d duplicate, %d unreadable; an import creates %d.',
			$counts['new'],
			$counts['duplicate'],
			$counts['unreadable'],
			$counts['creates'],
		));
		if ($preview['reasons'] !== []) {
			$output->writeln('Left out: ' . implode(', ', array_map(
				static fn (array $reason): string => $reason['reason'] . ': ' . $reason['count'],
				$preview['reasons'],
			)));
		}
	}
}
