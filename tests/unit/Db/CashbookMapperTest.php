<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Db;

use Brick\Money\Money;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Service\CashbookService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class CashbookMapperTest extends TestCase {

	private const string USER_ID = 'cashbook-mapper-test';

	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;
	private CashbookService $cashbookService;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);
		$this->cashbookService = Server::get(CashbookService::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->cashbookService->deleteByOwner(self::USER_ID);
		parent::tearDown();
	}

	private function insertEntry(Cashbook $cashbook, int $sequence, string $balance): void {
		$this->entryMapper->insert(Entry::create(
			$cashbook,
			$sequence,
			self::USER_ID,
			new \DateTimeImmutable('2025-01-02 10:00', new \DateTimeZone('UTC')),
			'',
			EntryReason::IncomeMisc,
			Money::of('1', $cashbook->getCurrency()),
			Money::of($balance, $cashbook->getCurrency()),
		));
	}

	public function testLoadsLastEntryOfEachCashbook(): void {
		$first = $this->cashbookService->create(self::USER_ID, 'First', 'EUR');
		$second = $this->cashbookService->create(self::USER_ID, 'Second', 'JPY');
		$empty = $this->cashbookService->create(self::USER_ID, 'Empty', 'EUR');
		$this->insertEntry($first, 1, '1');
		$this->insertEntry($first, 2, '2.5');
		$this->insertEntry($second, 1, '300');

		$cashbooks = [];
		foreach ($this->cashbookMapper->getCashbooksByOwner(self::USER_ID) as $cashbook) {
			$cashbooks[$cashbook->getName()] = [$cashbook->getLastSequence(), (string)$cashbook->getBalance()->getAmount()];
		}
		ksort($cashbooks);
		$this->assertSame([
			'Empty' => [0, '0.00'],
			'First' => [2, '2.50'],
			'Second' => [1, '300'],
		], $cashbooks);

		$cashbook = $this->cashbookMapper->getCashbook($first->getId());
		$this->assertSame(2, $cashbook->getLastSequence());
		$this->assertSame('2.50', (string)$cashbook->getBalance()->getAmount());
	}

	public function testUpdateDoesNotStoreLoadedEntryState(): void {
		$cashbook = $this->cashbookService->create(self::USER_ID, 'Shop', 'EUR');
		$this->insertEntry($cashbook, 1, '5');

		$loaded = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame([], $loaded->getUpdatedFields());
		$this->cashbookService->update($loaded, 'Market');
		$this->assertSame(1, $this->cashbookMapper->getCashbook($cashbook->getId())->getLastSequence());
	}
}
