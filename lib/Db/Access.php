<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One grant on one vehicle, in our own table rather than a core share
 * (docs/adr/0001-own-access-table.md). The grantee is a user id or a group id, and which of the
 * two it is `granteeType` says - the two namespaces are separate and a name may be in both.
 *
 * @method int getVehicleId()
 * @method void setVehicleId(int $vehicleId)
 * @method string getGrantee()
 * @method void setGrantee(string $grantee)
 * @method string getGranteeType()
 * @method void setGranteeType(string $granteeType)
 * @method string getRole()
 * @method void setRole(string $role)
 */
class Access extends BaseEntity {
	public const USER = 'user';
	public const GROUP = 'group';

	protected int $vehicleId = 0;
	protected string $grantee = '';
	protected string $granteeType = '';
	protected string $role = '';

	public function __construct() {
		parent::__construct();
		$this->addType('vehicleId', Types::BIGINT);
		$this->addType('grantee', Types::STRING);
		$this->addType('granteeType', Types::STRING);
		$this->addType('role', Types::STRING);
	}
}
