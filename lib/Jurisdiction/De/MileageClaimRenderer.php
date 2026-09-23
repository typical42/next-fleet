<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\MileageClaim;

/**
 * The business kilometres of one year at the flat rate, as the browser prints them: one line per
 * trip, the sum beneath, and the statute the rate comes from.
 *
 * Written in German whatever the reader's language, for the reason `FahrtenbuchRenderer` gives.
 */
class MileageClaimRenderer implements IClaimRenderer {
	private const UNSTATED = 'nicht angegeben';

	/** Upright, since eight columns fit; inline, because the page loads nothing. */
	private const STYLE = <<<'CSS'
		@page { size: A4; margin: 12mm; }
		body { font: 9pt/1.35 system-ui, sans-serif; color: #000; background: #fff; margin: 0 auto; max-width: 210mm; padding: 8mm; }
		h1 { font-size: 16pt; margin: 0 0 2mm; }
		dl { display: grid; grid-template-columns: max-content 1fr; gap: 0 4mm; margin: 0; }
		dd { margin: 0; }
		table { width: 100%; border-collapse: collapse; margin-top: 4mm; }
		thead { display: table-header-group; }
		tr { break-inside: avoid; }
		th, td { border: 0.5pt solid #555; padding: 1mm 1.5mm; text-align: left; vertical-align: top; }
		thead th, tfoot th, tfoot td { background: #eee; }
		tfoot th, tfoot td { font-weight: bold; }
		.number { text-align: right; white-space: nowrap; }
		footer { margin-top: 6mm; font-size: 8pt; }
		@media print { body { padding: 0; max-width: none; } }
		CSS;

	public function render(MileageClaim $claim): string {
		$vehicle = $claim->vehicle;
		$name = trim(($vehicle->getManufacturer() ?? '') . ' ' . ($vehicle->getModel() ?? ''));

		return '<!DOCTYPE html>'
			. '<html lang="de"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<title>Fahrtkosten ' . $this->text($vehicle->getPlate()) . ' ' . $claim->year . '</title>'
			. '<style>' . self::STYLE . '</style></head><body>'
			. '<header><h1>Fahrtkosten für Dienstfahrten ' . $claim->year . '</h1><dl>'
			. '<dt>Kennzeichen</dt><dd>' . $this->text($vehicle->getPlate()) . '</dd>'
			. ($name === '' ? '' : '<dt>Fahrzeug</dt><dd>' . $this->text($name) . '</dd>')
			. '</dl></header>'
			. $this->table($claim)
			. $this->notes($claim)
			. $this->footer($claim)
			. '</body></html>';
	}

	private function table(MileageClaim $claim): string {
		$columns = [['', 'Datum'], ['', 'Abfahrtsort'], ['', 'Reiseziel'], ['', 'Zweck'], ['', 'Geschäftspartner'],
			['number', 'Kilometer'], ['number', 'Satz je km'], ['number', 'Betrag']];
		$html = '<table><thead><tr>';
		foreach ($columns as [$class, $column]) {
			$html .= '<th scope="col"' . $this->class($class) . '>' . $column . '</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ($claim->lines === []) {
			return $html . '<tr><td colspan="' . count($columns) . '">Keine Dienstfahrten in ' . $claim->year . '.</td></tr></tbody></table>';
		}

		foreach ($claim->lines as $line) {
			$trip = $line['trip'];
			$cells = [
				['', gmdate('d.m.Y', $trip->getStartedAt() + $trip->getStartedAtOff() * 60)],
				['', $this->text($trip->getFromLabel())],
				['', $this->text($trip->getToLabel())],
				['', $this->text($trip->getPurpose())],
				['', $this->text($trip->getPartner())],
				['number', $line['kilometres'] === null ? self::UNSTATED : $this->count($line['kilometres'])],
				['number', $line['rate'] === null ? self::UNSTATED : $this->rate($line['rate'])],
				['number', $line['amount'] === null ? self::UNSTATED : $this->money($line['amount'])],
			];
			$html .= '<tr>';
			foreach ($cells as [$class, $content]) {
				$html .= '<td' . $this->class($class) . '>' . $content . '</td>';
			}
			$html .= '</tr>';
		}

		return $html . '</tbody><tfoot><tr><th scope="row" colspan="5">Summe</th>'
			. '<td class="number">' . ($claim->kilometres === null ? '' : $this->count($claim->kilometres)) . '</td><td></td>'
			. '<td class="number">' . ($claim->total === null ? self::UNSTATED : $this->money($claim->total)) . '</td>'
			. '</tr></tfoot></table>';
	}

	/**
	 * What the sum leaves out, so nobody reads it as the year's: the trips it could not value, and
	 * the commutes, which are a different deduction (the Entfernungspauschale).
	 */
	private function notes(MileageClaim $claim): string {
		$unvalued = count(array_filter($claim->lines, static fn (array $line): bool => $line['amount'] === null));
		$html = '<section id="hinweise">';
		if ($unvalued > 0) {
			$html .= '<p>' . ($unvalued === 1 ? '1 Fahrt ohne Betrag ist' : $unvalued . ' Fahrten ohne Betrag sind')
				. ' in der Summe nicht enthalten: für ihren Tag ist kein Satz oder für sie keine Strecke angegeben.</p>';
		}

		return $html . '<p>Fahrten zwischen Wohnung und erster Tätigkeitsstätte sind nicht enthalten.</p></section>';
	}

	private function footer(MileageClaim $claim): string {
		$url = $this->text($claim->sourceUrl);

		// docs/legal.md, as on the Fahrtenbuch.
		return '<footer><p>Satz: <a href="' . $url . '">' . $url . '</a></p>'
			. '<p>Erstellt mit NextFleet. Eine Rechenhilfe, keine Steuerberatung; nicht rechtlich geprüft.</p></footer>';
	}

	/** Tenths of a cent as euros per kilometre, with the third decimal only where there is one. */
	private function rate(int $tenths): string {
		return number_format($tenths / 1000, $tenths % 10 === 0 ? 2 : 3, ',', '.') . ' €';
	}

	private function money(int $cents): string {
		return number_format($cents / 100, 2, ',', '.') . ' €';
	}

	private function count(int $value): string {
		return number_format($value, 0, ',', '.');
	}

	private function class(string $class): string {
		return $class === '' ? '' : ' class="' . $class . '"';
	}

	private function text(?string $value): string {
		return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
	}
}
