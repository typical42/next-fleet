<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Jurisdiction\IReportRenderer;
use OCA\NextFleet\Jurisdiction\LogbookReport;

/**
 * A Fahrtenbuch as the browser prints it: one line per trip, voided ones included, the periods
 * Logbook Mode was on above the table and the requirement it claims to meet below it.
 *
 * Written in German whatever the reader's language, and not marked for translation: it is a
 * document for a German tax office, and the language of the proceedings there is German (section
 * 87 AO, https://www.gesetze-im-internet.de/ao_1977/__87.html). The words are part of the layout,
 * which is the country's (docs/features.md#logbook-mode).
 */
class FahrtenbuchRenderer implements IReportRenderer {
	/** What each column is called, and so what a missing field is called on its line. */
	private const WORDS = [
		'plate' => 'Kennzeichen',
		'started_at' => 'Datum',
		'ended_at' => 'Ankunft',
		'start_odo' => 'Km-Stand Beginn',
		'end_odo' => 'Km-Stand Ende',
		'distance' => 'Kilometer',
		'from_label' => 'Abfahrtsort',
		'to_label' => 'Reiseziel',
		'purpose' => 'Zweck',
		'partner' => 'Geschäftspartner',
		'category' => 'Art',
	];

	private const CATEGORIES = [
		Trip::BUSINESS => 'Dienstlich',
		Trip::PRIVATE => 'Privat',
		Trip::COMMUTE => 'Wohnung – Arbeitsstätte',
	];

	/**
	 * Landscape, because twelve columns do not fit upright, and the header repeated on every sheet.
	 * Inline, because the page loads nothing (docs/security.md#hostile-content).
	 */
	private const STYLE = <<<'CSS'
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

	public function render(LogbookReport $report): string {
		$vehicle = $report->vehicle;
		$name = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return '<!DOCTYPE html>'
			. '<html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Fahrtenbuch ' . $this->text($vehicle->getPlate()) . ' ' . $report->year . '</title>'
			. '<style>' . self::STYLE . '</style></head><body>'
			. '<header><h1>Fahrtenbuch ' . $report->year . '</h1><dl>'
			. '<dt>Kennzeichen</dt><dd>' . $this->text($vehicle->getPlate()) . '</dd>'
			. ($name === '' ? '' : '<dt>Fahrzeug</dt><dd>' . $this->text($name) . '</dd>')
			. '</dl></header>'
			. $this->periods($report)
			. $this->table($report)
			. $this->footer($report)
			. '</body></html>';
	}

	/**
	 * When the mode was on, or that it was not (docs/adr/0003-logbook-mode-does-not-lock-the-past.md).
	 * A trip outside every period was recorded without an audit trail, and the page says that too
	 * rather than leaving an auditor to infer it.
	 */
	private function periods(LogbookReport $report): string {
		$html = '<section id="modus"><h2>Fahrtenbuchmodus</h2>';
		if ($report->periods === []) {
			return $html . '<p>Der Fahrtenbuchmodus war ' . $report->year . ' nicht eingeschaltet. '
				. 'Keine Fahrt dieses Jahres ist gegen unbemerkte Änderungen gesichert.</p></section>';
		}

		$html .= '<p>Eingeschaltet – Änderungen und Stornierungen wurden protokolliert:</p><ul>';
		foreach ($report->periods as $period) {
			$html .= '<li>' . ($period['to'] === null
				? 'seit dem ' . $this->utc($period['from'])
				: 'vom ' . $this->utc($period['from']) . ' bis zum ' . $this->utc($period['to'])) . '</li>';
		}

		return $html . '</ul><p>Fahrten außerhalb dieser Zeiträume wurden ohne Änderungsprotokoll erfasst.</p></section>';
	}

	private function table(LogbookReport $report): string {
		$columns = ['Datum', 'Zeit', self::WORDS['start_odo'], self::WORDS['end_odo'], self::WORDS['distance'],
			self::WORDS['from_label'], self::WORDS['to_label'], self::WORDS['purpose'], self::WORDS['partner'],
			self::WORDS['category'], 'Erfasst', 'Vermerk'];
		$html = '<table><thead><tr>';
		foreach ($columns as $column) {
			$html .= '<th scope="col">' . $column . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ($report->trips === []) {
			return $html . '<tr><td colspan="' . count($columns) . '">Keine Fahrten in ' . $report->year . '.</td></tr></tbody></table>';
		}

		foreach ($report->trips as $line) {
			$html .= $this->line($line['trip'], $line['missing']);
		}

		return $html . '</tbody></table>';
	}

	/** @param list<string> $missing */
	private function line(Trip $trip, array $missing): string {
		$started = $trip->getStartedAt() + $trip->getStartedAtOff() * 60;
		$ended = $trip->getEndedAt() + $trip->getEndedAtOff() * 60;
		$sameDay = gmdate('Y-m-d', $started) === gmdate('Y-m-d', $ended);

		$cells = [
			['', gmdate('d.m.Y', $started)],
			['', gmdate('H:i', $started) . '–' . ($sameDay ? '' : gmdate('d.m.Y ', $ended)) . gmdate('H:i', $ended)],
			['number', $this->count($trip->getStartOdo())],
			['number', $this->count($trip->getEndOdo())],
			['number', $this->count($this->kilometres($trip))],
			['', $this->text($trip->getFromLabel())],
			['', $this->text($trip->getToLabel())],
			['', $this->text($trip->getPurpose())],
			['', $this->text($trip->getPartner())],
			['', $this->text(self::CATEGORIES[$trip->getCategory()] ?? $trip->getCategory())],
			// The server's instant, not the driver's: timeliness is the gap between the two
			// (docs/architecture.md#time).
			['', gmdate('d.m.Y', $trip->getCreatedAt())],
			['note', $this->notes($trip, $missing)],
		];

		$html = '<tr' . ($trip->getDeletedAt() === null ? '' : ' class="voided"') . '>';
		foreach ($cells as [$class, $content]) {
			$html .= '<td' . ($class === '' ? '' : ' class="' . $class . '"') . '>' . $content . '</td>';
		}

		return $html . '</tr>';
	}

	/**
	 * The kilometres the line accounts for: what the driver stated, or the difference between the
	 * two counters they read. Nothing where neither says it - a figure worked out from the odometer
	 * chain is the core's, and it did not hand one over.
	 */
	private function kilometres(Trip $trip): ?int {
		if ($trip->getDistance() !== null) {
			return $trip->getDistance();
		}
		if ($trip->getStartOdo() !== null && $trip->getEndOdo() !== null) {
			return $trip->getEndOdo() - $trip->getStartOdo();
		}

		return null;
	}

	/** @param list<string> $missing */
	private function notes(Trip $trip, array $missing): string {
		$notes = [];
		if ($trip->getDeletedAt() !== null) {
			$notes[] = 'Storniert am ' . gmdate('d.m.Y', $trip->getDeletedAt());
		}
		if ($missing !== []) {
			$notes[] = 'Unvollständig, es fehlt: ' . $this->text(implode(', ', array_map(
				static fn (string $field): string => self::WORDS[$field] ?? $field,
				$missing,
			)));
		}
		if ($trip->getReconciled()) {
			$notes[] = 'Abgleich: Kilometer aus dem Zählerstand abgeleitet, nicht abgelesen';
		}

		return implode('<br>', $notes);
	}

	private function footer(LogbookReport $report): string {
		$html = '<footer>';
		if ($report->sourceUrl !== null) {
			$url = $this->text($report->sourceUrl);
			$html .= '<p>Anforderung: <a href="' . $url . '">' . $url . '</a></p>';
		}

		// docs/legal.md: said wherever the app speaks, and a printed logbook is where it matters.
		return $html . '<p>Erstellt mit NextFleet. Eine Hilfe beim Führen des Fahrtenbuchs, keine '
			. 'Zertifizierung; nicht rechtlich geprüft.</p></footer>';
	}

	/** A server instant, which carries no offset, so it says it is UTC. */
	private function utc(int $instant): string {
		return gmdate('d.m.Y, H:i', $instant) . ' UTC';
	}

	private function count(?int $value): string {
		return $value === null ? '' : number_format($value, 0, ',', '.');
	}

	private function text(?string $value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
