<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Controller;

use OCA\NextFleet\Exception\FileChangedException;
use OCA\NextFleet\Exception\ImportChangedException;
use OCA\NextFleet\Exception\ImportRefusedException;
use OCA\NextFleet\ResponseDefinitions;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\Lock\LockedException;

/**
 * The refusals only an import meets, the same through both doors: a file it will not read, one
 * somebody's client is writing, one changed since its preview, and entries changed since it wrote
 * them. The rest are EntryAnswers' and OcsAnswers'.
 *
 * @psalm-import-type NextFleetImportRefusal from ResponseDefinitions
 * @psalm-import-type NextFleetRefusal from ResponseDefinitions
 */
trait ImportAnswers {
	/**
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, NextFleetImportRefusal, array{}>|DataResponse<Http::STATUS_LOCKED, NextFleetRefusal, array{}>
	 */
	private function reading(\Closure $call): DataResponse {
		try {
			return $call();
		} catch (ImportRefusedException $e) {
			// The reason is a word for the screen to put into words; the message is for a log.
			return new DataResponse(
				['message' => 'Not a file an import reads: ' . $e->getMessage(), 'reason' => $e->reason, 'row' => $e->row],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		} catch (LockedException) {
			// Somebody's client is writing it; a moment later it reads.
			return new DataResponse(['message' => 'Being written, try again'], Http::STATUS_LOCKED);
		}
	}

	/**
	 * And the one only the import meets: a file that is no longer the one its preview read.
	 * Kept apart from reading() so the preview's type does not promise a 409 it cannot give.
	 *
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_UNPROCESSABLE_ENTITY, NextFleetImportRefusal, array{}>|DataResponse<Http::STATUS_LOCKED, NextFleetRefusal, array{}>|DataResponse<Http::STATUS_CONFLICT, NextFleetRefusal, array{}>
	 */
	private function importing(\Closure $call): DataResponse {
		try {
			return $this->reading($call);
		} catch (FileChangedException) {
			return new DataResponse(['message' => 'The file changed since the preview'], Http::STATUS_CONFLICT);
		}
	}

	/**
	 * The undo's own: a list naming an entry the import no longer left as it was. It reads no
	 * file, so it promises none of reading()'s answers.
	 *
	 * @template T of DataResponse
	 * @param \Closure(): T $call
	 * @return T|DataResponse<Http::STATUS_CONFLICT, NextFleetRefusal, array{}>
	 */
	private function undoing(\Closure $call): DataResponse {
		try {
			return $call();
		} catch (ImportChangedException $e) {
			return new DataResponse(['message' => $e->getMessage()], Http::STATUS_CONFLICT);
		}
	}
}
