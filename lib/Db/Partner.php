<?php

/**
 * SPDX-FileCopyrightText: 2026 Johannes Kolb
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\NextFleet\Db;

use OCP\DB\Types;

/**
 * One partner in its owner's list, one property per column of `fleet_partners`. A trip keeps the
 * text it was entered with and links here ([ADR 0013](docs/adr/0013-a-partner-is-a-record-the-trip-keeps-its-text.md)).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getAddress()
 * @method void setAddress(?string $address)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 */
class Partner extends BaseEntity {
	/** The column's length, which the trip's `partner` shares. */
	public const NAME_LENGTH = 255;

	/**
	 * What a deleted record keeps for a name, which is none: the column takes no empty text on
	 * Oracle, which stores '' as NULL.
	 */
	public const ERASED = '-';

	protected string $userId = '';
	protected string $name = '';
	protected ?string $address = null;
	protected ?string $note = null;

	public function __construct() {
		parent::__construct();
		$this->addType('userId', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('address', Types::STRING);
		$this->addType('note', Types::STRING);
	}

	/**
	 * The name a trip text stands for (TypedText::normal()): two drivers who type one name on two
	 * keyboards reach one record, and a variant spelling stays its own. Null where the text names
	 * nobody.
	 */
	public static function nameOf(?string $text): ?string {
		return TypedText::normal($text, self::NAME_LENGTH);
	}
}
