<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Service;

use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Brick\Money\Money;
use InvalidArgumentException;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\InvalidInputException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;

final class EntryService {
	use TTransactional;

	/** Maximum number of integer digits of an amount */
	private const int MAX_AMOUNT_DIGITS = 18;
	/** Maximum length of the posting text */
	public const int MAX_TEXT_LENGTH = 1000;
	/** Maximum length of the reference (length of the database column) */
	public const int MAX_REFERENCE_LENGTH = 127;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private IL10N $l10n,
		private ITimeFactory $timeFactory,
		private CashbookMapper $cashbookMapper,
		private EntryMapper $entryMapper,
	) {
	}

	/**
	 * Add an income or expense entry after the last entry of a cashbook.
	 *
	 * The entry gets the next sequence number of the cashbook. It is booked at the current time,
	 * or at the time of the last entry if that is in the future, so the balance always follows from the previous entry.
	 *
	 * @param string $amount The positive amount as decimal string, expenses are subtracted from the balance
	 * @param ?float $tax The tax rate (e.g. `0.19` for 19%)
	 * @param ?string $referenceDate The date of the receipt (`YYYY-MM-DD`)
	 * @throws InvalidInputException If an expense exceeds the cash balance
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function add(
		Cashbook $cashbook,
		string $userId,
		EntryReason $reason,
		string $amount,
		string $text = '',
		?float $tax = null,
		?string $reference = null,
		?string $referenceDate = null,
	): Entry {
		if (!$reason->isIncome() && !$reason->isExpense()) {
			throw new InvalidArgumentException('Only income and expense entries can be added.');
		}

		$money = $this->parseAmount($amount, $cashbook->getCurrency());
		$signedAmount = $reason->isExpense() ? $money->negated() : $money;

		$text = trim($text);
		if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
			throw new InvalidArgumentException(sprintf('The text must not be longer than %d characters.', self::MAX_TEXT_LENGTH));
		}

		$reference = trim($reference ?? '');
		if (mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
			throw new InvalidArgumentException(sprintf('The reference must not be longer than %d characters.', self::MAX_REFERENCE_LENGTH));
		}

		$taxRate = $this->parseTax($tax);
		$receiptDate = $referenceDate === null || $referenceDate === '' ? null : $this->parseDate($referenceDate);

		return $this->atomic(function () use ($cashbook, $userId, $reason, $signedAmount, $text, $taxRate, $reference, $receiptDate): Entry {
			$this->cashbookMapper->lock($cashbook);

			$last = $this->entryMapper->getLastEntry($cashbook);
			$balance = ($last?->getBalance() ?? Money::zero($cashbook->getCurrency()))->plus($signedAmount);
			if ($balance->isNegative()) {
				throw new InvalidInputException('The expense exceeds the cash balance.', $this->l10n->t('The expense exceeds the cash balance.'));
			}

			$datetime = \DateTimeImmutable::createFromInterface($this->timeFactory->now())->setTimezone(new \DateTimeZone('UTC'));
			if ($last !== null && $last->getDatetime() > $datetime) {
				$datetime = $last->getDatetime();
			}

			return $this->entryMapper->insert(Entry::create(
				$cashbook,
				($last?->getSequence() ?? 0) + 1,
				$userId,
				$datetime,
				$text,
				$reason,
				$signedAmount,
				$balance,
				$taxRate,
				$reference === '' ? null : $reference,
				$receiptDate,
			));
		}, $this->db);
	}

	/**
	 * @throws InvalidArgumentException If the amount is not a positive decimal number in the precision of the currency
	 */
	private function parseAmount(string $amount, string $currency): Money {
		$amount = trim($amount);
		if (preg_match('/^\d{1,' . self::MAX_AMOUNT_DIGITS . '}(\.\d+)?$/', $amount) !== 1) {
			throw new InvalidArgumentException('The amount must be a positive decimal number.');
		}

		try {
			$money = Money::of($amount, $currency);
		} catch (MathException $error) {
			throw new InvalidArgumentException('The amount has more decimal places than the currency.', previous: $error);
		}
		if (!$money->isPositive()) {
			throw new InvalidArgumentException('The amount must be a positive decimal number.');
		}
		return $money;
	}

	/**
	 * @throws InvalidArgumentException If the tax rate is not between 0 and 1
	 */
	private function parseTax(?float $tax): ?BigRational {
		if ($tax === null) {
			return null;
		}
		if (!is_finite($tax) || $tax < 0 || $tax > 1) {
			throw new InvalidArgumentException('The tax rate must be between 0 and 1.');
		}
		return BigRational::of((string)BigDecimal::of(sprintf('%.6F', $tax))->stripTrailingZeros());
	}

	/**
	 * @throws InvalidArgumentException If the date is not a valid `YYYY-MM-DD` date
	 */
	private function parseDate(string $date): \DateTimeImmutable {
		$parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
		if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
			throw new InvalidArgumentException('The receipt date must be a valid date (YYYY-MM-DD).');
		}
		return $parsed;
	}
}
