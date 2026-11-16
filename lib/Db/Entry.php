<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Db;

use Brick\Math\BigRational;
use Brick\Money\Money;
use JsonSerializable;
use OCA\Cashbook\ResponseDefinitions;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use RuntimeException;

/**
 * @psalm-import-type CashbookEntry from ResponseDefinitions
 *
 * @psalm-suppress PropertyNotSetInConstructor Properties are populated by the mapper
 * @psalm-suppress PossiblyUnusedProperty Properties are accessed through the magic getters and setters
 *
 * @method ?Cashbook getCashbook()
 * @method int getCashbookId()
 * @method \DateTimeImmutable getDatetime()
 * @method void setDatetime(\DateTimeImmutable $datetime)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method ?string getText()
 * @method void setText(?string $text)
 * @method string getAmountRaw()
 * @method void setAmountRaw(string $amountRaw)
 * @method string getBalanceRaw()
 * @method void setBalanceRaw(string $balanceRaw)
 * @method ?string getTaxRaw()
 * @method void setTaxRaw(?string $taxRaw)
 * @method ?string getReasonRaw()
 * @method void setReasonRaw(?string $reasonRaw)
 * @method ?string getReference()
 * @method void setReference(?string $reference)
 */
final class Entry extends Entity implements JsonSerializable {

	/**
	 * The cashbook this entry belongs to
	 * @var int
	 */
	protected $cashbookId;

	/**
	 * The date of this entry
	 * @var \DateTimeImmutable
	 */
	protected $datetime;

	/**
	 * The user that created this entry
	 * @var string
	 */
	protected $userId;

	/**
	 * The entry this one supersedes
	 * @var int
	 */
	protected $supersededId;

	/**
	 * The entry that supersedes this one
	 * @var int
	 */
	protected $supersededBy;

	/**
	 * The associated tax rate
	 * @var string
	 */
	protected $taxRaw;

	/**
	 * The raw amount (money so it is saved as string)
	 * @var string
	 */
	protected $amountRaw;

	/**
	 * The raw balance of the cashbook with this entry (money so it is saved as string)
	 * @var string
	 */
	protected $balanceRaw;

	/**
	 * The posting reference
	 * @var string
	 */
	protected $reference;

	/**
	 * The posting reference date
	 * @var \DateTimeImmutable
	 */
	protected $referenceDate;

	/**
	 * The posting text
	 * @var ?string
	 */
	protected $text;

	/**
	 * The raw reason (value of the `EntryReason` enum), stored in the `reason` column
	 * @var ?string
	 */
	protected $reasonRaw;

	public function __construct(
		protected ?Cashbook $cashbook = null,
	) {
		$this->addType('cashbookId', Types::BIGINT);
		$this->addType('datetime', Types::DATETIME_IMMUTABLE);
		$this->addType('referenceDate', Types::DATE_IMMUTABLE);
	}

	public static function create(
		Cashbook $cashbook,
		string $userId,
		\DateTimeImmutable $datetime,
		string $text,
		?EntryReason $reason,
		Money $amount,
		Money $balance,
		?BigRational $tax = null,
		?string $reference = null,
	): self {
		$entry = new self($cashbook);
		$entry->setUserId($userId);
		$entry->setDatetime($datetime);
		$entry->setText($text);
		$entry->setAmount($amount);
		$entry->setBalance($balance);
		$entry->setTax($tax);
		$entry->setReason($reason);
		$entry->setReference($reference);
		return $entry;
	}

	public function setCashbook(Cashbook $cashbook): void {
		$this->cashbook = $cashbook;
	}

	public function setTax(?BigRational $tax): void {
		$this->setTaxRaw($tax === null ? null : (string)$tax);
	}

	public function getTax(): ?BigRational {
		$raw = $this->getTaxRaw();
		if ($raw === null || $raw === '') {
			return null;
		}

		return BigRational::of($raw);
	}

	public function setAmount(Money $amount): void {
		$this->setAmountRaw((string)$amount->getAmount());
	}

	/**
	 * @throws RuntimeException If the cashbook was not set (to fetch the currency)
	 */
	public function getAmount(): Money {
		if ($this->cashbook === null) {
			throw new \RuntimeException('No currency available: Cashbook not set.');
		}
		return Money::of($this->amountRaw, $this->cashbook->getCurrency());
	}

	public function setReason(?EntryReason $reason): void {
		$this->setReasonRaw($reason?->value);
	}

	/**
	 * @throws \ValueError If the stored reason is not a valid `EntryReason`
	 */
	public function getReason(): ?EntryReason {
		$raw = $this->getReasonRaw();
		return $raw === null ? null : EntryReason::from($raw);
	}

	public function setBalance(Money $balance): void {
		$this->setBalanceRaw((string)$balance->getAmount());
	}

	/**
	 * @throws RuntimeException If the cashbook was not set (to fetch the currency)
	 */
	public function getBalance(): Money {
		if ($this->cashbook === null) {
			throw new \RuntimeException('No currency available: Cashbook not set.');
		}
		return Money::of($this->balanceRaw, $this->cashbook->getCurrency());
	}

	#[\Override]
	public function columnToProperty(string $columnName): string {
		if ($columnName === 'reason') {
			return 'reasonRaw';
		}
		return parent::columnToProperty($columnName);
	}

	#[\Override]
	public function propertyToColumn(string $property): string {
		if ($property === 'reasonRaw') {
			return 'reason';
		}
		return parent::propertyToColumn($property);
	}

	#[\Override]
	protected function getter(string $name): mixed {
		if ($name === 'cashbookId') {
			return $this->cashbook?->getId() ?? $this->cashbookId;
		}
		return parent::getter($name);
	}

	#[\Override]
	public function getUpdatedFields(): array {
		if ($this->cashbook !== null && $this->cashbook->getId() !== $this->cashbookId) {
			$this->markFieldUpdated('cashbookId');
		}
		return parent::getUpdatedFields();
	}

	/**
	 * @return CashbookEntry
	 */
	#[\Override]
	public function jsonSerialize(): array {
		$data = [
			'id' => $this->getId(),
			'balance' => (string)$this->getBalance()->getAmount(),
			'text' => $this->getText() ?? '',
			'date' => $this->getDatetime()->format(DATE_ATOM),
			'reason' => $this->getReason()?->value,
			'reference' => $this->getReference(),
			'tax' => $this->getTax()?->toFloat(),
		];

		$amount = $this->getAmount();
		$absoluteAmount = (string)$amount->abs()->getAmount();
		if ($amount->isPositive()) {
			$data['debit'] = $absoluteAmount;
		} elseif ($amount->isNegative()) {
			$data['credit'] = $absoluteAmount;
		}
		return $data;
	}
}
