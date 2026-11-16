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
	 * Get a page of the entries of a cashbook in chronological order.
	 *
	 * @return list<Entry>
	 */
	public function getEntries(Cashbook $cashbook, int $offset = 0, int $limit = 50): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
			)
			->orderBy('datetime', 'ASC')
			->addOrderBy('id', 'ASC')
			->setMaxResults($limit)
			->setFirstResult($offset);
		return $this->findCashbookEntities($cashbook, $qb);
	}

	/**
	 * Get all entries of a cashbook in the date range `[$begin, $end)` in chronological order.
	 *
	 * If the range starts or ends within an open day (between a day opening and its day closing),
	 * the range is extended to include the whole day.
	 *
	 * @return list<Entry>
	 */
	public function getEntriesByDate(Cashbook $cashbook, \DateTimeImmutable $begin, \DateTimeImmutable $end): array {
		$opening = $this->findBoundary($cashbook, EntryReason::DayOpening, 'lte', $begin, 'DESC');
		if ($opening !== null && $this->findBoundary($cashbook, EntryReason::DayClosing, 'gte', $opening, 'ASC', $begin) === null) {
			$begin = $opening;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->gte('datetime', $qb->createNamedParameter($begin, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			)
			->orderBy('datetime', 'ASC')
			->addOrderBy('id', 'ASC');

		$closing = $this->findBoundary($cashbook, EntryReason::DayClosing, 'gte', $end, 'ASC');
		if ($closing !== null && $this->findBoundary($cashbook, EntryReason::DayOpening, 'gte', $end, 'ASC', $closing) === null) {
			$qb->andWhere($qb->expr()->lte('datetime', $qb->createNamedParameter($closing, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));
		} else {
			$qb->andWhere($qb->expr()->lt('datetime', $qb->createNamedParameter($end, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));
		}

		return $this->findCashbookEntities($cashbook, $qb);
	}

	/**
	 * Delete all entries of a cashbook.
	 */
	public function deleteByCashbook(Cashbook $cashbook): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
			)
			->executeStatement();
	}

	/**
	 * Find the date of the nearest day opening or closing entry relative to the given date.
	 *
	 * @param EntryReason::DayOpening|EntryReason::DayClosing $reason
	 * @param 'lte'|'gte' $comparison How the entry date is compared to `$date`
	 * @param 'ASC'|'DESC' $order Whether to find the earliest or the latest matching entry
	 * @param ?\DateTimeImmutable $before If set only entries before this date are considered
	 */
	private function findBoundary(
		Cashbook $cashbook,
		EntryReason $reason,
		string $comparison,
		\DateTimeImmutable $date,
		string $order,
		?\DateTimeImmutable $before = null,
	): ?\DateTimeImmutable {
		$qb = $this->db->getQueryBuilder();
		$qb->select('datetime')
			->from($this->tableName)
			->where(
				$qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId(), IQueryBuilder::PARAM_INT)),
				$qb->expr()->eq('reason', $qb->createNamedParameter($reason->value)),
				$qb->expr()->$comparison('datetime', $qb->createNamedParameter($date, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)),
			)
			->orderBy('datetime', $order)
			->setMaxResults(1);
		if ($before !== null) {
			$qb->andWhere($qb->expr()->lt('datetime', $qb->createNamedParameter($before, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));
		}

		$result = $qb->executeQuery();
		/** @var string|false $value */
		$value = $result->fetchOne();
		$result->closeCursor();
		if ($value === false) {
			return null;
		}
		return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
	}

	/**
	 * @return list<Entry>
	 */
	private function findCashbookEntities(Cashbook $cashbook, IQueryBuilder $qb): array {
		$entries = $this->findEntities($qb);
		foreach ($entries as $entry) {
			$entry->setCashbook($cashbook);
		}
		return $entries;
	}
}
