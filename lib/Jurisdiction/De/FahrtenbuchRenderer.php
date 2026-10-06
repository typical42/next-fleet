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
use OCA\NextFleet\Jurisdiction\PrintedPage;

/**
 * A Fahrtenbuch as the browser prints it: one line per trip, voided ones included, the periods
 * Logbook Mode was on above the table and the requirement it claims to meet below it.
 *
 * Written in German whatever the reader's language, and not marked for translation: it is a
 * document for a German tax office, and the language of the proceedings there is German (section
 * 87 AO, https://www.gesetze-im-internet.de/ao_1977/__87.html). The words are part of the layout,
 * which is the country's (docs/features.md#logbook-mode).
 *
 * @psalm-import-type LateChange from LogbookReport
 */
class FahrtenbuchRenderer implements IReportRenderer {
	/**
	 * What each column is called, and so what a missing field is called on its line - here and on
	 * the mileage claim.
	 */
	public const WORDS = [
		'plate' => 'Kennzeichen',
		'started_at' => 'Datum',
		'started_at_off' => 'Zeitzone Abfahrt',
		'ended_at' => 'Ankunft',
		'ended_at_off' => 'Zeitzone Ankunft',
		'start_odo' => 'Km-Stand Beginn',
		'end_odo' => 'Km-Stand Ende',
		'distance' => 'Kilometer',
		'from_label' => 'Abfahrtsort',
		'to_label' => 'Reiseziel',
		'purpose' => 'Zweck',
		'partner' => 'Geschäftspartner',
		'category' => 'Art',
	];

	/** The mileage claim's terms; a commute as § 9 (1) 3 Nr. 4 EStG names it. */
	private const CATEGORIES = [
		Trip::BUSINESS => 'Geschäftlich',
		Trip::PRIVATE => 'Privat',
		Trip::COMMUTE => 'Wohnung – erste Tätigkeitsstätte',
	];

	/** The reader's time zone, which stamp() writes the server's instants in; render() sets it. */
	private \DateTimeZone $zone;

	public function render(LogbookReport $report): string {
		$this->zone = $report->zone;
		$vehicle = $report->vehicle;
		$name = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return '<!DOCTYPE html>'
			. '<html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Fahrtenbuch ' . PrintedPage::text($vehicle->getPlate()) . ' ' . $report->year . '</title>'
			. '<style>' . PrintedPage::LOGBOOK_STYLE . '</style></head><body>'
			. '<header><h1>Fahrtenbuch ' . $report->year . '</h1><dl>'
			. '<dt>Kennzeichen</dt><dd>' . PrintedPage::text($vehicle->getPlate()) . '</dd>'
			. ($name === '' ? '' : '<dt>Fahrzeug</dt><dd>' . PrintedPage::text($name) . '</dd>')
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

		$html .= '<p>Eingeschaltet – Änderungen und Annullierungen wurden protokolliert:</p><ul>';
		foreach ($report->periods as $period) {
			$html .= '<li>' . ($period['to'] === null
				? 'seit dem ' . $this->stamp($period['from'])
				: 'vom ' . $this->stamp($period['from']) . ' bis zum ' . $this->stamp($period['to']))
				. $this->plates($period['plates'] ?? []) . '</li>';
		}

		return $html . '</ul><p>Fahrten außerhalb dieser Zeiträume wurden ohne Änderungsprotokoll erfasst.</p></section>';
	}

	/**
	 * The plates a period was kept under; the header's is today's, and the receipts carry the old one.
	 *
	 * @param list<array{plate: ?string, from: int}> $plates
	 */
	private function plates(array $plates): string {
		$named = [];
		foreach ($plates as $i => $plate) {
			$named[] = ($i === 0 ? '' : 'ab dem ' . $this->stamp($plate['from']) . ' ')
				. ($plate['plate'] === null || $plate['plate'] === '' ? 'ohne Kennzeichen' : PrintedPage::text($plate['plate']));
		}

		return $named === [] ? '' : ', Kennzeichen ' . implode(', ', $named);
	}

	private function table(LogbookReport $report): string {
		$columns = ['Datum', 'Zeit', self::WORDS['start_odo'], self::WORDS['end_odo'], self::WORDS['distance'],
			self::WORDS['from_label'], self::WORDS['to_label'], self::WORDS['purpose'], self::WORDS['partner'],
			self::WORDS['category'], 'Erfasst',
			...($report->enteredBy === null ? [] : ['Eingetragen von']),
			'Vermerk'];
		$html = '<table><thead><tr>';
		foreach ($columns as $column) {
			$html .= '<th scope="col">' . $column . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ($report->trips === []) {
			return $html . '<tr><td colspan="' . count($columns) . '">Keine Fahrten in ' . $report->year . '.</td></tr></tbody></table>';
		}

		foreach ($report->trips as $line) {
			$html .= $this->line($line['trip'], $line['missing'], $line['late'], $line['unlogged'] ?? null, $report->enteredBy);
		}

		return $html . '</tbody></table>';
	}

	/**
	 * @param list<string> $missing
	 * @param list<LateChange> $late
	 * @param ?array<string, string> $enteredBy
	 */
	private function line(Trip $trip, array $missing, array $late, ?int $unlogged, ?array $enteredBy): string {
		$started = $trip->getStartedAt() + $trip->getStartedAtOff() * 60;
		$ended = $trip->getEndedAt() + $trip->getEndedAtOff() * 60;
		$sameDay = gmdate('Y-m-d', $started) === gmdate('Y-m-d', $ended);

		$cells = [
			['', gmdate('d.m.Y', $started)],
			['', gmdate('H:i', $started) . '–' . ($sameDay ? '' : gmdate('d.m.Y ', $ended)) . gmdate('H:i', $ended)],
			['number', $this->count($trip->getStartOdo())],
			['number', $this->count($trip->getEndOdo())],
			['number', $this->count($trip->kilometres())],
			['', PrintedPage::text($trip->getFromLabel())],
			['', PrintedPage::text($trip->getToLabel())],
			['', PrintedPage::text($trip->getPurpose())],
			['', PrintedPage::text($trip->getPartner())],
			['', PrintedPage::text(self::CATEGORIES[$trip->getCategory()] ?? $trip->getCategory())],
			// The server's instant, not the driver's: timeliness is the gap between the two
			// (docs/architecture.md#time). With its offset - a bare date beside `Datum` in the
			// trip's own offset can read as entered before driven.
			['', $this->stamp($trip->getCreatedAt())],
			...($enteredBy === null ? [] : [['', PrintedPage::text($enteredBy[$trip->getCreatedBy()] ?? $trip->getCreatedBy())]]),
			['note', $this->notes($trip, $missing, $late, $unlogged)],
		];

		return PrintedPage::row($trip->getDeletedAt() !== null, $cells);
	}

	/**
	 * @param list<string> $missing
	 * @param list<LateChange> $late
	 */
	private function notes(Trip $trip, array $missing, array $late, ?int $unlogged): string {
		$notes = [];
		// A void made late is noted with the late changes, and it is the one the line shows.
		$voidedLate = array_filter($late, static fn (array $change): bool => $change['change'] === 'voided'
			&& ($change['fields']['deleted_at'][1] ?? null) === $trip->getDeletedAt()) !== [];
		if ($trip->getDeletedAt() !== null && !$voidedLate) {
			$notes[] = 'Annulliert am ' . $this->stamp($trip->getDeletedAt());
		}
		if ($missing !== []) {
			$notes[] = 'Unvollständig, es fehlt: ' . PrintedPage::text(implode(', ', array_map(
				static fn (string $field): string => self::WORDS[$field] ?? $field,
				$missing,
			)));
		}
		if ($trip->getReconciled()) {
			$notes[] = 'Abgleich: Kilometer aus dem Zählerstand abgeleitet, nicht abgelesen';
		}
		foreach ($late as $change) {
			$notes[] = $this->lateChange($change);
		}
		if ($unlogged !== null) {
			$notes[] = 'Geändert ohne Protokoll am ' . $this->stamp($unlogged);
		}

		return implode('<br>', $notes);
	}

	/**
	 * One change made after the lock delay: when, and what each field said before it. The line
	 * already shows what it says now. A restore says since when the trip had been voided, because the
	 * line no longer does.
	 *
	 * @param LateChange $change
	 */
	private function lateChange(array $change): string {
		$voidedAt = $change['fields']['deleted_at'][0] ?? null;
		if ($change['change'] === 'voided') {
			return 'Nachträglich annulliert am ' . $this->stamp($change['at']);
		}
		if ($change['change'] === 'restored') {
			return 'Nachträglich wiederhergestellt am ' . $this->stamp($change['at'])
				. (is_int($voidedAt) ? '. Vorher: annulliert am ' . $this->stamp($voidedAt) : '');
		}

		$before = [];
		foreach ($change['fields'] as $field => [$value]) {
			$before[] = (self::WORDS[$field] ?? PrintedPage::text($field)) . ' ' . $this->before($field, $value, $change['offsets']);
		}

		return 'Nachträglich geändert am ' . $this->stamp($change['at']) . '. Vorher: ' . implode('; ', $before);
	}

	/**
	 * A value as it stood before a change, written the way its column writes it. A local time is
	 * read with the offset in force before the change, which need not be the line's own.
	 *
	 * @param array{started_at_off: int, ended_at_off: int} $offsets
	 */
	private function before(string $field, mixed $value, array $offsets): string {
		if ($value === null || $value === '') {
			return 'leer';
		}

		return match ($field) {
			'started_at' => $this->local((int)$value, $offsets['started_at_off']),
			'ended_at' => $this->local((int)$value, $offsets['ended_at_off']),
			'started_at_off', 'ended_at_off' => $this->offset((int)$value),
			'start_odo', 'end_odo', 'distance' => $this->count((int)$value),
			'category' => PrintedPage::text(self::CATEGORIES[$value] ?? (string)$value),
			default => match (true) {
				is_bool($value) => $value ? 'ja' : 'nein',
				is_int($value) => $this->count($value),
				default => '„' . PrintedPage::text(is_scalar($value) ? (string)$value : (string)json_encode($value)) . '“',
			},
		};
	}

	private function local(int $instant, int $offset): string {
		return gmdate('d.m.Y, H:i', $instant + $offset * 60);
	}

	private function offset(int $minutes): string {
		return sprintf('UTC%s%02d:%02d', $minutes < 0 ? '−' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
	}

	private function footer(LogbookReport $report): string {
		$html = '<footer>';
		if ($report->sourceUrl !== null) {
			$url = PrintedPage::text($report->sourceUrl);
			$html .= '<p>Anforderung: <a href="' . $url . '">' . $url . '</a></p>';
		}

		// docs/legal.md: said wherever the app speaks, and a printed logbook is where it matters.
		return $html . '<p>Erstellt mit NextFleet. Eine Hilfe beim Führen des Fahrtenbuchs, keine '
			. 'Zertifizierung; nicht rechtlich geprüft.</p></footer>';
	}

	/**
	 * A server instant, which carries no offset, in the reader's zone. The offset is written out:
	 * the trips beside it are in their own offsets, which need not be the reader's.
	 */
	private function stamp(int $instant): string {
		$at = (new \DateTimeImmutable('@' . $instant))->setTimezone($this->zone);

		return $at->format('d.m.Y, H:i') . ' ' . $this->offset(intdiv($at->getOffset(), 60));
	}

	private function count(?int $value): string {
		return $value === null ? '' : number_format($value, 0, ',', '.');
	}
}
