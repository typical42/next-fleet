<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's AI policy asks every contribution to say what an AI tool did. The README says it for
 * the app, the pull request template asks it of each change.
 */
class DisclosureTest extends TestCase {
	private const ROOT = __DIR__ . '/../..';
	private const POLICY = 'https://github.com/nextcloud/.github/blob/master/AI_POLICY.md';

	public function testTheReadmeSaysHowTheAppWasMade(): void {
		$readme = (string)file_get_contents(self::ROOT . '/README.md');

		$this->assertStringContainsString("\n## How this app was made\n", $readme);
		$this->assertStringContainsString('Claude Code', $readme);
	}

	public function testThePullRequestTemplateAsksForTheDisclosure(): void {
		$template = (string)file_get_contents(self::ROOT . '/.github/pull_request_template.md');

		$this->assertMatchesRegularExpression('/^- \[ \] .*AI tool/m', $template);
		$this->assertMatchesRegularExpression('/^- \[ \] .*`Assisted-by: AGENT_NAME:MODEL_VERSION`/m', $template);
		$this->assertStringContainsString(self::POLICY, $template);
	}

	public function testTheContributingGuideGivesTheRules(): void {
		$guide = (string)file_get_contents(self::ROOT . '/docs/contributing.md');

		$this->assertStringContainsString("\n## AI assistance\n", $guide);
		$this->assertStringContainsString('`Assisted-by: AGENT_NAME:MODEL_VERSION`', $guide);
		$this->assertStringContainsString(self::POLICY, $guide);
	}
}
