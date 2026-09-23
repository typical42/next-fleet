<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\Generic;

use OCA\NextFleet\Db\Trip;
use OCA\NextFleet\Jurisdiction\IReportRenderer;
use OCA\NextFleet\Jurisdiction\Jurisdictions;
use OCA\NextFleet\Jurisdiction\LogbookReport;
use OCP\IL10N;

/**
 * A plain trip listing for a country nobody has written: what was entered, and no requirement
 * laid over it, because stating one would be inventing that country's law.
 *
 * In the reader's language, unlike the Fahrtenbuch: no authority is named, so none sets the
 * language (docs/ui.md#languages).
 */
class LogbookRenderer implements IReportRenderer {
	/**
	 * The Fahrtenbuch's sheet, and for its reasons: ten columns do not fit upright, and the page
	 * loads nothing (docs/security.md#hostile-content).
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

	public function __construct(
		private IL10N $l,
	) {
	}

	public function render(LogbookReport $report): string {
		$vehicle = $report->vehicle;
		$name = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return '<!DOCTYPE html>'
			. '<html lang="' . $this->text($this->l->getLanguageCode()) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			// TRANSLATORS: the printed logbook's title: %1$s is the plate, %2$s the year
			. '<title>' . $this->text($this->l->t('Logbook %1$s %2$s', [(string)$vehicle->getPlate(), (string)$report->year])) . '</title>'
			. '<style>' . self::STYLE . '</style></head><body>'
			. '<header><h1>' . $this->text($this->l->t('Logbook %1$s', [(string)$report->year])) . '</h1><dl>'
			. '<dt>' . $this->text($this->l->t('Plate')) . '</dt><dd>' . $this->text($vehicle->getPlate()) . '</dd>'
			. ($name === '' ? '' : '<dt>' . $this->text($this->l->t('Vehicle')) . '</dt><dd>' . $this->text($name) . '</dd>')
			. '</dl></header>'
			. $this->split($report)
			. $this->table($report)
			// docs/legal.md: said wherever the app speaks, and a printed logbook is where it matters.
			. '<footer><p>' . $this->text($this->l->t('Made with NextFleet. An aid for keeping a logbook, not a certification.')) . '</p></footer>'
			. '</body></html>';
	}

	/**
	 * The distance per category over the year. A voided trip drove nothing. A trip whose distance is
	 * not stated adds nothing and is counted aloud, so the sum does not pass for the whole; a
	 * category with only such trips is not stated rather than zero.
	 */
	private function split(LogbookReport $report): string {
		/** @var array<string, ?int> $sums */
		$sums = [Trip::BUSINESS => null, Trip::PRIVATE => null, Trip::COMMUTE => null];
		$trips = [Trip::BUSINESS => 0, Trip::PRIVATE => 0, Trip::COMMUTE => 0];
		$unstated = 0;
		foreach ($report->trips as ['trip' => $trip]) {
			$category = $trip->getCategory();
			if ($trip->getDeletedAt() !== null || !isset($trips[$category])) {
				continue;
			}
			$trips[$category]++;
			if ($trip->kilometres() === null) {
				$unstated++;
			} else {
				$sums[$category] = ($sums[$category] ?? 0) + $trip->kilometres();
			}
		}

		$html = '<section id="split"><h2>' . $this->text($this->unit($report) === 'h'
			? $this->l->t('Hours by category')
			: $this->l->t('Kilometres by category')) . '</h2><dl>';
		foreach ($sums as $category => $sum) {
			// No trip is a true zero; trips with no distance stated are not.
			$sum ??= $trips[$category] === 0 ? 0 : null;
			$html .= '<dt>' . $this->text($this->category($category)) . '</dt><dd>'
				. ($sum === null ? $this->text($this->l->t('Not stated')) : $this->count($sum)) . '</dd>';
		}
		$html .= '</dl>';
		if ($unstated > 0) {
			$html .= '<p>' . $this->text($this->l->t('Trips with no distance stated, not counted above: %1$s', [(string)$unstated])) . '</p>';
		}

		return $html . '</section>';
	}

	/** What the vehicle's counter counts, `km` or `h`: a trip's distance is in the same unit. */
	private function unit(LogbookReport $report): string {
		return $report->vehicle->getOdoUnit();
	}

	private function table(LogbookReport $report): string {
		$columns = [$this->l->t('Date'), $this->l->t('Time'),
			// TRANSLATORS: where a trip set off
			$this->l->t('From'),
			// TRANSLATORS: where a trip went
			$this->l->t('To'),
			$this->l->t('Purpose'), $this->l->t('Odometer start'), $this->l->t('Odometer end'),
			$this->unit($report) === 'h' ? $this->l->t('Hours') : $this->l->t('Kilometres'),
			$this->l->t('Category'), $this->l->t('Note')];
		$html = '<table><thead><tr>';
		foreach ($columns as $column) {
			$html .= '<th scope="col">' . $this->text($column) . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ($report->trips === []) {
			return $html . '<tr><td colspan="' . count($columns) . '">'
				. $this->text($this->l->t('No trips in %1$s.', [(string)$report->year])) . '</td></tr></tbody></table>';
		}

		foreach ($report->trips as $line) {
			$html .= $this->line($line['trip']);
		}

		return $html . '</tbody></table>';
	}

	private function line(Trip $trip): string {
		$started = $this->local($trip->getStartedAt(), $trip->getStartedAtOff());
		$ended = $this->local($trip->getEndedAt(), $trip->getEndedAtOff());
		$sameDay = $this->date($started) === $this->date($ended);

		$cells = [
			['', $this->text($this->date($started))],
			['', $this->text($this->time($started) . '–' . ($sameDay ? '' : $this->date($ended) . ' ') . $this->time($ended))],
			['', $this->text($trip->getFromLabel())],
			['', $this->text($trip->getToLabel())],
			['', $this->text($trip->getPurpose())],
			['number', $this->count($trip->getStartOdo())],
			['number', $this->count($trip->getEndOdo())],
			['number', $this->count($trip->kilometres())],
			['', $this->text($this->category($trip->getCategory()))],
			['note', $this->notes($trip)],
		];

		$html = '<tr' . ($trip->getDeletedAt() === null ? '' : ' class="voided"') . '>';
		foreach ($cells as [$class, $content]) {
			$html .= '<td' . ($class === '' ? '' : ' class="' . $class . '"') . '>' . $content . '</td>';
		}

		return $html . '</tr>';
	}

	private function notes(Trip $trip): string {
		$notes = [];
		if ($trip->getDeletedAt() !== null) {
			// The server's instant, which carries no offset, so it says it is UTC.
			$at = $this->local($trip->getDeletedAt(), 0);
			$notes[] = $this->l->t('Voided on %1$s, %2$s UTC', [$this->date($at), $this->time($at)]);
		}
		if ($trip->getReconciled()) {
			$notes[] = $this->l->t('Distance derived from the odometer, not read off it');
		}

		return implode('<br>', array_map($this->text(...), $notes));
	}

	private function category(string $category): string {
		return match ($category) {
			Trip::BUSINESS => $this->l->t('Business'),
			Trip::PRIVATE => $this->l->t('Private'),
			Trip::COMMUTE => $this->l->t('Commute'),
			default => $category,
		};
	}

	/** Nextcloud's formatter reads a mutable `DateTime` in its own zone, and nothing else as one. */
	private function local(int $at, int $off): \DateTime {
		return \DateTime::createFromImmutable(Jurisdictions::localTime($at, $off));
	}

	private function date(\DateTime $at): string {
		return (string)$this->l->l('date', $at, ['width' => 'medium']);
	}

	private function time(\DateTime $at): string {
		return (string)$this->l->l('time', $at, ['width' => 'short']);
	}

	/**
	 * Ungrouped: `IL10N` formats dates and no numbers, and plain digits read the same in every
	 * locale, where `1.234` does not (docs/ui.md#languages).
	 */
	private function count(?int $value): string {
		return $value === null ? '' : (string)$value;
	}

	private function text(?string $value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
