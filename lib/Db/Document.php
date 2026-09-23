<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One of a vehicle's papers, one property per column of `fleet_documents`
 * (docs/architecture.md#data-model). The file stays in Nextcloud Files; `fileId` is what finds
 * it after a move. `linkedType` and `linkedId` name the entry it belongs to, or are both null.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string getKind()
 * @method void setKind(string $kind)
 * @method string|null getLinkedType()
 * @method void setLinkedType(?string $linkedType)
 * @method int|null getLinkedId()
 * @method void setLinkedId(?int $linkedId)
 */
class Document extends BaseEntity {
	public const REGISTRATION = 'registration';
	public const INSURANCE = 'insurance';
	public const MANUAL = 'manual';
	public const RECEIPT = 'receipt';
	public const PHOTO = 'photo';
	public const KINDS = [self::REGISTRATION, self::INSURANCE, self::MANUAL, self::RECEIPT, self::PHOTO];

	public const ENERGY = 'energy';
	public const MAINTENANCE = 'maintenance';
	public const EXPENSE = 'expense';
	public const LINKABLE = [self::ENERGY, self::MAINTENANCE, self::EXPENSE];

	protected int $vehicleId = 0;
	protected int $fileId = 0;
	protected string $kind = '';
	protected ?string $linkedType = null;
	protected ?int $linkedId = null;

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('fileId', Types::BIGINT);
		$this->addType('kind', Types::STRING);
		$this->addType('linkedType', Types::STRING);
		$this->addType('linkedId', Types::BIGINT);
	}
}
