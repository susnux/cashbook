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
use Closure;
use InvalidArgumentException;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\ConflictException;
use OCA\Cashbook\Exception\InvalidInputException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\TTransactional;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\Exception;
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
	 * @param int $lastSequence The sequence number of the last entry known to the client, 0 for an empty cashbook
	 * @param string $amount The positive amount as decimal string, expenses are subtracted from the balance
	 * @param ?float $tax The tax rate (e.g. `0.19` for 19%)
	 * @param ?string $referenceDate The date of the receipt (`YYYY-MM-DD`)
	 * @throws ConflictException If `$lastSequence` is not the sequence number of the last entry
	 * @throws InvalidInputException If an expense exceeds the cash balance
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function add(
		Cashbook $cashbook,
		string $userId,
		int $lastSequence,
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

		$text = $this->parseText($text);
		$reference = $this->parseReference($reference);
		$taxRate = $this->parseTax($tax);
		$receiptDate = $referenceDate === null || $referenceDate === '' ? null : $this->parseDate($referenceDate);

		return $this->append(
			$cashbook,
			$lastSequence,
			static fn (int $sequence, \DateTimeImmutable $datetime, Money $lastBalance): Entry => Entry::create(
				$cashbook,
				$sequence,
				$userId,
				$datetime,
				$text,
				$reason,
				$signedAmount,
				$lastBalance->plus($signedAmount),
				$taxRate,
				$reference,
				$receiptDate,
			),
			'The expense exceeds the cash balance.',
			$this->l10n->t('The expense exceeds the cash balance.'),
		);
	}

	/**
	 * Reverse an income or expense entry by adding an entry with the negated amount after the last entry of a cashbook.
	 *
	 * The reversal keeps the reason and tax rate of the reversed entry. Day openings and closings,
	 * adjusting entries and reversals can not be reversed, and every entry can only be reversed once.
	 *
	 * @param int $lastSequence The sequence number of the last entry known to the client, 0 for an empty cashbook
	 * @param string $entryId The id of the entry to reverse
	 * @param string $text The reason for the reversal
	 * @throws ConflictException If `$lastSequence` is not the sequence number of the last entry
	 * @throws DoesNotExistException If the cashbook has no entry with the id
	 * @throws InvalidInputException If the text is empty, the entry was already reversed or the reversal exceeds the cash balance
	 * @throws InvalidArgumentException If the entry can not be reversed or a value is invalid
	 */
	public function reverse(
		Cashbook $cashbook,
		string $userId,
		int $lastSequence,
		string $entryId,
		string $text,
		?string $reference = null,
	): Entry {
		$text = $this->parseText($text);
		if ($text === '') {
			throw new InvalidInputException('The reason for the reversal is missing.', $this->l10n->t('Enter the reason for the reversal.'));
		}
		$reference = $this->parseReference($reference);

		return $this->append(
			$cashbook,
			$lastSequence,
			function (int $sequence, \DateTimeImmutable $datetime, Money $lastBalance) use ($cashbook, $userId, $entryId, $text, $reference): Entry {
				$original = $this->entryMapper->getEntry($cashbook, $entryId);
				$reason = $original->getReason();
				if ($reason === null || (!$reason->isIncome() && !$reason->isExpense()) || $original->getSupersededId() !== null) {
					throw new InvalidArgumentException('Only income and expense entries can be reversed.');
				}
				if ($original->getSupersededBySequence() !== null) {
					throw new InvalidInputException('The entry was already reversed.', $this->l10n->t('This entry was already reversed.'));
				}

				return Entry::createReversal(
					$cashbook,
					$original,
					$sequence,
					$userId,
					$datetime,
					$text,
					$lastBalance->minus($original->getAmount()),
					$reference,
				);
			},
			'The reversal exceeds the cash balance.',
			$this->l10n->t('The reversal exceeds the cash balance.'),
		);
	}

	/**
	 * Insert an entry after the last entry of a cashbook while the cashbook is locked.
	 *
	 * The entry is booked at the current time, or at the time of the last entry if that is in the future,
	 * so the balance always follows from the previous entry.
	 *
	 * @param Closure(int, \DateTimeImmutable, Money): Entry $create Creates the entry from its sequence number, date and the balance before it
	 * @param string $negativeBalanceMessage The message if the balance after the entry is negative
	 * @param string $negativeBalanceHint The translated hint if the balance after the entry is negative
	 * @throws ConflictException If `$lastSequence` is not the sequence number of the last entry
	 * @throws InvalidInputException If the balance after the entry is negative
	 */
	private function append(
		Cashbook $cashbook,
		int $lastSequence,
		Closure $create,
		string $negativeBalanceMessage,
		string $negativeBalanceHint,
	): Entry {
		try {
			return $this->atomic(function () use ($cashbook, $lastSequence, $create, $negativeBalanceMessage, $negativeBalanceHint): Entry {
				$this->cashbookMapper->lock($cashbook);

				$last = $this->entryMapper->getLastEntry($cashbook);
				if (($last?->getSequence() ?? 0) !== $lastSequence) {
					throw new ConflictException('The cashbook was changed in the meantime.');
				}

				$datetime = \DateTimeImmutable::createFromInterface($this->timeFactory->now())->setTimezone(new \DateTimeZone('UTC'));
				if ($last !== null && $last->getDatetime() > $datetime) {
					$datetime = $last->getDatetime();
				}

				$entry = $create($lastSequence + 1, $datetime, $last?->getBalance() ?? Money::zero($cashbook->getCurrency()));
				if ($entry->getBalance()->isNegative()) {
					throw new InvalidInputException($negativeBalanceMessage, $negativeBalanceHint);
				}
				return $this->entryMapper->insert($entry);
			}, $this->db);
		} catch (Exception $error) {
			// Only possible if another request wrote despite the lock, the rolled back transaction can not continue
			if ($error->getReason() === Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw new ConflictException('The cashbook was changed in the meantime.', previous: $error);
			}
			throw $error;
		}
	}

	/**
	 * @throws InvalidArgumentException If the text is too long
	 */
	private function parseText(string $text): string {
		$text = trim($text);
		if (mb_strlen($text) > self::MAX_TEXT_LENGTH) {
			throw new InvalidArgumentException(sprintf('The text must not be longer than %d characters.', self::MAX_TEXT_LENGTH));
		}
		return $text;
	}

	/**
	 * @return ?string The trimmed reference, null if it is empty
	 * @throws InvalidArgumentException If the reference is too long
	 */
	private function parseReference(?string $reference): ?string {
		$reference = trim($reference ?? '');
		if (mb_strlen($reference) > self::MAX_REFERENCE_LENGTH) {
			throw new InvalidArgumentException(sprintf('The reference must not be longer than %d characters.', self::MAX_REFERENCE_LENGTH));
		}
		return $reference === '' ? null : $reference;
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
