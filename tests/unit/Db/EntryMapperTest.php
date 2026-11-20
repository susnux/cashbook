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
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryMapperTest extends TestCase {

	private const string USER_ID = 'entry-mapper-test';

	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;
	private Cashbook $cashbook;
	private int $sequence = 0;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);

		$cashbook = new Cashbook();
		$cashbook->setName('Test');
		$cashbook->setAccount('');
		$cashbook->setCurrency('EUR');
		$cashbook->setOwner(self::USER_ID);
		$this->cashbook = $this->cashbookMapper->insert($cashbook);
	}

	#[\Override]
	protected function tearDown(): void {
		$db = Server::get(IDBConnection::class);
		$qb = $db->getQueryBuilder();
		$qb->delete('cashbook_cashbook_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER_ID)))
			->executeStatement();
		$this->cashbookMapper->delete($this->cashbook);
		parent::tearDown();
	}

	/**
	 * @param ?int $sequence The sequence number, defaults to the next one
	 */
	private function insertEntry(string $datetime, ?EntryReason $reason, string $text, ?int $sequence = null): Entry {
		return $this->entryMapper->insert(Entry::create(
			$this->cashbook,
			$sequence ?? ++$this->sequence,
			self::USER_ID,
			new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')),
			$text,
			$reason,
			Money::of('1', 'EUR'),
			Money::of('1', 'EUR'),
		));
	}

	private function insertReversal(string $datetime, Entry $original, string $text): Entry {
		return $this->entryMapper->insert(Entry::createReversal(
			$this->cashbook,
			$original,
			++$this->sequence,
			self::USER_ID,
			new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')),
			$text,
			Money::of('0', 'EUR'),
		));
	}

	/**
	 * @param list<Entry> $entries
	 * @return array<string, array{?int, ?int}> The sequence numbers of the reversed entry and of the reversal by entry text
	 */
	private function links(array $entries): array {
		$links = [];
		foreach ($entries as $entry) {
			$links[$entry->getText() ?? ''] = [$entry->getSupersededSequence(), $entry->getSupersededBySequence()];
		}
		return $links;
	}

	/**
	 * @param list<Entry> $entries
	 * @return list<string>
	 */
	private function texts(array $entries): array {
		return array_map(static fn (Entry $entry): string => $entry->getText() ?? '', $entries);
	}

	/**
	 * Two closed days and one day that is still open
	 */
	private function insertDays(): void {
		$this->insertEntry('2025-01-31 08:00', EntryReason::DayOpening, 'open-1');
		$this->insertEntry('2025-01-31 22:00', EntryReason::IncomeSales, 'sale-1');
		$this->insertEntry('2025-02-01 02:00', EntryReason::IncomeSales, 'sale-2');
		$this->insertEntry('2025-02-01 03:00', EntryReason::DayClosing, 'close-1');
		$this->insertEntry('2025-02-27 08:00', EntryReason::DayOpening, 'open-2');
		$this->insertEntry('2025-02-28 01:00', EntryReason::IncomeSales, 'sale-3');
		$this->insertEntry('2025-02-28 23:00', EntryReason::IncomeSales, 'sale-4');
		$this->insertEntry('2025-03-01 01:00', EntryReason::IncomeSales, 'sale-5');
		$this->insertEntry('2025-03-01 02:00', EntryReason::DayClosing, 'close-2');
		$this->insertEntry('2025-03-02 08:00', EntryReason::DayOpening, 'open-3');
		$this->insertEntry('2025-03-02 09:00', EntryReason::IncomeSales, 'sale-6');
	}

	public function testGetEntriesIsOrderedBySequence(): void {
		$this->insertEntry('2025-01-02 10:00', EntryReason::IncomeSales, 'c', 3);
		$this->insertEntry('2025-01-02 10:00', EntryReason::IncomeSales, 'a', 1);
		$this->insertEntry('2025-01-02 10:00', EntryReason::IncomeSales, 'b', 2);
		$this->insertEntry('2025-01-02 10:00', EntryReason::IncomeSales, 'd', 4);

		$entries = $this->entryMapper->getEntries($this->cashbook);
		$this->assertSame(['a', 'b', 'c', 'd'], $this->texts($entries));
		$this->assertSame([1, 2, 3, 4], array_map(static fn (Entry $entry): int => $entry->getSequence(), $entries));
		$this->assertSame(['c', 'd'], $this->texts($this->entryMapper->getEntries($this->cashbook, 2, 2)));
	}

	public function testSequenceIsUniquePerCashbook(): void {
		$this->insertEntry('2025-01-02 10:00', EntryReason::IncomeSales, 'a', 1);

		try {
			$this->insertEntry('2025-01-02 11:00', EntryReason::IncomeSales, 'b', 1);
			$this->fail('A duplicate sequence number must be rejected.');
		} catch (Exception $error) {
			$this->assertSame(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $error->getReason());
		}
	}

	public function testGetEntriesLinksReversalsAcrossPeriods(): void {
		$sale = $this->insertEntry('2025-01-10 10:00', EntryReason::IncomeSales, 'sale');
		$this->insertEntry('2025-01-11 10:00', EntryReason::IncomeSales, 'other sale');
		$this->insertReversal('2025-02-03 10:00', $sale, 'reversal');

		$january = $this->entryMapper->getEntriesByDate(
			$this->cashbook,
			new \DateTimeImmutable('2025-01-01', new \DateTimeZone('UTC')),
			new \DateTimeImmutable('2025-02-01', new \DateTimeZone('UTC')),
		);
		$this->assertSame(['sale' => [null, 3], 'other sale' => [null, null]], $this->links($january));

		$february = $this->entryMapper->getEntriesByDate(
			$this->cashbook,
			new \DateTimeImmutable('2025-02-01', new \DateTimeZone('UTC')),
			new \DateTimeImmutable('2025-03-01', new \DateTimeZone('UTC')),
		);
		$this->assertSame(['reversal' => [1, null]], $this->links($february));
		$this->assertSame($sale->getId(), $february[0]->getSupersededId());

		$all = $this->entryMapper->getEntries($this->cashbook);
		$this->assertSame(['sale', 'other sale', 'reversal'], $this->texts($all));
		$this->assertSame(['sale' => [null, 3], 'other sale' => [null, null], 'reversal' => [1, null]], $this->links($all));
		$this->assertSame(['reversal'], $this->texts($this->entryMapper->getEntries($this->cashbook, 2, 2)));
	}

	public function testEntryCanOnlyBeReversedOnce(): void {
		$sale = $this->insertEntry('2025-01-10 10:00', EntryReason::IncomeSales, 'sale');
		$this->insertReversal('2025-01-10 11:00', $sale, 'reversal');

		try {
			$this->insertReversal('2025-01-10 12:00', $sale, 'second reversal');
			$this->fail('A second reversal of the same entry must be rejected.');
		} catch (Exception $error) {
			$this->assertSame(Exception::REASON_UNIQUE_CONSTRAINT_VIOLATION, $error->getReason());
		}
		$this->assertSame(['sale', 'reversal'], $this->texts($this->entryMapper->getEntries($this->cashbook)));
	}

	public function testGetEntry(): void {
		$sale = $this->insertEntry('2025-01-10 10:00', EntryReason::IncomeSales, 'sale');
		$reversal = $this->insertReversal('2025-01-10 11:00', $sale, 'reversal');

		$loaded = $this->entryMapper->getEntry($this->cashbook, $sale->getId());
		$this->assertSame('sale', $loaded->getText());
		$this->assertSame($this->cashbook, $loaded->getCashbook());
		$this->assertSame(2, $loaded->getSupersededBySequence());
		$this->assertSame(1, $this->entryMapper->getEntry($this->cashbook, $reversal->getId())->getSupersededSequence());
	}

	public function testGetEntryOfOtherCashbook(): void {
		$sale = $this->insertEntry('2025-01-10 10:00', EntryReason::IncomeSales, 'sale');
		$other = new Cashbook();
		$other->setName('Other');
		$other->setAccount('');
		$other->setCurrency('EUR');
		$other->setOwner(self::USER_ID);
		$other = $this->cashbookMapper->insert($other);

		try {
			$this->expectException(DoesNotExistException::class);
			$this->entryMapper->getEntry($other, $sale->getId());
		} finally {
			$this->cashbookMapper->delete($other);
		}
	}

	public function testGetLastEntry(): void {
		$this->assertNull($this->entryMapper->getLastEntry($this->cashbook));

		$this->insertEntry('2025-01-03 10:00', EntryReason::IncomeSales, 'c', 2);
		$this->insertEntry('2025-01-03 10:00', EntryReason::IncomeSales, 'c2', 3);
		$this->insertEntry('2025-01-03 10:00', EntryReason::IncomeSales, 'a', 1);

		$last = $this->entryMapper->getLastEntry($this->cashbook);
		$this->assertSame('c2', $last?->getText());
		$this->assertSame($this->cashbook, $last->getCashbook());
	}

	public function testGetEntriesByDateWithoutDays(): void {
		$this->insertEntry('2025-01-31 23:59', null, 'before');
		$this->insertEntry('2025-02-01 00:00', null, 'first');
		$this->insertEntry('2025-02-15 12:00', null, 'middle');
		$this->insertEntry('2025-02-28 23:59', null, 'last');
		$this->insertEntry('2025-03-01 00:00', null, 'after');

		$entries = $this->entryMapper->getEntriesByDate(
			$this->cashbook,
			new \DateTimeImmutable('2025-02-01 00:00', new \DateTimeZone('UTC')),
			new \DateTimeImmutable('2025-03-01 00:00', new \DateTimeZone('UTC')),
		);
		$this->assertSame(['first', 'middle', 'last'], $this->texts($entries));
	}

	public function testGetEntriesByDateWithLimit(): void {
		$this->insertEntry('2025-02-01 10:00', null, 'a');
		$this->insertEntry('2025-02-02 10:00', null, 'b');
		$this->insertEntry('2025-02-03 10:00', null, 'c');

		$entries = $this->entryMapper->getEntriesByDate(
			$this->cashbook,
			new \DateTimeImmutable('2025-02-01 00:00', new \DateTimeZone('UTC')),
			new \DateTimeImmutable('2025-03-01 00:00', new \DateTimeZone('UTC')),
			2,
		);
		$this->assertSame(['a', 'b'], $this->texts($entries));
	}

	public static function dataGetEntriesByDateWithDays(): array {
		return [
			'extends to the whole first and last day' => [
				'2025-02-01 00:00', '2025-03-01 00:00',
				['open-1', 'sale-1', 'sale-2', 'close-1', 'open-2', 'sale-3', 'sale-4', 'sale-5', 'close-2'],
			],
			'does not extend if range starts and ends between days' => [
				'2025-02-02 00:00', '2025-02-27 00:00',
				[],
			],
			'range within a single day' => [
				'2025-02-28 00:00', '2025-02-28 12:00',
				['open-2', 'sale-3', 'sale-4', 'sale-5', 'close-2'],
			],
			'day that is still open' => [
				'2025-03-02 00:00', '2025-04-01 00:00',
				['open-3', 'sale-6'],
			],
		];
	}

	/**
	 * @param list<string> $expected
	 */
	#[DataProvider('dataGetEntriesByDateWithDays')]
	public function testGetEntriesByDateWithDays(string $begin, string $end, array $expected): void {
		$this->insertDays();

		$entries = $this->entryMapper->getEntriesByDate(
			$this->cashbook,
			new \DateTimeImmutable($begin, new \DateTimeZone('UTC')),
			new \DateTimeImmutable($end, new \DateTimeZone('UTC')),
		);
		$this->assertSame($expected, $this->texts($entries));
		foreach ($entries as $entry) {
			$this->assertSame($this->cashbook, $entry->getCashbook());
		}
	}
}
