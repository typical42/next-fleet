<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One recorded change, one property per column of `fleet_audit`
 * (docs/architecture.md#data-model). Written where a logbook needs the change - the mode's flips,
 * the vehicle's facts, a trip under the mode - and for a transfer. Only ever inserted: who and
 * when are `createdBy` and `createdAt`, which the row carries anyway and which no update may touch.
 *
 * `entity` says which table `entityId` points into. There is no foreign key - the trail has to
 * outlive an erasure of what it describes.
 *
 * @method string getEntity()
 * @method void setEntity(string $entity)
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method array<string, mixed> getDiffJson()
 * @method void setDiffJson(array $diffJson)
 */
class Audit extends BaseEntity {
	public const VEHICLE = 'vehicle';
	public const TRIP = 'trip';
	/**
	 * Who wrote what a transfer writes, its audit, grant and recipient rows (TransferService). No
	 * account did, and `:` keeps any account from taking the name (IUserManager::validateUserId()).
	 * The audit row's diff names both owners, which an erasure renames there too
	 * (AuditMapper::pseudonymise()).
	 */
	public const TRANSFERRED_BY = 'occ:transfer';

	protected string $entity = '';
	protected int $entityId = 0;
	/**
	 * The changed fields, plus any fact the change itself carried - that an edit was late, that a
	 * trip was derived rather than observed. Its shape is the writing service's business.
	 *
	 * @var array<string, mixed>
	 */
	protected array $diffJson = [];

	public function __construct() {
		parent::__construct();
		$this->addType('entity', Types::STRING);
		$this->addType('entityId', Types::BIGINT);
		$this->addType('diffJson', Types::JSON);
	}
}
