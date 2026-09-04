<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$config = new Nextcloud\CodingStandard\Config();
$config
	->getFinder()
	->ignoreVCSIgnored(true)
	->ignoreDotFiles(false)
	->exclude('node_modules')
	->exclude('vendor')
	->in(__DIR__);

return $config;
