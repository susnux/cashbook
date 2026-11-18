<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * @psalm-api Loaded by the migration service
 */
final class Version000001Date20250214140000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		if (!$schema->hasTable('cashbook_cashbooks')) {
			$this->createCashbooksTable($schema);
			$changed = true;
		}

		if (!$schema->hasTable('cashbook_cashbook_entries')) {
			$this->createCashbookEntriesTable($schema);
			$changed = true;
		}

		return $changed ? $schema : null;
	}

	private function createCashbooksTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable('cashbook_cashbooks');
		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('name', Types::STRING, [
			'notnull' => false,
		]);
		$table->addColumn('account', Types::STRING, [
			'notnull' => false,
		]);
		$table->addColumn('owner', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('currency', Types::STRING, [
			'notnull' => true,
			'length' => 3,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['owner'], 'cashbook_owner_idx');
	}

	private function createCashbookEntriesTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable('cashbook_cashbook_entries');

		// Snowflake ID
		$table->addColumn('id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		$table->addColumn('cashbook_id', Types::BIGINT, [
			'notnull' => true,
			'length' => 20,
			'unsigned' => true,
		]);
		// Gapless number of the entry within its cashbook, starting at 1
		$table->addColumn('sequence', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('datetime', Types::DATETIME_IMMUTABLE, [
			'notnull' => true,
		]);
		$table->addColumn('amount_raw', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		$table->addColumn('balance_raw', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		$table->addColumn('tax_raw', Types::STRING, [
			'notnull' => false,
			'length' => 32,
		]);
		$table->addColumn('reason', Types::STRING, [
			'notnull' => false,
			'length' => 64,
		]);
		$table->addColumn('reference', Types::STRING, [
			'notnull' => false,
			'length' => 127,
		]);
		$table->addColumn('reference_date', Types::DATE_IMMUTABLE, [
			'notnull' => false,
		]);
		$table->addColumn('text', Types::TEXT, [
			'notnull' => false,
		]);

		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['cashbook_id', 'sequence'], 'cashbook_entries_seq_uniq');
		$table->addIndex(['cashbook_id', 'datetime'], 'cashbook_entries_date_idx');
		$table->addIndex(['cashbook_id', 'reason', 'datetime'], 'cashbook_entries_reason_idx');
	}
}
