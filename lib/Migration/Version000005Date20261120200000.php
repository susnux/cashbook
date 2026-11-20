<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Closure;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Split the adjusting entries (`correction`) into income and expense adjusting entries.
 *
 * Adjusting entries were shown by the sign of their amount, so negative ones become expense adjusting entries
 * and all others income adjusting entries.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000005Date20261120200000 extends SimpleMigrationStep {

	private const string LEGACY_CORRECTION = 'correction';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$expenses = $this->db->getQueryBuilder();
		$expenses->update(EntryMapper::TABLE_NAME)
			->set('reason', $expenses->createNamedParameter(EntryReason::ExpenseCorrection->value))
			->where($expenses->expr()->eq('reason', $expenses->createNamedParameter(self::LEGACY_CORRECTION)))
			->andWhere($expenses->expr()->like('amount_raw', $expenses->createNamedParameter('-%', IQueryBuilder::PARAM_STR)))
			->executeStatement();

		$income = $this->db->getQueryBuilder();
		$income->update(EntryMapper::TABLE_NAME)
			->set('reason', $income->createNamedParameter(EntryReason::IncomeCorrection->value))
			->where($income->expr()->eq('reason', $income->createNamedParameter(self::LEGACY_CORRECTION)))
			->executeStatement();
	}
}
