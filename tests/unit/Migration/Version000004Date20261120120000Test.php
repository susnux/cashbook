<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Migration\Version000004Date20261120120000;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000004Date20261120120000Test extends TestCase {

	public function testSupersededIdIsUniqueAndNullable(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$table = $schema->getTable(EntryMapper::TABLE_NAME);

		$this->assertFalse($table->getColumn('superseded_id')->getNotnull());
		$index = $table->getIndex('cashbook_entries_supersede_uniq');
		$this->assertTrue($index->isUnique());
		$this->assertSame(['superseded_id'], $index->getColumns());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$migration = new Version000004Date20261120120000();

		$this->assertNull($migration->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}

	public function testAddsMissingIndex(): void {
		// The schema is only changed in memory, the database is not migrated
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$schema->getTable(EntryMapper::TABLE_NAME)->dropIndex('cashbook_entries_supersede_uniq');

		$migration = new Version000004Date20261120120000();
		$result = $migration->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);
		$index = $schema->getTable(EntryMapper::TABLE_NAME)->getIndex('cashbook_entries_supersede_uniq');
		$this->assertTrue($index->isUnique());
		$this->assertSame(['superseded_id'], $index->getColumns());
	}
}
