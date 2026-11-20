<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Closure;
use OCA\Cashbook\Db\EntryMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the link from a reversal entry to the entry it reverses.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000004Date20261120120000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$table = $schema->getTable(EntryMapper::TABLE_NAME);
		if ($table->hasColumn('superseded_id') && $table->hasIndex('cashbook_entries_supersede_uniq')) {
			return null;
		}

		if (!$table->hasColumn('superseded_id')) {
			$table->addColumn('superseded_id', Types::BIGINT, [
				'notnull' => false,
				'length' => 20,
				'unsigned' => true,
			]);
		}
		if (!$table->hasIndex('cashbook_entries_supersede_uniq')) {
			// An entry can only be reversed once, entries that reverse nothing are NULL
			$table->addUniqueIndex(['superseded_id'], 'cashbook_entries_supersede_uniq');
		}
		return $schema;
	}
}
