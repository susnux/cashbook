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
use OCA\Cashbook\Migration\Version000003Date20261119180000;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000003Date20261119180000Test extends TestCase {

	private const string OWNER = 'cashbook-migration-tax';

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
		(new Version000003Date20261119180000(Server::get(IDBConnection::class)))
			->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);
	}

	public function testStoresTaxRatesInLowestTerms(): void {
		$rates = ['19/100', '190/1000', '1900/10000', '0/100', '', null];
		foreach ($rates as $index => $rate) {
			$entry = Entry::create(
				$this->cashbook,
				$index + 1,
				self::OWNER,
				new \DateTimeImmutable('2025-01-02 10:0' . $index, new \DateTimeZone('UTC')),
				'',
				EntryReason::IncomeSales,
				Money::of('1', 'EUR'),
				Money::of($index + 1, 'EUR'),
			);
			$entry->setTaxRaw($rate);
			$this->entryMapper->insert($entry);
		}

		$this->runMigration();
		// Running it again must not change the normalized rates
		$this->runMigration();

		$stored = [];
		foreach ($this->entryMapper->getEntries($this->cashbook) as $entry) {
			$stored[$entry->getSequence()] = $entry->getTaxRaw();
		}
		ksort($stored);
		$this->assertSame([1 => '19/100', 2 => '19/100', 3 => '19/100', 4 => '0', 5 => '', 6 => null], $stored);
	}
}
