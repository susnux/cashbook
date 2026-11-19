<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Migration;

use Brick\Math\BigRational;
use Brick\Math\Exception\MathException;
use Closure;
use OCA\Cashbook\Db\EntryMapper;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Store existing tax rates in lowest terms, so equal rates are stored equally.
 *
 * @psalm-api Loaded by the migration service
 */
final class Version000003Date20261119180000 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * @param Closure(): ISchemaWrapper $schemaClosure
	 */
	#[\Override]
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$select = $this->db->getQueryBuilder();
		$select->selectDistinct('tax_raw')
			->from(EntryMapper::TABLE_NAME)
			->where($select->expr()->isNotNull('tax_raw'));
		$result = $select->executeQuery();
		/** @var list<string> $rates */
		$rates = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		$update = $this->db->getQueryBuilder();
		$update->update(EntryMapper::TABLE_NAME)
			->set('tax_raw', $update->createParameter('normalized'))
			->where($update->expr()->eq('tax_raw', $update->createParameter('raw')));
		foreach ($rates as $raw) {
			try {
				$normalized = (string)BigRational::of($raw)->simplified();
			} catch (MathException) {
				// Not a rate (e.g. an empty string), keep it as it is
				continue;
			}
			if ($normalized !== $raw) {
				$update->setParameter('raw', $raw)
					->setParameter('normalized', $normalized)
					->executeStatement();
			}
		}
	}
}
