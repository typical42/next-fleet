<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction;

/**
 * What the printed pages share, so every country's escapes and lays out alike.
 */
final class PrintedPage {
	/**
	 * A logbook's sheet: landscape, because ten and more columns do not fit upright, and the header
	 * repeated on every sheet. Inline, because the page loads nothing
	 * (docs/security.md#hostile-content).
	 */
	public const LOGBOOK_STYLE = <<<'CSS'
		@page { size: A4 landscape; margin: 12mm; }
		body { font: 9pt/1.35 system-ui, sans-serif; color: #000; background: #fff; margin: 0 auto; max-width: 297mm; padding: 8mm; }
		h1 { font-size: 16pt; margin: 0 0 2mm; }
		h2 { font-size: 11pt; margin: 5mm 0 1mm; }
		dl { display: grid; grid-template-columns: max-content 1fr; gap: 0 4mm; margin: 0; }
		dd { margin: 0; }
		table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
		thead { display: table-header-group; }
		tr { break-inside: avoid; }
		th, td { border: 0.5pt solid #555; padding: 1mm 1.5mm; text-align: left; vertical-align: top; }
		th { background: #eee; }
		.number { text-align: right; white-space: nowrap; }
		.voided td:not(.note) { text-decoration: line-through; color: #555; }
		footer { margin-top: 6mm; font-size: 8pt; }
		@media print { body { padding: 0; max-width: none; } }
		CSS;

	public static function text(?string $value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}

	/**
	 * @param list<array{string, string}> $cells each cell's class, or '', and its HTML
	 */
	public static function row(bool $voided, array $cells): string {
		$html = '<tr' . ($voided ? ' class="voided"' : '') . '>';
		foreach ($cells as [$class, $content]) {
			$html .= '<td' . ($class === '' ? '' : ' class="' . $class . '"') . '>' . $content . '</td>';
		}

		return $html . '</tr>';
	}
}
