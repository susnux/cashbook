<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the indexes used to look up cashbooks by owner and entries by cashbook and date.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000002Date20261116000000 extends SimpleMigrationStep {

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		$changed = false;

		$cashbooks = $schema->getTable('cashbook_cashbooks');
		if (!$cashbooks->hasIndex('cashbook_owner_idx')) {
			$cashbooks->addIndex(['owner'], 'cashbook_owner_idx');
			$changed = true;
		}

		$entries = $schema->getTable('cashbook_cashbook_entries');
		if (!$entries->hasIndex('cashbook_entries_date_idx')) {
			$entries->addIndex(['cashbook_id', 'datetime'], 'cashbook_entries_date_idx');
			$changed = true;
		}
		if (!$entries->hasIndex('cashbook_entries_reason_idx')) {
			$entries->addIndex(['cashbook_id', 'reason', 'datetime'], 'cashbook_entries_reason_idx');
			$changed = true;
		}

		return $changed ? $schema : null;
	}
}
