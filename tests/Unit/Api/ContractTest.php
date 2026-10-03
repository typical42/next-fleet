<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

/**
 * The OCS API v1 only grows (docs/api.md#what-v1-promises). `openapi.json` must keep every promise
 * `tests/Api/v1-baseline.json` made; the doctored copies below prove each kind of broken promise
 * is caught.
 */
class ContractTest extends TestCase {
	private const ROOT = __DIR__ . '/../../..';
	private const VEHICLES = '/ocs/v2.php/apps/nextfleet/api/v1/vehicles';
	private const SYNC = '/ocs/v2.php/apps/nextfleet/api/v1/sync';

	public function testTheDocumentKeepsEveryPromiseOfTheBaseline(): void {
		$this->assertSame([], Contract::breaks($this->baseline(), $this->load('openapi.json')));
	}

	public function testARemovedPathBreaksIt(): void {
		$offered = $this->baseline();
		unset($offered['paths'][self::VEHICLES]);

		$this->assertContains('GET ' . self::VEHICLES . ': gone', Contract::breaks($this->baseline(), $offered));
	}

	public function testARemovedMethodBreaksIt(): void {
		$offered = $this->baseline();
		unset($offered['paths'][self::VEHICLES]['post']);

		$this->assertSame(['POST ' . self::VEHICLES . ': gone'], Contract::breaks($this->baseline(), $offered));
	}

	public function testARemovedResponseFieldBreaksEveryAnswerThatHadIt(): void {
		$offered = $this->baseline();
		unset($offered['components']['schemas']['Vehicle']['properties']['plate']);

		$breaks = Contract::breaks($this->baseline(), $offered);

		$this->assertContains('GET ' . self::VEHICLES . ' 200: ocs.data[].plate gone', $breaks);
		$this->assertContains('POST ' . self::VEHICLES . ' 201: ocs.data.plate gone', $breaks);
	}

	/** Sync's rows sit four schemas down, one of them behind a nullable `allOf`. */
	public function testAFieldGoneDeepInAnAnswerBreaksIt(): void {
		$offered = $this->baseline();
		unset($offered['components']['schemas']['Booking']['properties']['starts_at']);

		$this->assertContains(
			'GET ' . self::SYNC . ' 200: ocs.data.changes.bookings[].row.starts_at gone',
			Contract::breaks($this->baseline(), $offered),
		);
	}

	public function testAResponseFieldOfAnotherTypeBreaksIt(): void {
		$offered = $this->baseline();
		$offered['components']['schemas']['Vehicle']['properties']['odo_value'] = ['type' => 'string'];

		$this->assertContains(
			'GET ' . self::VEHICLES . ' 200: ocs.data[].odo_value was integer/int64, is string',
			Contract::breaks($this->baseline(), $offered),
		);
	}

	public function testAResponseFieldThatMayNowBeNullBreaksIt(): void {
		$offered = $this->baseline();
		$offered['components']['schemas']['Vehicle']['properties']['uuid']['nullable'] = true;

		$this->assertContains('GET ' . self::VEHICLES . ' 200: ocs.data[].uuid may now be null', Contract::breaks($this->baseline(), $offered));
	}

	public function testAResponseFieldThatMayNowBeMissingBreaksIt(): void {
		$offered = $this->baseline();
		$required = &$offered['components']['schemas']['Vehicle']['required'];
		$required = array_values(array_diff($required, ['uuid']));

		$this->assertContains('GET ' . self::VEHICLES . ' 200: ocs.data[].uuid may now be missing', Contract::breaks($this->baseline(), $offered));
	}

	public function testARequiredBodyFieldBreaksIt(): void {
		$offered = $this->baseline();
		$body = &$offered['paths'][self::VEHICLES]['post']['requestBody']['content']['application/json']['schema'];
		$body['properties']['fleet'] = ['type' => 'string'];
		$body['required'] = ['fleet', 'plate'];

		$breaks = Contract::breaks($this->baseline(), $offered);

		$this->assertContains('POST ' . self::VEHICLES . ' body: fleet newly required', $breaks);
		$this->assertContains('POST ' . self::VEHICLES . ' body: plate newly required', $breaks);
	}

	public function testARequiredParameterBreaksIt(): void {
		$offered = $this->baseline();
		$offered['paths'][self::VEHICLES]['get']['parameters'][] = ['name' => 'fleet', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']];
		$offered['paths'][self::SYNC]['get']['parameters'][1]['required'] = true;

		$breaks = Contract::breaks($this->baseline(), $offered);

		$this->assertContains('GET ' . self::VEHICLES . ' query fleet: newly required', $breaks);
		$this->assertContains('GET ' . self::SYNC . ' query limit: newly required', $breaks);
	}

	public function testARequiredBodyBreaksIt(): void {
		$offered = $this->baseline();
		$offered['paths'][self::VEHICLES]['post']['requestBody']['required'] = true;

		$this->assertSame(['POST ' . self::VEHICLES . ' body: newly required'], Contract::breaks($this->baseline(), $offered));
	}

	public function testARequestFieldOfAnotherTypeBreaksIt(): void {
		$offered = $this->baseline();
		$body = &$offered['paths'][self::VEHICLES]['post']['requestBody']['content']['application/json']['schema'];
		$body['properties']['tank_ml'] = ['type' => 'string', 'nullable' => true];
		$body['properties']['plate']['nullable'] = false;
		$offered['paths'][self::SYNC]['get']['parameters'][1]['schema'] = ['type' => 'string'];

		$breaks = Contract::breaks($this->baseline(), $offered);

		$this->assertContains('POST ' . self::VEHICLES . ' body: tank_ml was integer/int64, is string', $breaks);
		$this->assertContains('POST ' . self::VEHICLES . ' body: plate no longer takes null', $breaks);
		$this->assertContains('GET ' . self::SYNC . ' query limit: was integer/int64, is string', $breaks);
	}

	public function testASuccessThatIsNoLongerAnsweredBreaksIt(): void {
		$offered = $this->baseline();
		$offered['paths'][self::VEHICLES]['post']['responses']['200'] = $offered['paths'][self::VEHICLES]['post']['responses']['201'];
		unset($offered['paths'][self::VEHICLES]['post']['responses']['201'], $offered['paths'][self::VEHICLES]['post']['responses']['412']);

		$this->assertSame(['POST ' . self::VEHICLES . ' 201: gone'], Contract::breaks($this->baseline(), $offered));
	}

	public function testAMediaTypeNoLongerTakenOrAnsweredBreaksIt(): void {
		$offered = $this->baseline();
		$post = &$offered['paths'][self::VEHICLES]['post'];
		$post['requestBody']['content'] = ['application/xml' => $post['requestBody']['content']['application/json']];
		$post['responses']['201']['content'] = ['application/xml' => $post['responses']['201']['content']['application/json']];

		$this->assertSame([
			'POST ' . self::VEHICLES . ' body application/json: gone',
			'POST ' . self::VEHICLES . ' 201 application/json: gone',
		], Contract::breaks($this->baseline(), $offered));
	}

	/** A schema that holds itself, behind the nullable `allOf` the extractor writes, still ends. */
	public function testASchemaThatHoldsItselfIsWalkedOnce(): void {
		$promised = $this->baseline();
		$promised['components']['schemas']['Vehicle']['properties']['twin'] = ['nullable' => true, 'allOf' => [['$ref' => '#/components/schemas/Vehicle']]];
		$offered = $promised;
		unset($offered['components']['schemas']['Vehicle']['properties']['vin']);

		$this->assertContains('GET ' . self::VEHICLES . ' 200: ocs.data[].vin gone', Contract::breaks($promised, $offered));
	}

	public function testAdditionsKeepEveryPromise(): void {
		$offered = $this->baseline();
		$offered['paths'][self::VEHICLES . '/{uuid}/tyres'] = $offered['paths'][self::VEHICLES];
		$offered['paths'][self::VEHICLES]['get']['parameters'][] = ['name' => 'fleet', 'in' => 'query', 'schema' => ['type' => 'string']];
		$offered['paths'][self::VEHICLES]['post']['requestBody']['content']['application/json']['schema']['properties']['fleet'] = ['type' => 'string'];
		$offered['paths'][self::VEHICLES]['post']['responses']['429'] = $offered['paths'][self::VEHICLES]['post']['responses']['401'];
		$offered['components']['schemas']['Vehicle']['properties']['fleet'] = ['type' => 'string'];
		$offered['components']['schemas']['Vehicle']['required'][] = 'fleet';
		$offered['components']['schemas']['Tyre'] = ['type' => 'object'];

		$this->assertSame([], Contract::breaks($this->baseline(), $offered));
	}

	public function testARequestThatTakesMoreKeepsEveryPromise(): void {
		$offered = $this->baseline();
		$body = &$offered['paths'][self::VEHICLES]['post']['requestBody']['content']['application/json']['schema'];
		unset($body['properties']['color']);
		$body['properties']['tank_ml']['nullable'] = true;
		$body['properties']['plate'] = ['nullable' => true];
		$offered['paths'][self::VEHICLES . '/{uuid}/kpis']['get']['requestBody'] = $offered['paths'][self::VEHICLES]['post']['requestBody'];
		$offered['paths'][self::VEHICLES]['post']['requestBody']['content']['application/xml'] = ['schema' => ['type' => 'string']];

		$this->assertSame([], Contract::breaks($this->baseline(), $offered));
	}

	/** @return array<string, mixed> */
	private function baseline(): array {
		return $this->load('tests/Api/v1-baseline.json');
	}

	/** @return array<string, mixed> */
	private function load(string $path): array {
		$document = json_decode((string)file_get_contents(self::ROOT . '/' . $path), true, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($document);

		return $document;
	}
}
