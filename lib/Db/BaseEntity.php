<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * The five columns every table carries (docs/architecture.md#data-model). Instants are unix
 * seconds; a user-facing timestamp adds its own `<name>_off` and lives on the concrete entity.
 *
 * @method string getUuid()
 * @method void setUuid(string $uuid)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 * @method int|null getDeletedAt()
 * @method void setDeletedAt(?int $deletedAt)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 */
abstract class BaseEntity extends Entity {
	protected string $uuid = '';
	protected int $createdAt = 0;
	protected int $updatedAt = 0;
	protected ?int $deletedAt = null;
	protected string $createdBy = '';

	public function __construct() {
		$this->addType('uuid', Types::STRING);
		$this->addType('createdAt', Types::BIGINT);
		$this->addType('updatedAt', Types::BIGINT);
		$this->addType('deletedAt', Types::BIGINT);
		$this->addType('createdBy', Types::STRING);
	}
}
