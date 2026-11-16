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
use Psr\Log\LoggerInterface;

/**
 * @template-extends QBMapper<Entry>
 */
final class EntryMapper extends QBMapper {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		IDBConnection $db,
	) {
		parent::__construct($db, 'cashbook_cashbook_entries', Entry::class);
	}

	/**
	 * @return list<Entry>
	 */
	public function getEntries(Cashbook $cashbook, int $offset = 0, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
			)
			->setMaxResults($limit)
			->setFirstResult($offset);
		$entries = $this->findEntities($qb);
		foreach ($entries as $entry) {
			$entry->setCashbook($cashbook);
		}
		return $entries;
	}

	/**
	 * @return list<Entry>
	 */
	public function getEntriesByDate(Cashbook $cashbook, \DateTimeImmutable $begin, \DateTimeImmutable $end): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('reason', $qb->createNamedParameter(EntryReason::DayOpening, IQueryBuilder::PARAM_STR)),
				$qb->expr()->gte('datetime', $qb->createNamedParameter($begin, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			)
			->setMaxResults(1);
		$idStart = (int)$qb->executeQuery()->fetchOne();

		$qb = $this->db->getQueryBuilder();
		$qbEnd = $this->db->getQueryBuilder();
		$qbEnd->select('id')
			->from($this->tableName)
			->where(
				$qbEnd->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qbEnd->expr()->eq('reason', $qb->createNamedParameter(EntryReason::DayOpening, IQueryBuilder::PARAM_STR)),
				$qbEnd->expr()->lte('datetime', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			)
			->orderBy('datetime', 'DESC')
			->setMaxResults(1);

		$qb->select('id')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('reason', $qb->createNamedParameter(EntryReason::DayClosing, IQueryBuilder::PARAM_STR)),
				$qb->expr()->gt('id', $qbEnd->createFunction('(' . $qbEnd->getSQL() . ')')),
			)
			->setMaxResults(1);
		$idEnd = (int)$qb->executeQuery()->fetchOne();

		\OCP\Server::get(LoggerInterface::class)->error('Entry date range IDs: {start} - {end}', ['start' => $idStart, 'end' => $idEnd]);

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->gte('id', $qb->createNamedParameter($idStart, IQueryBuilder::PARAM_INT)),
				$qb->expr()->lte('id', $qb->createNamedParameter($idEnd, IQueryBuilder::PARAM_INT)),
			);
		$entries = $this->findEntities($qb);
		foreach ($entries as $entry) {
			$entry->setCashbook($cashbook);
		}
		return $entries;
	}
}
