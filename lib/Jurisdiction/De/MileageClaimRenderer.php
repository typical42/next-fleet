<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Jurisdiction\De;

use OCA\NextFleet\Jurisdiction\IClaimRenderer;
use OCA\NextFleet\Jurisdiction\MileageClaim;
use OCA\NextFleet\Jurisdiction\PrintedPage;

/**
 * The business kilometres of one year at the flat rate, as the browser prints them: one line per
 * trip, the sum beneath, and the statute the rate comes from. The trips are the reader's own, and
 * the page says so, since on a shared car the Fahrtenbuch holds more.
 *
 * Written in German whatever the reader's language, for the reason `FahrtenbuchRenderer` gives.
 */
class MileageClaimRenderer implements IClaimRenderer {
	private const UNSTATED = 'nicht angegeben';
	private const INCOMPLETE = 'incomplete';
	private const DERIVED = 'derived';

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
			. '<title>Fahrtkosten ' . PrintedPage::text($vehicle->getPlate()) . ' ' . $claim->year . '</title>'
			. '<style>' . self::STYLE . '</style></head><body>'
			. '<header><h1>Fahrtkosten für geschäftliche Fahrten ' . $claim->year . '</h1><dl>'
			. '<dt>Kennzeichen</dt><dd>' . PrintedPage::text($vehicle->getPlate()) . '</dd>'
			. ($name === '' ? '' : '<dt>Fahrzeug</dt><dd>' . PrintedPage::text($name) . '</dd>')
			. '</dl><p>Nur Fahrten, die Sie eingetragen haben.</p></header>'
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
			return $html . '<tr><td colspan="' . count($columns) . '">Keine geschäftlichen Fahrten in ' . $claim->year . ', die Sie eingetragen haben.</td></tr></tbody></table>';
		}

		foreach ($claim->lines as $line) {
			$trip = $line['trip'];
			$amount = $line['amount'] === null ? self::UNSTATED : $this->money($line['amount']);
			$amount = match (self::leftOut($line)) {
				null => $amount,
				// Brackets for an amount the sum leaves out, as an accountant would mark it. A
				// missing field is named, since the plate and the counters have no column here.
				self::DERIVED => self::bracketed($line, $amount) . ' <br>abgeleitet',
				self::INCOMPLETE => self::bracketed($line, $amount) . ' <br>fehlt: '
					. implode(', ', array_map(static fn (string $field): string => FahrtenbuchRenderer::WORDS[$field] ?? $field, $line['missing'] ?? [])),
			};
			$cells = [
				['', gmdate('d.m.Y', $trip->getStartedAt() + $trip->getStartedAtOff() * 60)],
				['', PrintedPage::text($trip->getFromLabel())],
				['', PrintedPage::text($trip->getToLabel())],
				['', PrintedPage::text($trip->getPurpose())],
				['', PrintedPage::text($trip->getPartner())],
				['number', $line['kilometres'] === null ? self::UNSTATED : $this->count($line['kilometres'])],
				['number', $line['rate'] === null ? self::UNSTATED : $this->rate($line['rate'])],
				['number', $amount],
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
	 * What the sum leaves out, so nobody reads it as the year's: the trips the Finanzamt would not
	 * accept, the trips it could not value, and the commutes, which are a different deduction (the
	 * Entfernungspauschale). Then who may claim the flat rate at all.
	 */
	private function notes(MileageClaim $claim): string {
		$incomplete = 0;
		$derived = 0;
		$bracketed = false;
		$unvalued = 0;
		foreach ($claim->lines as $line) {
			$reason = self::leftOut($line);
			$incomplete += (int)($reason === self::INCOMPLETE);
			$derived += (int)($reason === self::DERIVED);
			$bracketed = $bracketed || ($reason !== null && $line['amount'] !== null);
			$unvalued += (int)($reason === null && $line['amount'] === null);
		}
		$html = '<section id="hinweise">';
		if ($bracketed) {
			$html .= '<p>Beträge in Klammern sind in der Summe nicht enthalten.</p>';
		}
		if ($incomplete > 0) {
			$html .= '<p>' . ($incomplete === 1 ? 'Einer Fahrt' : $incomplete . ' Fahrten')
				. ' fehlen Angaben, die das Finanzamt für eine geschäftliche Fahrt verlangt; was fehlt, steht beim Betrag.'
				. ($incomplete === 1 ? ' Ergänzt zählt sie mit.' : ' Ergänzt zählen sie mit.') . '</p>';
		}
		if ($derived > 0) {
			$html .= '<p>' . ($derived === 1 ? '1 Fahrt ist' : $derived . ' Fahrten sind') . ' abgeleitet: aus dem Kilometerstand erschlossen, um eine Lücke'
				. ' zu schließen, und nicht als gefahren eingetragen. Als geschäftliche Fahrt belegt das nichts.</p>';
		}
		if ($unvalued > 0) {
			$html .= '<p>' . ($unvalued === 1 ? '1 Fahrt ohne Betrag ist' : $unvalued . ' Fahrten ohne Betrag sind')
				. ' in der Summe nicht enthalten: für ihren Tag ist kein Satz oder für sie keine Strecke angegeben.</p>';
		}

		return $html . '<p>Fahrten zwischen Wohnung und erster Tätigkeitsstätte sind nicht enthalten.</p>'
			. '<p>Die Kilometerpauschale gilt nur für Fahrzeuge, die nicht zum Betriebsvermögen gehören.</p></section>';
	}

	/**
	 * Why the sum leaves a line out, or null when it counts. Derived wins over incomplete:
	 * completing a Reconciliation Trip would not make it count, so it must not be promised.
	 *
	 * @param array{missing?: list<string>, reconciled?: bool, ...} $line
	 */
	private static function leftOut(array $line): ?string {
		return match (true) {
			$line['reconciled'] ?? false => self::DERIVED,
			($line['missing'] ?? []) !== [] => self::INCOMPLETE,
			default => null,
		};
	}

	/** @param array{amount: ?int, ...} $line */
	private static function bracketed(array $line, string $amount): string {
		return $line['amount'] === null ? $amount : '(' . $amount . ')';
	}

	private function footer(MileageClaim $claim): string {
		$url = PrintedPage::text($claim->sourceUrl);

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
}
