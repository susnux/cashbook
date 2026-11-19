<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Cashbook>
 */
final class CashbookMapper extends QBMapper {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, 'cashbook_cashbooks', Cashbook::class);
	}

	public function getCashbook(string $id): Cashbook {
		$qb = $this->selectWithLastEntry();
		$qb->where(
			$qb->expr()->eq('c.id', $qb->createNamedParameter($id)),
		);
		return $this->findEntity($qb);
	}

	/**
	 * Lock the row of a cashbook until the end of the current transaction.
	 *
	 * Serializes concurrent changes of the cashbook, e.g. adding entries based on the current balance.
	 * Needs to be the first statement of the transaction.
	 */
	public function lock(Cashbook $cashbook): void {
		// A write instead of `SELECT … FOR UPDATE`, as SQLite only supports locking the whole database
		// and fails a transaction that writes after reading outdated data instead of waiting for the lock.
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->tableName)
			->set('owner', 'owner')
			->where(
				$qb->expr()->eq('id', $qb->createNamedParameter($cashbook->getId())),
			)
			->executeStatement();
	}

	/**
	 * @return list<Cashbook>
	 */
	public function getCashbooksByOwner(string $owner): array {
		$qb = $this->selectWithLastEntry();
		$qb->where(
			$qb->expr()->eq('c.owner', $qb->createNamedParameter($owner)),
		);
		return $this->findEntities($qb);
	}

	/**
	 * Select cashbooks (alias `c`) with the sequence number and balance of their last entry.
	 */
	private function selectWithLastEntry(): IQueryBuilder {
		$last = $this->db->getQueryBuilder();
		$last->select($last->func()->max('l.sequence'))
			->from(EntryMapper::TABLE_NAME, 'l')
			->where($last->expr()->eq('l.cashbook_id', 'c.id'));

		$qb = $this->db->getQueryBuilder();
		$qb->select('c.*')
			->selectAlias('e.sequence', 'last_sequence')
			->selectAlias('e.balance_raw', 'balance_raw')
			->from($this->tableName, 'c')
			->leftJoin('c', EntryMapper::TABLE_NAME, 'e', $qb->expr()->andX(
				$qb->expr()->eq('e.cashbook_id', 'c.id'),
				$qb->expr()->eq('e.sequence', $qb->createFunction('(' . $last->getSQL() . ')')),
			));
		return $qb;
	}
}
