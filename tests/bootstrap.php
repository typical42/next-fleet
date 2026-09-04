<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

// Unit tests run outside a server, so everything an app class touches is mocked.
// nextcloud/ocp declares no autoloader — it feeds Psalm, not PHP — so the first test that
// needs a real OCP class must map it here. Integration tests bootstrap the container instead.
require_once __DIR__ . '/../vendor/autoload.php';
