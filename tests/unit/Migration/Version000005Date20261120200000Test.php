<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Migration;

use Brick\Money\Money;
use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Migration\Version000005Date20261120200000;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000005Date20261120200000Test extends TestCase {

	private const string OWNER = 'cashbook-migration-correction';

	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;
	private Cashbook $cashbook;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);

		$cashbook = new Cashbook();
		$cashbook->setName('Shop');
		$cashbook->setAccount('');
		$cashbook->setCurrency('EUR');
		$cashbook->setOwner(self::OWNER);
		$this->cashbook = $this->cashbookMapper->insert($cashbook);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->entryMapper->deleteByCashbook($this->cashbook);
		$this->cashbookMapper->delete($this->cashbook);
		parent::tearDown();
	}

	private function runMigration(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		(new Version000005Date20261120200000(Server::get(IDBConnection::class)))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	public function testSplitsAdjustingEntriesBySign(): void {
		$entries = [
			['correction', '0.50'],
			['correction', '-0.50'],
			['correction', '0'],
			[EntryReason::IncomeSales->value, '-1'],
			[null, '-1'],
		];
		foreach ($entries as $index => [$reason, $amount]) {
			$entry = Entry::create(
				$this->cashbook,
				$index + 1,
				self::OWNER,
				new \DateTimeImmutable('2025-01-02 10:0' . $index, new \DateTimeZone('UTC')),
				'',
				null,
				Money::of($amount, 'EUR'),
				Money::of('10', 'EUR'),
			);
			$entry->setReasonRaw($reason);
			$this->entryMapper->insert($entry);
		}

		$this->runMigration();
		// Running it again must not change the migrated entries
		$this->runMigration();

		$stored = [];
		foreach ($this->entryMapper->getEntries($this->cashbook) as $entry) {
			$stored[$entry->getSequence()] = $entry->getReasonRaw();
		}
		ksort($stored);
		$this->assertSame([
			1 => 'income-correction',
			2 => 'expense-correction',
			3 => 'income-correction',
			4 => 'income-sales',
			5 => null,
		], $stored);
	}
}
