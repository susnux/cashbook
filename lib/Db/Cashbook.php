<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Db;

use Brick\Money\Money;
use OCA\Cashbook\ResponseDefinitions;
use OCP\AppFramework\Db\SnowflakeAwareEntity;
use OCP\DB\Types;

/**
 * @psalm-import-type CashbookCashbook from ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor Properties are populated by the mapper
 * @psalm-suppress PossiblyUnusedProperty Properties are accessed through the magic getters and setters
 *
 * @method string getName()
 * @method void setName(string $value)
 * @method string getAccount()
 * @method void setAccount(string $value)
 * @method string getCurrency()
 * @method void setCurrency(string $value)
 * @method string getOwner()
 * @method void setOwner(string $value)
 */
final class Cashbook extends SnowflakeAwareEntity implements \JsonSerializable {

	/**
	 * Name of the cashbook
	 * @var string
	 */
	protected $name;

	/**
	 * Account this cashbook is about
	 * @var string
	 */
	protected $account;

	/**
	 * The currency (ISO code) this cashbook is kept
	 * @var string
	 */
	protected $currency;

	/**
	 * The owner of the cashbook
	 * @var string
	 */
	protected $owner;

	/**
	 * The sequence number of the last entry, loaded from the entries by the mapper (not stored)
	 * @var ?int
	 */
	protected $lastSequence;

	/**
	 * The cash balance after the last entry, loaded from the entries by the mapper (not stored)
	 * @var ?string
	 */
	protected $balanceRaw;

	public function __construct() {
		$this->addType('name', 'string');
		$this->addType('account', 'string');
		$this->addType('currency', 'string');
		$this->addType('owner', 'string');
		$this->addType('lastSequence', Types::BIGINT);
		$this->addType('balanceRaw', Types::STRING);
	}

	/**
	 * The sequence number of the last entry, 0 if the cashbook has no entries
	 */
	public function getLastSequence(): int {
		return $this->lastSequence ?? 0;
	}

	/**
	 * The cash balance after the last entry
	 */
	public function getBalance(): Money {
		return Money::of($this->balanceRaw ?? '0', $this->currency);
	}

	/**
	 * @return CashbookCashbook
	 */
	#[\Override]
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'account' => $this->account,
			'currency' => $this->currency,
			'name' => $this->name,
			'owner' => $this->owner,
			'lastSequence' => $this->getLastSequence(),
			'balance' => (string)$this->getBalance()->getAmount(),
		];
	}
}
