<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Db\Booking;
use OCA\NextFleet\Service\BookingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The bookings that hang off one vehicle. Every rule lives in BookingService, including the access
 * check.
 */
class BookingController extends Controller {
	use EntryAnswers;

	public function __construct(
		string $appName,
		IRequest $request,
		private BookingService $service,
		private IUserSession $session,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	public function index(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->list($this->userId(), $uuid, $this->request->getParams())));
	}

	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse(
			$this->service->book($this->userId(), $uuid, $this->request->getParams()),
			Http::STATUS_CREATED,
		));
	}

	/**
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(string $uuid, string $booking): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->change($this->userId(), $uuid, $booking, $token, $this->request->getParams()));
	}

	/**
	 * A cancel: the booking stays, `cancelled`.
	 *
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function delete(string $uuid, string $booking): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->cancel($this->userId(), $uuid, $booking, $token));
	}

	/**
	 * Taking the car. No token: what counts is the state the booking is in now
	 * (BookingService::inState()).
	 *
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function checkOut(string $uuid, string $booking): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->checkOut($this->userId(), $uuid, $booking, $this->request->getParams())));
	}

	/**
	 * Giving it back, answered with the trip to log as `trip_draft`.
	 *
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function checkIn(string $uuid, string $booking): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->checkIn($this->userId(), $uuid, $booking, $this->request->getParams())));
	}

	/**
	 * The three answers to a booking that waits for one (BookingService::answer()).
	 *
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function approve(string $uuid, string $booking): DataResponse {
		return $this->answered($uuid, $booking, Booking::APPROVED);
	}

	/** @param string $booking the booking's uuid */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function ask(string $uuid, string $booking): DataResponse {
		return $this->answered($uuid, $booking, Booking::ASKED);
	}

	/** @param string $booking the booking's uuid */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function decline(string $uuid, string $booking): DataResponse {
		return $this->answered($uuid, $booking, Booking::DECLINED);
	}

	/**
	 * The booker confirms a booking an approver made for them. No token: it changes nothing the
	 * booking plans.
	 *
	 * @param string $booking the booking's uuid
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function seen(string $uuid, string $booking): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->seen($this->userId(), $uuid, $booking)));
	}

	#[NoAdminRequired]
	public function bookers(string $uuid): DataResponse {
		return $this->answer(fn (): DataResponse => new DataResponse($this->service->bookers($this->userId(), $uuid)));
	}

	/** @param value-of<BookingService::ANSWERS> $answer */
	private function answered(string $uuid, string $booking, string $answer): DataResponse {
		return $this->checked(fn (int $token): array => $this->service->answer($this->userId(), $uuid, $booking, $token, $answer, $this->request->getParams()));
	}
}
