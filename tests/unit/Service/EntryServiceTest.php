<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Service;

use Brick\Money\Money;
use InvalidArgumentException;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\ConflictException;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\Service\CashbookService;
use OCA\Cashbook\Service\EntryService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryServiceTest extends TestCase {

	private const string USER_ID = 'entry-service-test';
	private const string NOW = '2026-03-04 10:00:00';

	private EntryService $service;
	private EntryMapper $entryMapper;
	private Cashbook $cashbook;
	private int $sequence = 0;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->entryMapper = Server::get(EntryMapper::class);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		$timeFactory = $this->createStub(ITimeFactory::class);
		$timeFactory->method('now')->willReturn(new \DateTimeImmutable(self::NOW, new \DateTimeZone('Europe/Berlin')));

		$this->service = new EntryService(
			Server::get(IDBConnection::class),
			$l10n,
			$timeFactory,
			Server::get(CashbookMapper::class),
			$this->entryMapper,
		);
		$this->cashbook = Server::get(CashbookService::class)->create(self::USER_ID, 'Shop', 'EUR');
	}

	#[\Override]
	protected function tearDown(): void {
		Server::get(CashbookService::class)->deleteByOwner(self::USER_ID);
		parent::tearDown();
	}

	private function insertEntry(string $datetime, string $amount, string $balance, ?EntryReason $reason = EntryReason::IncomeSales): Entry {
		return $this->entryMapper->insert(Entry::create(
			$this->cashbook,
			++$this->sequence,
			self::USER_ID,
			new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')),
			'',
			$reason,
			Money::of($amount, 'EUR'),
			Money::of($balance, 'EUR'),
		));
	}

	private function lastSequence(): int {
		return $this->entryMapper->getLastEntry($this->cashbook)?->getSequence() ?? 0;
	}

	public function testAddFirstEntry(): void {
		$entry = $this->service->add(
			$this->cashbook,
			self::USER_ID,
			$this->lastSequence(),
			EntryReason::IncomeBankTransfer,
			' 150.5 ',
			' Cash float ',
			0.19,
			' R-1 ',
			'2026-03-01',
		);

		$this->assertSame([
			'id' => $entry->getId(),
			'sequence' => 1,
			'balance' => '150.50',
			'text' => 'Cash float',
			'date' => '2026-03-04T09:00:00+00:00',
			'reason' => 'income-bank',
			'reference' => 'R-1',
			'referenceDate' => '2026-03-01',
			'tax' => 0.19,
			'reverses' => null,
			'reversedBy' => null,
			'debit' => '150.50',
		], $entry->jsonSerialize());

		$stored = $this->entryMapper->getEntries($this->cashbook);
		$this->assertCount(1, $stored);
		$this->assertSame($entry->jsonSerialize(), $stored[0]->jsonSerialize());
		$this->assertSame(self::USER_ID, $stored[0]->getUserId());
	}

	public function testAddExpenseSubtractsFromLastBalance(): void {
		$this->insertEntry('2026-03-01 10:00', '20', '20');
		$this->insertEntry('2026-03-02 10:00', '30', '50');

		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::ExpenseGoods, '12.25');

		$data = $entry->jsonSerialize();
		$this->assertSame(3, $data['sequence']);
		$this->assertSame('37.75', $data['balance']);
		$this->assertSame('12.25', $data['credit']);
		$this->assertArrayNotHasKey('debit', $data);
		$this->assertSame('', $data['text']);
		$this->assertNull($data['tax']);
		$this->assertNull($data['reference']);
		$this->assertNull($data['referenceDate']);
	}

	public function testAddAfterEntryInTheFuture(): void {
		$this->insertEntry('2026-05-01 10:00', '20', '20');

		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::IncomeMisc, '5');

		$this->assertSame('2026-05-01T10:00:00+00:00', $entry->jsonSerialize()['date']);
		$this->assertSame(['20.00', '25.00'], array_map(
			static fn (Entry $entry): string => $entry->jsonSerialize()['balance'],
			$this->entryMapper->getEntries($this->cashbook),
		));
	}

	public function testAddExpenseExceedingBalance(): void {
		$this->insertEntry('2026-03-01 10:00', '10', '10');

		try {
			$this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::ExpenseMisc, '10.01');
			$this->fail('An expense exceeding the balance should be rejected');
		} catch (InvalidInputException $error) {
			$this->assertSame('The expense exceeds the cash balance.', $error->getMessage());
			$this->assertSame('translated: The expense exceeds the cash balance.', $error->getHint());
		}
		$this->assertCount(1, $this->entryMapper->getEntries($this->cashbook));

		// spending all cash is allowed
		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::ExpenseMisc, '10');
		$this->assertSame('0.00', $entry->jsonSerialize()['balance']);
	}

	public function testAddStoresTaxLikeTheImport(): void {
		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::IncomeSales, '1', tax: 0.07);
		$this->assertSame('7/100', $entry->getTaxRaw());

		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::IncomeSales, '1', tax: 0.075);
		$this->assertSame('3/40', $entry->getTaxRaw());

		$entry = $this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), EntryReason::IncomeSales, '1', tax: 0.0);
		$this->assertSame(0.0, $entry->jsonSerialize()['tax']);
	}

	public static function dataAddConflict(): array {
		return [
			'outdated' => [1],
			'ahead' => [3],
			'empty cashbook expected' => [0],
		];
	}

	#[DataProvider('dataAddConflict')]
	public function testAddConflict(int $lastSequence): void {
		$this->insertEntry('2026-03-01 10:00', '20', '20');
		$this->insertEntry('2026-03-02 10:00', '30', '50');

		try {
			$this->service->add($this->cashbook, self::USER_ID, $lastSequence, EntryReason::IncomeMisc, '1');
			$this->fail('An entry based on a different last entry should be rejected');
		} catch (ConflictException $error) {
			$this->assertSame('The cashbook was changed in the meantime.', $error->getMessage());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public function testAddToEmptyCashbookExpectingEntries(): void {
		$this->expectException(ConflictException::class);
		$this->service->add($this->cashbook, self::USER_ID, 1, EntryReason::IncomeMisc, '1');
	}

	public static function dataAddInvalid(): array {
		$long = static fn (int $length): string => str_repeat('a', $length);
		return [
			'day opening' => [EntryReason::DayOpening, '1', [], 'Only income and expense entries can be added.'],
			'day closing' => [EntryReason::DayClosing, '1', [], 'Only income and expense entries can be added.'],
			'correction' => [EntryReason::Correction, '1', [], 'Only income and expense entries can be added.'],
			'empty amount' => [EntryReason::IncomeMisc, '', [], 'The amount must be a positive decimal number.'],
			'zero amount' => [EntryReason::IncomeMisc, '0.00', [], 'The amount must be a positive decimal number.'],
			'negative amount' => [EntryReason::IncomeMisc, '-1', [], 'The amount must be a positive decimal number.'],
			'localized amount' => [EntryReason::IncomeMisc, '1,50', [], 'The amount must be a positive decimal number.'],
			'exponent' => [EntryReason::IncomeMisc, '1e3', [], 'The amount must be a positive decimal number.'],
			'too large amount' => [EntryReason::IncomeMisc, '1' . str_repeat('0', 18), [], 'The amount must be a positive decimal number.'],
			'too precise amount' => [EntryReason::IncomeMisc, '1.005', [], 'The amount has more decimal places than the currency.'],
			'negative tax' => [EntryReason::IncomeMisc, '1', ['tax' => -0.01], 'The tax rate must be between 0 and 1.'],
			'too large tax' => [EntryReason::IncomeMisc, '1', ['tax' => 1.01], 'The tax rate must be between 0 and 1.'],
			'infinite tax' => [EntryReason::IncomeMisc, '1', ['tax' => INF], 'The tax rate must be between 0 and 1.'],
			'too long text' => [EntryReason::IncomeMisc, '1', ['text' => $long(1001)], 'The text must not be longer than 1000 characters.'],
			'too long reference' => [EntryReason::IncomeMisc, '1', ['reference' => $long(128)], 'The reference must not be longer than 127 characters.'],
			'invalid date' => [EntryReason::IncomeMisc, '1', ['referenceDate' => '2026-02-30'], 'The receipt date must be a valid date (YYYY-MM-DD).'],
			'date with time' => [EntryReason::IncomeMisc, '1', ['referenceDate' => '2026-02-03T10:00'], 'The receipt date must be a valid date (YYYY-MM-DD).'],
			'localized date' => [EntryReason::IncomeMisc, '1', ['referenceDate' => '03.02.2026'], 'The receipt date must be a valid date (YYYY-MM-DD).'],
		];
	}

	/**
	 * @param array{text?: string, tax?: float, reference?: string, referenceDate?: string} $options
	 */
	#[DataProvider('dataAddInvalid')]
	public function testAddInvalid(EntryReason $reason, string $amount, array $options, string $message): void {
		try {
			$this->service->add($this->cashbook, self::USER_ID, $this->lastSequence(), $reason, $amount, ...$options);
			$this->fail('Invalid values should be rejected');
		} catch (InvalidArgumentException $error) {
			$this->assertSame($message, $error->getMessage());
		}
		$this->assertSame([], $this->entryMapper->getEntries($this->cashbook));
	}

	public function testAddAcceptsMaximumLengths(): void {
		$entry = $this->service->add(
			$this->cashbook,
			self::USER_ID,
			$this->lastSequence(),
			EntryReason::IncomeMisc,
			str_repeat('9', 18) . '.99',
			text: str_repeat('ä', 1000),
			reference: str_repeat('ü', 127),
		);
		$this->assertSame(str_repeat('9', 18) . '.99', $entry->jsonSerialize()['balance']);
	}

	public function testReverseSale(): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10', 'Coffee', 0.19, 'R-1', '2026-03-01');
		$this->service->add($this->cashbook, self::USER_ID, 1, EntryReason::IncomeMisc, '5');

		$reversal = $this->service->reverse($this->cashbook, 'bob', 2, $sale->getId(), ' Wrong amount ', ' S-1 ');

		$this->assertSame([
			'id' => $reversal->getId(),
			'sequence' => 3,
			'balance' => '5.00',
			'text' => 'Wrong amount',
			'date' => '2026-03-04T09:00:00+00:00',
			'reason' => 'income-sales',
			'reference' => 'S-1',
			'referenceDate' => null,
			'tax' => 0.19,
			'reverses' => 1,
			'reversedBy' => null,
			'debit' => '-10.00',
		], $reversal->jsonSerialize());
		$this->assertSame('bob', $reversal->getUserId());
		$this->assertSame('-10.00', $reversal->getAmountRaw());

		$stored = $this->entryMapper->getEntries($this->cashbook);
		$this->assertSame([3, null, null], array_map(static fn (Entry $entry): ?int => $entry->getSupersededBySequence(), $stored));
		$this->assertSame($reversal->jsonSerialize(), $stored[2]->jsonSerialize());
	}

	public function testReverseExpense(): void {
		$this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeMisc, '50');
		$expense = $this->service->add($this->cashbook, self::USER_ID, 1, EntryReason::ExpenseGoods, '20', 'Paper', 0.07);

		$data = $this->service->reverse($this->cashbook, self::USER_ID, 2, $expense->getId(), 'Returned')->jsonSerialize();

		$this->assertSame('50.00', $data['balance']);
		$this->assertSame('-20.00', $data['credit']);
		$this->assertArrayNotHasKey('debit', $data);
		$this->assertSame('expense-goods', $data['reason']);
		$this->assertSame(0.07, $data['tax']);
		$this->assertNull($data['reference']);
	}

	public function testReverseIncomeExceedingBalance(): void {
		$income = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeMisc, '10');
		$this->service->add($this->cashbook, self::USER_ID, 1, EntryReason::ExpenseMisc, '5');

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 2, $income->getId(), 'Typo');
			$this->fail('A reversal exceeding the balance should be rejected');
		} catch (InvalidInputException $error) {
			$this->assertSame('The reversal exceeds the cash balance.', $error->getMessage());
			$this->assertSame('translated: The reversal exceeds the cash balance.', $error->getHint());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public function testReverseAlreadyReversed(): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');
		$this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), 'Typo');

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 2, $sale->getId(), 'Typo again');
			$this->fail('An entry can only be reversed once');
		} catch (InvalidInputException $error) {
			$this->assertSame('The entry was already reversed.', $error->getMessage());
			$this->assertSame('translated: This entry was already reversed.', $error->getHint());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public static function dataReverseNotReversible(): array {
		return [
			'day opening' => [EntryReason::DayOpening],
			'day closing' => [EntryReason::DayClosing],
			'adjusting entry' => [EntryReason::Correction],
			'no reason' => [null],
		];
	}

	#[DataProvider('dataReverseNotReversible')]
	public function testReverseNotReversible(?EntryReason $reason): void {
		$this->insertEntry('2026-03-01 10:00', '20', '20');
		$entry = $this->insertEntry('2026-03-01 11:00', '5', '25', $reason);

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 2, $entry->getId(), 'Typo');
			$this->fail('Only income and expense entries can be reversed');
		} catch (InvalidArgumentException $error) {
			$this->assertSame('Only income and expense entries can be reversed.', $error->getMessage());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public function testReverseEntryWithoutAmount(): void {
		$this->insertEntry('2026-03-01 10:00', '20', '20');
		$entry = $this->insertEntry('2026-03-01 11:00', '0', '20');

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 2, $entry->getId(), 'Typo');
			$this->fail('Entries without an amount can not be reversed');
		} catch (InvalidArgumentException $error) {
			$this->assertSame('Entries without an amount can not be reversed.', $error->getMessage());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public function testReverseNegativeIncome(): void {
		$this->insertEntry('2026-03-01 10:00', '20', '20');
		$entry = $this->insertEntry('2026-03-01 11:00', '-5', '15');

		$data = $this->service->reverse($this->cashbook, self::USER_ID, 2, $entry->getId(), 'Typo')->jsonSerialize();

		$this->assertSame('20.00', $data['balance']);
		$this->assertSame('-5.00', $data['credit']);
		$this->assertArrayNotHasKey('debit', $data);
	}

	public function testReverseReversal(): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');
		$reversal = $this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), 'Typo');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Reversals can not be reversed.');
		$this->service->reverse($this->cashbook, self::USER_ID, 2, $reversal->getId(), 'Undo');
	}

	public function testReverseRequiresText(): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), " \t ");
			$this->fail('A reversal without a text should be rejected');
		} catch (InvalidInputException $error) {
			$this->assertSame('The reason for the reversal is missing.', $error->getMessage());
			$this->assertSame('translated: Enter the reason for the reversal.', $error->getHint());
		}
		$this->assertCount(1, $this->entryMapper->getEntries($this->cashbook));
	}

	public static function dataReverseInvalid(): array {
		return [
			'too long text' => [str_repeat('a', 1001), null, 'The text must not be longer than 1000 characters.'],
			'too long reference' => ['Typo', str_repeat('a', 128), 'The reference must not be longer than 127 characters.'],
		];
	}

	#[DataProvider('dataReverseInvalid')]
	public function testReverseInvalid(string $text, ?string $reference, string $message): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), $text, $reference);
	}

	public function testReverseConflict(): void {
		$sale = $this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');
		$this->service->add($this->cashbook, self::USER_ID, 1, EntryReason::IncomeSales, '5');

		try {
			$this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), 'Typo');
			$this->fail('A reversal based on a different last entry should be rejected');
		} catch (ConflictException $error) {
			$this->assertSame('The cashbook was changed in the meantime.', $error->getMessage());
		}
		$this->assertCount(2, $this->entryMapper->getEntries($this->cashbook));
	}

	public function testReverseEntryOfOtherCashbook(): void {
		$other = Server::get(CashbookService::class)->create(self::USER_ID, 'Other', 'EUR');
		$sale = $this->service->add($other, self::USER_ID, 0, EntryReason::IncomeSales, '10');
		$this->service->add($this->cashbook, self::USER_ID, 0, EntryReason::IncomeSales, '10');

		$this->expectException(DoesNotExistException::class);
		$this->service->reverse($this->cashbook, self::USER_ID, 1, $sale->getId(), 'Typo');
	}
}
