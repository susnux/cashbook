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
		private CashbookMapper $cashbookMapper,
		private EntryMapper $entryMapper,
	) {
	}

	/**
	 * Create a new cashbook.
	 *
	 * @throws InvalidInputException If the name is empty
	 * @throws InvalidArgumentException If a value is invalid
	 */
	public function create(string $owner, string $name, string $currency, string $account = ''): Cashbook {
		$cashbook = new Cashbook();
		$this->setDetails($cashbook, $name, $account);
		try {
			$cashbook->setCurrency(Currency::of(strtoupper($currency))->getCurrencyCode());
		} catch (UnknownCurrencyException) {
			throw new InvalidArgumentException('Unknown currency.');
		}
		$cashbook->setOwner($owner);
		return $this->cashbookMapper->insert($cashbook);
	}

	/**
	 * Change the name and account of a cashbook.
	 *
	 * The currency can not be changed, as the amounts of the entries are stored in it.
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
