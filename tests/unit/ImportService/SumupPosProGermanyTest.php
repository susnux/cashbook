<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\ImportService;

use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\ImportService\SumupPosProGermany;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SumupPosProGermanyTest extends TestCase {

	private const string USER_ID = 'sumup-import-test';
	private const string FIXTURES = __DIR__ . '/../../fixtures/sumup/';

	private IDBConnection $db;
	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->db = Server::get(IDBConnection::class);
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cashbook_cashbook_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter(self::USER_ID)))
			->executeStatement();
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cashbook_cashbooks')
			->where($qb->expr()->eq('owner', $qb->createNamedParameter(self::USER_ID)))
			->executeStatement();
		parent::tearDown();
	}

	private function createImporter(?LoggerInterface $logger = null): SumupPosProGermany {
		$timezone = $this->createMock(IDateTimeZone::class);
		$timezone->method('getTimeZone')->willReturn(new \DateTimeZone('Europe/Berlin'));
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));

		return new SumupPosProGermany(
			$timezone,
			self::USER_ID,
			$logger ?? $this->createMock(LoggerInterface::class),
			$l10n,
			$this->db,
			$this->cashbookMapper,
			$this->entryMapper,
		);
	}

	/**
	 * @return array{name: list<string>, tmp_name: list<string>}
	 */
	private function files(string ...$names): array {
		return [
			'name' => $names,
			'tmp_name' => array_map(static fn (string $name): string => self::FIXTURES . $name, $names),
		];
	}

	/**
	 * @return list<Entry>
	 */
	private function getEntries(Cashbook $cashbook): array {
		$entries = $this->entryMapper->getEntries($cashbook, 0, 50);
		usort($entries, static fn (Entry $a, Entry $b): int => $a->getId() <=> $b->getId());
		return $entries;
	}

	private function countImportedRows(string $table, string $column): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from($table)
			->where($qb->expr()->eq($column, $qb->createNamedParameter(self::USER_ID)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public static function dataImportWithoutReport(): array {
		return [
			'negative expenses' => ['Kassenbuch_basic.csv'],
			'positive expenses' => ['Kassenbuch_positive_expense.csv'],
		];
	}

	#[DataProvider('dataImportWithoutReport')]
	public function testImportWithoutReport(string $file): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->anything(), ['reason' => 'Unbekannter Grund']);

		$cashbook = $this->createImporter($logger)->import($this->files($file));

		$this->assertSame('Test Shop', $cashbook->getName());
		$this->assertSame('1234', $cashbook->getAccount());
		$this->assertSame('EUR', $cashbook->getCurrency());
		$this->assertSame(self::USER_ID, $cashbook->getOwner());

		$entries = $this->getEntries($cashbook);
		$this->assertCount(3, $entries);

		// Thousands separator
		$this->assertSame('1200.50', (string)$entries[0]->getAmount()->getAmount());
		$this->assertSame('1300.50', (string)$entries[0]->getBalance()->getAmount());
		$this->assertSame(EntryReason::IncomeSales, $entries[0]->getReason());
		$this->assertSame('19/100', (string)$entries[0]->getTax());
		$this->assertSame('2025-01-02T09:00:00+00:00', $entries[0]->getDatetime()->format(DATE_ATOM));

		// Expense without tax
		$this->assertSame('-20.00', (string)$entries[1]->getAmount()->getAmount());
		$this->assertSame('1280.50', (string)$entries[1]->getBalance()->getAmount());
		$this->assertSame(EntryReason::ExpenseGoods, $entries[1]->getReason());
		$this->assertSame('Paper', $entries[1]->getText());
		$this->assertNull($entries[1]->getTax());

		// Unknown reason
		$this->assertNull($entries[2]->getReason());
	}

	public function testImportWithReport(): void {
		$cashbook = $this->createImporter()->import($this->files('Kassenbuch_report.csv', 'Tagesabschluss_report.csv'));

		$entries = array_map(
			static fn (Entry $entry): array => [$entry->getReason(), (string)$entry->getAmount()->getAmount(), (string)$entry->getBalance()->getAmount()],
			$this->getEntries($cashbook),
		);
		$this->assertSame([
			[EntryReason::DayOpening, '0.00', '100.00'],
			[EntryReason::IncomeSales, '20.00', '120.00'],
			[EntryReason::ExpenseMisc, '-5.00', '115.00'],
			[EntryReason::DayClosing, '0.00', '115.00'],
			[EntryReason::DayOpening, '0.00', '115.00'],
			[EntryReason::IncomeSales, '10.00', '125.00'],
			[EntryReason::DayClosing, '0.00', '125.00'],
			// Entry after the last closing is imported as well
			[EntryReason::IncomeSales, '1.00', '126.00'],
		], $entries);
	}

	public function testImportWithTimezoneOption(): void {
		$cashbook = $this->createImporter()->import($this->files('Kassenbuch_basic.csv'), ['timezone' => 'UTC']);

		$this->assertSame('2025-01-02T10:00:00+00:00', $this->getEntries($cashbook)[0]->getDatetime()->format(DATE_ATOM));
	}

	public function testInvalidTimezoneOption(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Invalid timezone option passed.');
		$this->createImporter()->import($this->files('Kassenbuch_basic.csv'), ['timezone' => 'Mars/Olympus']);
	}

	public static function dataInvalidImport(): array {
		return [
			'no cashbook file' => [['Tagesabschluss_report.csv'], [], 'No cashbook export'],
			'unknown currency' => [['Kassenbuch_unknown_currency.csv'], [], 'unknown currency'],
			'start balance mismatch' => [['Kassenbuch_report.csv', 'Tagesabschluss_wrong_start.csv'], [], 'does not match the daily closing report'],
			'closing balance mismatch' => [['Kassenbuch_report.csv', 'Tagesabschluss_wrong_closing.csv'], [], 'day closing of 03.01.2025 18:00:00'],
			'invalid amount' => [['Kassenbuch_invalid_amount.csv'], [], 'invalid values'],
		];
	}

	/**
	 * @param list<string> $files
	 * @param array{timezone?: string} $options
	 */
	#[DataProvider('dataInvalidImport')]
	public function testInvalidImportLeavesNoData(array $files, array $options, string $message): void {
		try {
			$this->createImporter()->import($this->files(...$files), $options);
			$this->fail('Import should have failed');
		} catch (InvalidInputException $error) {
			$this->assertStringContainsString($message, $error->getMessage());
			$this->assertStringNotContainsString('translated', $error->getMessage());
			$this->assertSame('translated: ' . $error->getMessage(), $error->getHint());
		}

		$this->assertSame(0, $this->countImportedRows('cashbook_cashbooks', 'owner'));
		$this->assertSame(0, $this->countImportedRows('cashbook_cashbook_entries', 'user_id'));
	}
}
