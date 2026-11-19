<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Closure;
use OCA\Cashbook\Service\CashbookService;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the timezone of a cashbook, existing cashbooks get the timezone of their owner.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000002Date20261119120000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
		private CashbookService $cashbookService,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable('cashbook_cashbooks');
		if ($table->hasColumn('timezone')) {
			return null;
		}

		// The longest IANA timezone ID has 32 characters
		$table->addColumn('timezone', Types::STRING, [
			'notnull' => true,
			'length' => 64,
			'default' => 'UTC',
		]);
		return $schema;
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->selectDistinct('owner')
			->from('cashbook_cashbooks');
		$result = $select->executeQuery();
		/** @var list<string> $owners */
		$owners = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		$update = $this->db->getQueryBuilder();
		$update->update('cashbook_cashbooks')
			->set('timezone', $update->createParameter('timezone'))
			->where($update->expr()->eq('owner', $update->createParameter('owner')));
		foreach ($owners as $owner) {
			$update->setParameter('owner', $owner)
				->setParameter('timezone', $this->cashbookService->getDefaultTimezone($owner))
				->executeStatement();
		}
	}
}
