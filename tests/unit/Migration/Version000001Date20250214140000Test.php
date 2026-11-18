<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Cashbook\Migration\Version000001Date20250214140000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000001Date20250214140000Test extends TestCase {

	public function testIdsAreNotAutoincrement(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertFalse($schema->getTable('cashbook_cashbooks')->getColumn('id')->getAutoincrement());
		$this->assertFalse($schema->getTable('cashbook_cashbook_entries')->getColumn('id')->getAutoincrement());
	}

	public function testIndexesExist(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertTrue($schema->getTable('cashbook_cashbooks')->hasIndex('cashbook_owner_idx'));
		$entries = $schema->getTable('cashbook_cashbook_entries');
		$sequence = $entries->getIndex('cashbook_entries_seq_uniq');
		$this->assertTrue($sequence->isUnique());
		$this->assertSame(['cashbook_id', 'sequence'], $sequence->getColumns());
		$this->assertSame(['cashbook_id', 'datetime'], $entries->getIndex('cashbook_entries_date_idx')->getColumns());
		$this->assertSame(['cashbook_id', 'reason', 'datetime'], $entries->getIndex('cashbook_entries_reason_idx')->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$migration = new Version000001Date20250214140000();

		$this->assertNull($migration->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}
}
