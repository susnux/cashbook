<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Service;

use Brick\Money\Currency;
use Brick\Money\Exception\UnknownCurrencyException;
use InvalidArgumentException;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Exception\InvalidInputException;
use OCP\AppFramework\Db\TTransactional;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;

final class CashbookService {
	use TTransactional;

	/** Maximum length of the name and account (length of the database columns) */
	private const int MAX_LENGTH = 255;

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private IDBConnection $db,
		private IL10N $l10n,
		private IDateTimeZone $dateTimeZone,
		private CashbookMapper $cashbookMapper,
		private EntryMapper $entryMapper,
	) {
	}

	/**
	 * Create a new cashbook.
	 *
	 * @param ?string $timezone The IANA timezone ID, defaults to the timezone of the owner
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function create(string $owner, string $name, string $currency, string $account = '', ?string $timezone = null): Cashbook {
		return $this->cashbookMapper->insert($this->build($owner, $name, $currency, $account, $timezone));
	}

	/**
	 * Create a new cashbook without storing it.
	 *
	 * The currency and timezone can not be changed afterwards.
	 *
	 * @param ?string $timezone The IANA timezone ID, defaults to the timezone of the owner
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function build(string $owner, string $name, string $currency, string $account = '', ?string $timezone = null): Cashbook {
		$cashbook = new Cashbook();
		$this->setDetails($cashbook, $name, $account);
		try {
			$cashbook->setCurrency(Currency::of(strtoupper($currency))->getCurrencyCode());
		} catch (UnknownCurrencyException) {
			throw new InvalidArgumentException('Unknown currency.');
		}
		$cashbook->setTimezone($timezone === null ? $this->getDefaultTimezone($owner) : $this->parseTimezone($timezone)->getName());
		$cashbook->setOwner($owner);
		return $cashbook;
	}

	/**
	 * Parse an IANA timezone ID.
	 *
	 * Fixed offsets like `+02:00` are rejected, as they have no daylight saving time rules.
	 *
	 * @throws InvalidArgumentException If the timezone is not a known IANA timezone ID
	 */
	public function parseTimezone(string $timezone): \DateTimeZone {
		if ($timezone === '' || !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
			throw new InvalidArgumentException('Unknown timezone.');
		}
		return new \DateTimeZone($timezone);
	}

	/**
	 * Get the timezone of a user as IANA timezone ID, `UTC` if the user has no valid timezone.
	 */
	public function getDefaultTimezone(string $userId): string {
		$timezone = $this->dateTimeZone->getTimeZone(userId: $userId)->getName();
		try {
			return $this->parseTimezone($timezone)->getName();
		} catch (InvalidArgumentException) {
			return 'UTC';
		}
	}

	/**
	 * Change the name and account of a cashbook.
	 *
	 * The currency can not be changed, as the amounts of the entries are stored in it.
	 * The timezone can not be changed, as it would move entries to other days that may already be printed.
	 *
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function update(Cashbook $cashbook, string $name, string $account = ''): Cashbook {
		$this->setDetails($cashbook, $name, $account);
		return $this->cashbookMapper->update($cashbook);
	}

	/**
	 * Validate and set the user editable details of a cashbook.
	 *
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If a value is too long
	 */
	private function setDetails(Cashbook $cashbook, string $name, string $account): void {
		$name = trim($name);
		$account = trim($account);
		if ($name === '') {
			throw new InvalidInputException('The name of the cashbook must not be empty.', $this->l10n->t('The name of the cashbook must not be empty.'));
		}
		if (mb_strlen($name) > self::MAX_LENGTH) {
			throw new InvalidArgumentException(sprintf('The name of the cashbook must not be longer than %d characters.', self::MAX_LENGTH));
		}
		if (mb_strlen($account) > self::MAX_LENGTH) {
			throw new InvalidArgumentException(sprintf('The account must not be longer than %d characters.', self::MAX_LENGTH));
		}

		$cashbook->setName($name);
		$cashbook->setAccount($account);
	}

	/**
	 * Delete a cashbook including all of its entries.
	 */
	public function delete(Cashbook $cashbook): void {
		$this->atomic(function () use ($cashbook): void {
			$this->entryMapper->deleteByCashbook($cashbook);
			$this->cashbookMapper->delete($cashbook);
		}, $this->db);
	}

	/**
	 * Delete all cashbooks owned by the given user including their entries.
	 */
	public function deleteByOwner(string $owner): void {
		foreach ($this->cashbookMapper->getCashbooksByOwner($owner) as $cashbook) {
			$this->delete($cashbook);
		}
	}
}
