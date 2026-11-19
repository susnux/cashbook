<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Migration;

use OC\DB\Connection;
use OC\DB\SchemaWrapper;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Migration\Version000002Date20261119120000;
use OCA\Cashbook\Service\CashbookService;
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Migration\IOutput;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class Version000002Date20261119120000Test extends TestCase {

	private const array USER_TIMEZONES = [
		'cashbook-migration-berlin' => 'Europe/Berlin',
		'cashbook-migration-offset' => 'Etc/GMT-2',
	];

	private CashbookMapper $cashbookMapper;
	private Version000002Date20261119120000 $migration;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);

		$dateTimeZone = $this->createStub(IDateTimeZone::class);
		$dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone('UTC'));
		// A session timezone must not be used for the owners
		$dateTimeZone->method('getTimeZone')->willReturn(new \DateTimeZone('Asia/Colombo'));
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(
			static fn (string $userId, string $app, string $key): string => $app === 'core' && $key === 'timezone' ? (self::USER_TIMEZONES[$userId] ?? '') : '',
		);
		$db = Server::get(IDBConnection::class);
		$this->migration = new Version000002Date20261119120000(
			$db,
			new CashbookService($db, $this->createStub(IL10N::class), $dateTimeZone, $userConfig, $this->cashbookMapper, Server::get(EntryMapper::class)),
		);
	}

	#[\Override]
	protected function tearDown(): void {
		foreach (array_keys(self::USER_TIMEZONES) as $owner) {
			foreach ($this->cashbookMapper->getCashbooksByOwner($owner) as $cashbook) {
				$this->cashbookMapper->delete($cashbook);
			}
		}
		parent::tearDown();
	}

	private function createCashbook(string $owner): Cashbook {
		$cashbook = new Cashbook();
		$cashbook->setName('Shop');
		$cashbook->setAccount('');
		$cashbook->setCurrency('EUR');
		$cashbook->setOwner($owner);
		return $this->cashbookMapper->insert($cashbook);
	}

	public function testTimezoneColumn(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));
		$column = $schema->getTable('cashbook_cashbooks')->getColumn('timezone');

		$this->assertTrue($column->getNotnull());
		$this->assertSame(64, $column->getLength());
		$this->assertSame('UTC', $column->getDefault());
	}

	public function testMigrationIsIdempotent(): void {
		$schema = new SchemaWrapper(Server::get(Connection::class));

		$this->assertNull($this->migration->changeSchema($this->createStub(IOutput::class), static fn () => $schema, []));
	}

	public function testBackfillsTimezoneOfOwner(): void {
		$berlin = $this->createCashbook('cashbook-migration-berlin');
		$secondBerlin = $this->createCashbook('cashbook-migration-berlin');
		$offset = $this->createCashbook('cashbook-migration-offset');
		$this->assertSame('UTC', $this->cashbookMapper->getCashbook($berlin->getId())->getTimezone());

		$schema = new SchemaWrapper(Server::get(Connection::class));
		$this->migration->postSchemaChange($this->createStub(IOutput::class), static fn () => $schema, []);

		$this->assertSame('Europe/Berlin', $this->cashbookMapper->getCashbook($berlin->getId())->getTimezone());
		$this->assertSame('Europe/Berlin', $this->cashbookMapper->getCashbook($secondBerlin->getId())->getTimezone());
		$this->assertSame('UTC', $this->cashbookMapper->getCashbook($offset->getId())->getTimezone());
	}
}
