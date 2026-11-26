<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a client does, over HTTP with an app password and nothing else (docs/api.md). Each run
 * signs in as a fresh account and deletes it afterwards, its app password and its Files with it;
 * the sweep up front catches what a crashed run left.
 */
class ClientTest extends TestCase {
	use Syncing;

	private const PREFIX = 'nextfleet-api-';

	private static Server $server;
	private static Account $anna;

	public static function setUpBeforeClass(): void {
		self::$server = Server::fromEnvironment();
		self::$server->forget(self::PREFIX);
		self::$anna = self::$server->account(self::PREFIX);
	}

	public static function tearDownAfterClass(): void {
		self::$server->forget(self::PREFIX);
	}

	/**
	 * @param array<string, mixed> $more the create's other fields
	 * @return array<string, mixed> the vehicle as its create answered it
	 */
	private function vehicle(string $plate, array $more = []): array {
		$created = self::$server->ocs(self::$anna, 'POST', '/vehicles', ['plate' => $plate] + $more);
		$this->assertSame(201, $created->status, $created->body);

		return $created->data();
	}

	/** @return array<string, mixed> the trip as its create answered it */
	private function trip(string $vehicle): array {
		$created = self::$server->ocs(self::$anna, 'POST', "/vehicles/$vehicle/trips", [
			'started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120,
			'end_odo' => 1000, 'category' => 'business',
		]);
		$this->assertSame(201, $created->status, $created->body);

		return $created->data();
	}

	/**
	 * Nextcloud's own refusal, before any code of ours runs - NC 31 answers it in XML whatever the
	 * request accepts. The failed login counts against this address, and every later login from
	 * it waits longer, so the count is cleared again.
	 */
	public function testAWrongPasswordIsA401(): void {
		$answer = self::$server->ocs(new Account(self::$anna->uid, 'not-the-password'), 'GET', '/vehicles');
		self::$server->forgiveFailedLogins();

		$this->assertSame(401, $answer->status, $answer->body);
	}

	public function testAVehicleIsCreatedAndListed(): void {
		$vehicle = $this->vehicle('API 1');

		$this->assertContains('own', $vehicle['may']);
		$listed = self::$server->ocs(self::$anna, 'GET', '/vehicles');
		$this->assertSame(200, $listed->status, $listed->body);
		$this->assertContains($vehicle['uuid'], array_column($listed->data(), 'uuid'));
	}

	public function testATripMovesTheCounter(): void {
		$vehicle = $this->vehicle('API 2');

		$trip = $this->trip($vehicle['uuid']);

		$this->assertSame(1000, $trip['end_odo']);
		$this->assertSame(1000, self::$server->ocs(self::$anna, 'GET', "/vehicles/{$vehicle['uuid']}")->data()['odo_value']);
	}

	/**
	 * A create sent again after its answer was lost: the same trip, 200, and still one Reading.
	 * Over HTTP, because `client_uuid` sits beside the route's `{uuid}` in one namespace.
	 */
	public function testATripSentTwiceUnderItsClientUuidIsOneTrip(): void {
		$vehicle = $this->vehicle('API 5');
		$trip = ['client_uuid' => '0195e2f1-4444-4000-8000-' . bin2hex(random_bytes(6)), 'started_at' => 1750000000, 'started_at_off' => 120,
			'ended_at' => 1750005400, 'ended_at_off' => 120, 'end_odo' => 1000, 'category' => 'business'];

		$created = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/trips", $trip);
		$again = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/trips", $trip);

		$this->assertSame(201, $created->status, $created->body);
		$this->assertSame(200, $again->status, $again->body);
		$this->assertSame($trip['client_uuid'], $created->data()['uuid']);
		$this->assertSame($created->data(), $again->data());
		$this->assertCount(1, self::$server->ocs(self::$anna, 'GET', "/vehicles/{$vehicle['uuid']}/readings")->data());
	}

	/** @return array<string, array{string}> */
	public static function doors(): array {
		return [
			'OCS' => ['/ocs/v2.php/apps/nextfleet/api/v1/vehicles/'],
			'internal' => ['/index.php/apps/nextfleet/api/vehicles/'],
		];
	}

	/**
	 * Nextcloud merges a JSON body over the URL's parameters, so a body field named like a
	 * placeholder would pick the row, or fail the argument's type with a 500 on NC 31.
	 */
	#[DataProvider('doors')]
	public function testABodyFieldNamedLikeAPlaceholderIsA400(string $door): void {
		$vehicle = $this->vehicle('API 6');
		$other = $this->vehicle('API 7');
		$headers = ['OCS-APIRequest: true', 'Accept: application/json', 'Content-Type: application/json'];

		foreach ([[], $other['uuid']] as $uuid) {
			$body = json_encode(['uuid' => $uuid, 'updated_at' => $other['updated_at'], 'plate' => 'API 8'], JSON_THROW_ON_ERROR);
			$answer = self::$server->request('PUT', $door . $vehicle['uuid'], self::$anna, $headers, $body);

			$this->assertSame(400, $answer->status, $answer->body);
		}
		$this->assertSame('API 7', self::$server->ocs(self::$anna, 'GET', "/vehicles/{$other['uuid']}")->data()['plate']);
	}

	public function testAStaleEditIsA412WithTheConflictFlag(): void {
		$vehicle = $this->vehicle('API 3');

		$stale = self::$server->ocs(self::$anna, 'PUT', "/vehicles/{$vehicle['uuid']}", ['updated_at' => $vehicle['updated_at'] - 1, 'plate' => 'API 3a']);

		$this->assertSame(412, $stale->status, $stale->body);
		$this->assertTrue($stale->data()['conflict']);
	}

	/** A file in the caller's Files, attached, and fetched back through the link. */
	public function testADocumentDownloads(): void {
		$vehicle = $this->vehicle('API 4');
		$fileId = self::$server->upload(self::$anna, 'registration.txt', 'Zulassungsbescheinigung Teil I');
		$attached = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/documents", ['file_id' => $fileId, 'kind' => 'registration']);
		$this->assertSame(200, $attached->status, $attached->body);

		$download = self::$server->request('GET', "/index.php/apps/nextfleet/vehicles/{$vehicle['uuid']}/documents/{$attached->data()[0]['uuid']}", self::$anna);

		$this->assertSame(200, $download->status, $download->body);
		$this->assertSame('Zulassungsbescheinigung Teil I', $download->body);
		$this->assertStringStartsWith('attachment', (string)$download->header('content-disposition'));
	}

	/**
	 * A client that saves a paper and then deletes it from Files at once: the delete must not meet
	 * the download's lock. Nextcloud keeps that lock to the request's end, which under Apache comes
	 * before the last bytes leave. `php -S` sends the bytes first, so there the delete races the
	 * request's end by a few milliseconds: hence twenty rounds. 64 KiB, whole 8 KiB writes, is the
	 * size likeliest to slip through first.
	 */
	public function testADownloadedFileDeletesAtOnce(): void {
		$vehicle = $this->vehicle('API 9');
		$refused = [];

		for ($round = 0; $round < 20; $round++) {
			$fileId = self::$server->upload(self::$anna, "paper-$round.txt", str_repeat('Fahrzeugschein. ', 4096));
			$attached = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/documents", ['file_id' => $fileId, 'kind' => 'registration']);
			$this->assertSame(200, $attached->status, $attached->body);
			$document = array_values(array_filter($attached->data(), static fn (array $paper): bool => $paper['file_id'] === $fileId))[0];

			$download = self::$server->request('GET', "/index.php/apps/nextfleet/vehicles/{$vehicle['uuid']}/documents/{$document['uuid']}", self::$anna);
			$deleted = self::$server->request('DELETE', '/remote.php/dav/files/' . rawurlencode(self::$anna->uid) . "/paper-$round.txt", self::$anna);

			$this->assertSame(200, $download->status, $download->body);
			if ($deleted->status !== 204) {
				$refused[] = "round $round: $deleted->status";
			}
		}

		$this->assertSame([], $refused);
	}

	/** An export in the caller's Files, previewed, then imported against the file the preview read. */
	public function testAnExportImports(): void {
		$vehicle = $this->vehicle('API 7', ['jurisdiction' => 'de', 'energy_types' => ['diesel']]);
		$fileId = self::$server->upload(self::$anna, 'fuel.csv', (string)file_get_contents(__DIR__ . '/../Fixture/import/lubelogger-fuel.csv'));
		$asked = [
			'file_id' => $fileId, 'importer' => 'lubelogger', 'record_type' => 'fuel',
			'units' => ['distance' => 'km', 'volume' => 'l'], 'tz' => 'UTC',
		];
		$preview = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/import/preview", $asked);
		$this->assertSame(200, $preview->status, $preview->body);

		$imported = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/import", $asked + ['etag' => $preview->data()['etag']]);

		$this->assertSame(200, $imported->status, $imported->body);
		$this->assertSame(['new' => 2, 'duplicate' => 0, 'unreadable' => 2, 'creates' => 2], $imported->data()['counts']);
		$this->assertSame(['energy', 'energy'], array_column($imported->data()['created'], 'type'));
		$this->assertSame(52310, self::$server->ocs(self::$anna, 'GET', "/vehicles/{$vehicle['uuid']}")->data()['odo_value']);
	}

	/**
	 * Two trips logged from one booking at once - two devices, one tap each, on two connections.
	 * One is tied to it and the other refused 409, and the refusal takes its trip back with it.
	 *
	 * What this proves is that outcome over HTTP, in whatever order the server took the two: it
	 * cannot tell whether they ran side by side. Apache and `php -S` with workers can run them so;
	 * that the hold then orders them is TripServiceTest's and HoldOrderTest's to prove.
	 */
	public function testTwoTripsSentAtOnceFromOneBookingTieOnlyOne(): void {
		$uuid = $this->vehicle('API 8')['uuid'];
		// This minute: a start more than five minutes back is refused (BookingService::apply()).
		$now = intdiv(time(), 60) * 60;
		$booked = self::$server->ocs(self::$anna, 'POST', "/vehicles/$uuid/bookings", ['starts_at' => $now, 'starts_at_off' => 120, 'ends_at' => $now + 3 * 3600, 'ends_at_off' => 120]);
		$this->assertSame(201, $booked->status, $booked->body);
		$booking = $booked->data()['uuid'];
		$out = self::$server->ocs(self::$anna, 'POST', "/vehicles/$uuid/bookings/$booking/check-out", ['odo' => 1000, 'at_off' => 120]);
		$this->assertSame(200, $out->status, $out->body);
		$in = self::$server->ocs(self::$anna, 'POST', "/vehicles/$uuid/bookings/$booking/check-in", ['odo' => 1100, 'at_off' => 120]);
		$this->assertSame(200, $in->status, $in->body);
		$trip = $in->data()['trip_draft'] + ['category' => 'business', 'booking_uuid' => $booking];

		$answers = self::$server->race(self::$anna, [['POST', "/vehicles/$uuid/trips", $trip], ['POST', "/vehicles/$uuid/trips", $trip]]);

		$statuses = array_map(static fn (Answer $answer): int => $answer->status, $answers);
		sort($statuses);
		$this->assertSame([201, 409], $statuses, $answers[0]->body . "\n" . $answers[1]->body);
		$tied = $answers[0]->status === 201 ? $answers[0] : $answers[1];
		$listed = self::$server->ocs(self::$anna, 'GET', "/vehicles/$uuid/bookings");
		$this->assertSame([$tied->data()['uuid']], array_column($listed->data(), 'trip_uuid'));
		$trips = self::$server->ocs(self::$anna, 'GET', "/vehicles/$uuid/timeline", ['type' => 'trip']);
		$this->assertCount(1, (array)$trips->data()['rows'], $trips->body);
	}

	/**
	 * Two trips that name one new partner, sent at once on two vehicles of one owner: both link one
	 * record, and each answer names it. As above, this proves the outcome in whatever order the server
	 * took the two; that a new name waits for the owner's hold is PartnerLinksTest's to prove.
	 */
	public function testTwoTripsSentAtOnceWithOneNewPartnerLinkOneRecord(): void {
		$partner = 'Race GmbH ' . bin2hex(random_bytes(4));
		$trip = ['started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750005400, 'ended_at_off' => 120,
			'end_odo' => 1000, 'category' => 'business', 'partner' => $partner];
		$first = $this->vehicle('API 9')['uuid'];
		$second = $this->vehicle('API 10')['uuid'];

		$answers = self::$server->race(self::$anna, [['POST', "/vehicles/$first/trips", $trip], ['POST', "/vehicles/$second/trips", $trip]]);

		$this->assertSame([201, 201], [$answers[0]->status, $answers[1]->status], $answers[0]->body . "\n" . $answers[1]->body);
		$this->assertIsString($answers[0]->data()['partner_uuid']);
		$this->assertSame($answers[0]->data()['partner_uuid'], $answers[1]->data()['partner_uuid']);
		$this->assertSame($partner, $answers[1]->data()['partner']);
	}

	/** @return array<string, array{string}> */
	public static function reports(): array {
		return [
			'the logbook' => ['logbook/2025'],
			'the mileage claim' => ['mileage/2025'],
			'a CSV' => ['csv/2025/trips'],
		];
	}

	/** The other download links: no CSRF token and no OCS header, only the app password. */
	#[DataProvider('reports')]
	public function testAReportAnswersAnAppPassword(string $report): void {
		$vehicle = $this->vehicle('API 5');
		$this->trip($vehicle['uuid']);

		$answer = self::$server->request('GET', "/index.php/apps/nextfleet/vehicles/{$vehicle['uuid']}/$report", self::$anna);

		$this->assertSame(200, $answer->status, $answer->body);
	}

	/**
	 * The owner keeps the partner list through the API: a rename onto a taken name is refused with
	 * its reason, a merge moves the trips, the trips and their CSV are read, and a delete reaches
	 * sync as a tombstone. The trips keep their texts.
	 */
	public function testTheOwnerKeepsThePartnerList(): void {
		$suffix = bin2hex(random_bytes(4));
		$vehicle = $this->vehicle('API 11')['uuid'];
		$trips = [];
		foreach (["Müller $suffix" => 1750000000, "Mueller $suffix" => 1750100000] as $partner => $at) {
			$created = self::$server->ocs(self::$anna, 'POST', "/vehicles/$vehicle/trips", [
				'started_at' => $at, 'started_at_off' => 120, 'ended_at' => $at + 3600, 'ended_at_off' => 120,
				'end_odo' => intdiv($at - 1740000000, 1000), 'category' => 'business', 'partner' => $partner,
			]);
			$this->assertSame(201, $created->status, $created->body);
			$trips[] = $created->data();
		}
		[$kept, $variant] = [$trips[0]['partner_uuid'], $trips[1]['partner_uuid']];
		$listed = self::$server->ocs(self::$anna, 'GET', '/partners');
		$this->assertSame(200, $listed->status, $listed->body);
		$token = array_column($listed->data(), 'updated_at', 'uuid')[$variant];

		$taken = self::$server->ocs(self::$anna, 'PUT', "/partners/$variant", ['updated_at' => $token, 'name' => "Müller $suffix"]);
		$this->assertSame([400, 'name_taken'], [$taken->status, $taken->data()['reason'] ?? null], $taken->body);

		$merged = self::$server->ocs(self::$anna, 'POST', "/partners/$variant/merge", ['updated_at' => $token, 'into' => $kept]);
		$this->assertSame(200, $merged->status, $merged->body);
		$this->assertSame($kept, $merged->data()['uuid']);

		$read = self::$server->ocs(self::$anna, 'GET', "/partners/$kept/trips", ['from' => '2025-06-01', 'to' => '2025-07-01']);
		$this->assertSame(200, $read->status, $read->body);
		$this->assertSame(["Müller $suffix", "Mueller $suffix"], array_column(array_column($read->data()['trips'], 'trip'), 'partner'));
		$csv = self::$server->request('GET', "/index.php/apps/nextfleet/partners/$kept/csv?from=2025-06-01&to=2025-07-01", self::$anna);
		$this->assertSame(200, $csv->status, $csv->body);
		$this->assertStringContainsString("Mueller $suffix", $csv->body);

		$cursor = $this->sync(self::$anna)['cursor'];
		$deleted = self::$server->ocs(self::$anna, 'DELETE', "/partners/$kept", ['updated_at' => $merged->data()['updated_at']]);
		$this->assertSame(200, $deleted->status, $deleted->body);
		$this->assertSame(404, self::$server->ocs(self::$anna, 'GET', "/partners/$kept")->status);
		$synced = array_column($this->sync(self::$anna, $cursor)['partners'], null, 'uuid');
		$this->assertArrayHasKey($kept, $synced);
		$this->assertSame([$deleted->data()['deleted_at'], null], [$synced[$kept]['deleted_at'], $synced[$kept]['row']]);
	}

	/**
	 * A viewer gets the owner's cost centres and no partner, by sync and by the partner's route.
	 * Made driver, their client starts over and gets the names, without address and note.
	 */
	public function testPartnerNamesGoOnlyToWhoeverMayLog(): void {
		$suffix = bin2hex(random_bytes(4));
		$viewer = self::$server->account(self::PREFIX . 'viewer-');
		$vehicle = $this->vehicle('API 13')['uuid'];
		$trip = self::$server->ocs(self::$anna, 'POST', "/vehicles/$vehicle/trips", [
			'started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750003600, 'ended_at_off' => 120,
			'end_odo' => 1000, 'category' => 'business', 'partner' => "Müller $suffix",
		]);
		$this->assertSame(201, $trip->status, $trip->body);
		$partner = $trip->data()['partner_uuid'];
		$centre = self::$server->ocs(self::$anna, 'POST', '/cost-centres', ['name' => "Vertrieb $suffix"]);
		$this->assertSame(201, $centre->status, $centre->body);
		$granted = self::$server->ocs(self::$anna, 'POST', "/vehicles/$vehicle/grants", ['grantee' => $viewer->uid, 'grantee_type' => 'user', 'role' => 'viewer']);
		$this->assertSame(200, $granted->status, $granted->body);

		$this->assertSame(403, self::$server->ocs($viewer, 'GET', "/partners/$partner")->status);
		$synced = $this->sync($viewer);
		$this->assertSame([], $synced['partners']);
		// Anna's lists hold what the other cases made too.
		$this->assertContains($centre->data()['uuid'], array_column($synced['cost_centres'], 'uuid'));

		$grant = array_column($granted->data(), 'uuid', 'grantee')[$viewer->uid];
		$changed = self::$server->ocs(self::$anna, 'PUT', "/vehicles/$vehicle/grants/$grant", ['role' => 'driver']);
		$this->assertSame(200, $changed->status, $changed->body);

		$read = self::$server->ocs($viewer, 'GET', "/partners/$partner");
		$this->assertSame(200, $read->status, $read->body);
		$this->assertSame(["Müller $suffix", null, null], [$read->data()['name'], $read->data()['address'], $read->data()['note']]);
		$again = $this->sync($viewer, $synced['cursor']);
		$this->assertTrue($again['reset']);
		$this->assertContains($partner, array_column($again['partners'], 'uuid'));
	}

	/**
	 * The owner keeps cost centres through the API: a taken code is refused with its reason, a
	 * vehicle's default reaches a new trip unless the trip sends null, one in use is archived
	 * rather than deleted, and the list reaches sync.
	 */
	public function testTheOwnerKeepsCostCentresAndAnEntryTakesTheDefault(): void {
		$suffix = bin2hex(random_bytes(4));
		$made = self::$server->ocs(self::$anna, 'POST', '/cost-centres', ['code' => "C$suffix", 'name' => "Vertrieb $suffix"]);
		$this->assertSame(201, $made->status, $made->body);
		$centre = $made->data();
		$taken = self::$server->ocs(self::$anna, 'POST', '/cost-centres', ['code' => "C$suffix", 'name' => "Service $suffix"]);
		$this->assertSame([400, 'code_taken'], [$taken->status, $taken->data()['reason'] ?? null], $taken->body);

		$vehicle = $this->vehicle('API 12');
		$set = self::$server->ocs(self::$anna, 'PUT', '/vehicles/' . $vehicle['uuid'], ['updated_at' => $vehicle['updated_at'], 'cost_centre_uuid' => $centre['uuid']]);
		$this->assertSame(200, $set->status, $set->body);
		$this->assertSame($centre['uuid'], $set->data()['cost_centre_uuid']);
		$trip = ['started_at' => 1750000000, 'started_at_off' => 120, 'ended_at' => 1750003600, 'ended_at_off' => 120, 'end_odo' => 1000, 'category' => 'business'];
		$defaulted = self::$server->ocs(self::$anna, 'POST', '/vehicles/' . $vehicle['uuid'] . '/trips', $trip);
		$this->assertSame([201, $centre['uuid']], [$defaulted->status, $defaulted->data()['cost_centre_uuid'] ?? null], $defaulted->body);
		$none = self::$server->ocs(self::$anna, 'POST', '/vehicles/' . $vehicle['uuid'] . '/trips', ['started_at' => 1750100000, 'ended_at' => 1750103600, 'end_odo' => 1100, 'cost_centre_uuid' => null] + $trip);
		$this->assertSame(201, $none->status, $none->body);
		$this->assertNull($none->data()['cost_centre_uuid']);

		$refused = self::$server->ocs(self::$anna, 'DELETE', '/cost-centres/' . $centre['uuid'], ['updated_at' => $centre['updated_at']]);
		$this->assertSame([400, 'in_use'], [$refused->status, $refused->data()['reason'] ?? null], $refused->body);
		$archived = self::$server->ocs(self::$anna, 'PUT', '/cost-centres/' . $centre['uuid'], ['updated_at' => $centre['updated_at'], 'code' => "C$suffix", 'name' => "Vertrieb $suffix", 'archived' => true]);
		$this->assertSame([200, true], [$archived->status, $archived->data()['archived'] ?? null], $archived->body);

		$listed = array_column(self::$server->ocs(self::$anna, 'GET', '/cost-centres')->data(), null, 'uuid');
		$this->assertTrue($listed[$centre['uuid']]['archived']);
		$synced = array_column($this->sync(self::$anna)['cost_centres'], null, 'uuid');
		$this->assertSame("Vertrieb $suffix", $synced[$centre['uuid']]['row']['name'] ?? null);
	}

	/** The owner plans a year; a second budget for it is refused, and sync carries the one there is. */
	public function testTheOwnerSetsABudgetPerYear(): void {
		$vehicle = $this->vehicle('API 13', ['currency' => 'EUR']);
		$path = '/vehicles/' . $vehicle['uuid'] . '/budgets';
		$made = self::$server->ocs(self::$anna, 'POST', $path, ['year' => 2031, 'amount' => 500000, 'net' => true]);
		$this->assertSame([201, 2031, 500000, true], [$made->status, $made->data()['year'] ?? null, $made->data()['amount'] ?? null, $made->data()['net'] ?? null], $made->body);
		$taken = self::$server->ocs(self::$anna, 'POST', $path, ['year' => 2031, 'amount' => 600000, 'net' => true]);
		$this->assertSame([400, 'year_taken'], [$taken->status, $taken->data()['reason'] ?? null], $taken->body);

		$raised = self::$server->ocs(self::$anna, 'PUT', $path . '/' . $made->data()['uuid'], ['updated_at' => $made->data()['updated_at'], 'amount' => 600000, 'net' => false]);
		$this->assertSame([200, 600000, false], [$raised->status, $raised->data()['amount'] ?? null, $raised->data()['net'] ?? null], $raised->body);
		$this->assertSame([$raised->data()], self::$server->ocs(self::$anna, 'GET', $path)->data());
		$this->assertSame($raised->data(), $this->synced($this->sync(self::$anna), 'budgets', $made->data()['uuid'])['row']);

		$gone = self::$server->ocs(self::$anna, 'DELETE', $path . '/' . $made->data()['uuid'], ['updated_at' => $raised->data()['updated_at']]);
		$this->assertSame(200, $gone->status, $gone->body);
		$this->assertSame([], self::$server->ocs(self::$anna, 'GET', $path)->data());
	}

	/** A delete reaches the client as a tombstone, and its restore as the row again. */
	public function testSyncCarriesADeleteAndItsRestore(): void {
		$vehicle = $this->vehicle('API 6');
		$trip = $this->trip($vehicle['uuid']);
		$first = $this->sync(self::$anna);
		$this->assertContains($vehicle['uuid'], array_column($first['vehicles'], 'uuid'));
		$this->assertNull($this->synced($first, 'trips', $trip['uuid'])['deleted_at']);

		$deleted = self::$server->ocs(self::$anna, 'DELETE', "/vehicles/{$vehicle['uuid']}/trips/{$trip['uuid']}", ['updated_at' => $trip['updated_at']]);
		$this->assertSame(200, $deleted->status, $deleted->body);
		$second = $this->sync(self::$anna, $first['cursor']);
		$tombstone = $this->synced($second, 'trips', $trip['uuid']);
		$this->assertNotNull($tombstone['deleted_at']);
		$this->assertNull($tombstone['row']);

		$restored = self::$server->ocs(self::$anna, 'POST', "/vehicles/{$vehicle['uuid']}/trips/{$trip['uuid']}/restore", ['updated_at' => $deleted->data()['updated_at']]);
		$this->assertSame(200, $restored->status, $restored->body);
		$third = $this->synced($this->sync(self::$anna, $second['cursor']), 'trips', $trip['uuid']);
		$this->assertNull($third['deleted_at']);
		$this->assertSame($restored->data()['updated_at'], $third['updated_at']);
	}
}
