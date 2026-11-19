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
use OCP\Config\IUserConfig;
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
		private IUserConfig $userConfig,
		private CashbookMapper $cashbookMapper,
		private EntryMapper $entryMapper,
	) {
	}

	/**
	 * Create a new cashbook.
	 *
	 * @param ?string $timezone The IANA timezone ID, defaults to the timezone of the owner
	 * @throws InvalidInputException If the name is empty or the timezone is not supported
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
	 * @throws InvalidInputException If the name is empty or the timezone is not supported
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
	 * Only `UTC` and the timezones of locations (`Area/Location`, including legacy names like `Europe/Kiev`) are supported.
	 * Fixed offsets like `+02:00`, `Etc/GMT-2` or `EST` are rejected, as they have no daylight saving time rules.
	 *
	 * @throws InvalidInputException If the timezone is not supported
	 */
	public function parseTimezone(string $timezone): \DateTimeZone {
		$isLocation = str_contains($timezone, '/') && !str_starts_with($timezone, 'Etc/');
		if ($timezone === '' || ($timezone !== 'UTC' && !$isLocation) || !in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
			throw new InvalidInputException('Unknown timezone.', $this->l10n->t('The selected timezone is not supported.'));
		}
		return new \DateTimeZone($timezone);
	}

	/**
	 * Get the timezone of a user as IANA timezone ID.
	 *
	 * Falls back to the default timezone of the server and then to `UTC` if they are not supported.
	 * Unlike `IDateTimeZone::getTimeZone()` this never guesses a fixed offset from the session of the current user.
	 */
	public function getDefaultTimezone(string $userId): string {
		$timezones = [
			$this->userConfig->getValueString($userId, 'core', 'timezone'),
			$this->dateTimeZone->getDefaultTimeZone()->getName(),
		];
		foreach ($timezones as $timezone) {
			try {
				return $this->parseTimezone($timezone)->getName();
			} catch (InvalidInputException) {
			}
		}
		return 'UTC';
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
