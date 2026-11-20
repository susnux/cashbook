<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Db;

use Brick\Math\BigRational;
use Brick\Money\Money;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryReason;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EntryTest extends TestCase {

	private function createEntry(string $amount, string $balance, ?string $text = 'Text', EntryReason $reason = EntryReason::IncomeSales): Entry {
		$cashbook = new Cashbook();
		$cashbook->setCurrency('EUR');

		$entry = Entry::create(
			$cashbook,
			7,
			'alice',
			new \DateTimeImmutable('2025-01-02 10:00', new \DateTimeZone('UTC')),
			'',
			$reason,
			Money::of($amount, 'EUR'),
			Money::of($balance, 'EUR'),
			BigRational::of('19/100'),
			'R-1',
		);
		$entry->generateId();
		$entry->setText($text);
		return $entry;
	}

	public function testJsonSerialize(): void {
		$entry = $this->createEntry('0.1', '9007199254740993.1');
		$this->assertSame([
			'id' => $entry->getId(),
			'sequence' => 7,
			'balance' => '9007199254740993.10',
			'text' => 'Text',
			'date' => '2025-01-02T10:00:00+00:00',
			'reason' => 'income-sales',
			'reference' => 'R-1',
			'referenceDate' => null,
			'tax' => 0.19,
			'reverses' => null,
			'reversedBy' => null,
			'debit' => '0.10',
		], $entry->jsonSerialize());
	}

	public function testJsonSerializeReferenceDate(): void {
		$entry = $this->createEntry('1', '1');
		$entry->setReferenceDate(new \DateTimeImmutable('2025-01-01', new \DateTimeZone('UTC')));
		$this->assertSame('2025-01-01', $entry->jsonSerialize()['referenceDate']);
		$this->assertArrayHasKey('referenceDate', $entry->getUpdatedFields());
	}

	public static function dataAmountSides(): array {
		return [
			'income' => ['12.5', ['debit' => '12.50']],
			'expense' => ['-12.5', ['credit' => '12.50']],
			'zero' => ['0', []],
		];
	}

	#[DataProvider('dataAmountSides')]
	public function testJsonSerializeAmountSides(string $amount, array $expected): void {
		$data = $this->createEntry($amount, '100')->jsonSerialize();
		$this->assertSame($expected, array_intersect_key($data, ['debit' => true, 'credit' => true]));
	}

	public function testCreateReversal(): void {
		$original = $this->createEntry('10', '10');
		$reversal = Entry::createReversal(
			$original->getCashbook(),
			$original,
			9,
			'bob',
			new \DateTimeImmutable('2025-02-03 10:00', new \DateTimeZone('UTC')),
			'Wrong amount',
			Money::of('5', 'EUR'),
			'S-1',
		);
		$reversal->generateId();

		$this->assertSame($original->getId(), $reversal->getSupersededId());
		$this->assertSame('bob', $reversal->getUserId());
		$this->assertSame([
			'id' => $reversal->getId(),
			'sequence' => 9,
			'balance' => '5.00',
			'text' => 'Wrong amount',
			'date' => '2025-02-03T10:00:00+00:00',
			'reason' => 'income-sales',
			'reference' => 'S-1',
			'referenceDate' => null,
			'tax' => 0.19,
			'reverses' => 7,
			'reversedBy' => null,
			'debit' => '-10.00',
		], $reversal->jsonSerialize());
	}

	public function testReversalOfExpenseIsNegativeCredit(): void {
		$original = $this->createEntry('-12.5', '0', reason: EntryReason::ExpenseGoods);
		$reversal = Entry::createReversal($original->getCashbook(), $original, 8, 'alice', new \DateTimeImmutable(), 'Text', Money::of('12.5', 'EUR'));

		$this->assertSame('12.50', (string)$reversal->getAmount()->getAmount());
		$data = $reversal->jsonSerialize();
		$this->assertSame('-12.50', $data['credit']);
		$this->assertArrayNotHasKey('debit', $data);
	}

	public function testReversalOfNegativeIncomeIsNegativeCredit(): void {
		// An imported income can be negative, it is shown as credit
		$original = $this->createEntry('-5', '0');
		$this->assertSame('5.00', $original->jsonSerialize()['credit']);

		$reversal = Entry::createReversal($original->getCashbook(), $original, 8, 'alice', new \DateTimeImmutable(), 'Text', Money::of('5', 'EUR'));

		$data = $reversal->jsonSerialize();
		$this->assertSame('income-sales', $data['reason']);
		$this->assertSame('-5.00', $data['credit']);
		$this->assertArrayNotHasKey('debit', $data);
	}

	public function testLinkedSequencesAreNotStored(): void {
		$original = $this->createEntry('10', '10');
		$reversal = Entry::createReversal($original->getCashbook(), $original, 8, 'alice', new \DateTimeImmutable(), 'Text', Money::of('0', 'EUR'));
		$reversal->setSupersededBySequence(9);

		$fields = $reversal->getUpdatedFields();
		$this->assertArrayHasKey('supersededId', $fields);
		$this->assertArrayNotHasKey('supersededSequence', $fields);
		$this->assertArrayNotHasKey('supersededBySequence', $fields);
	}

	public function testFromRowLoadsLinks(): void {
		$entry = Entry::fromRow(['superseded_id' => 7_000_000_000_000_000_001, 'superseded_sequence' => '3', 'superseded_by_sequence' => null]);
		$this->assertSame('7000000000000000001', $entry->getSupersededId());
		$this->assertSame(3, $entry->getSupersededSequence());
		$this->assertNull($entry->getSupersededBySequence());
	}

	public function testJsonSerializeWithoutText(): void {
		$this->assertSame('', $this->createEntry('1', '1', null)->jsonSerialize()['text']);
	}

	public function testReasonIsStoredInReasonColumn(): void {
		$entry = $this->createEntry('1', '1');
		$this->assertSame('reason', $entry->propertyToColumn('reasonRaw'));
		$this->assertSame('income-sales', $entry->getReasonRaw());
		$this->assertArrayHasKey('reasonRaw', $entry->getUpdatedFields());

		$entry = Entry::fromRow(['reason' => 'day-closing']);
		$this->assertSame(EntryReason::DayClosing, $entry->getReason());
	}

	public function testFromRowCastsIds(): void {
		$entry = Entry::fromRow(['id' => 7_000_000_000_000_000_001, 'cashbook_id' => 7_000_000_000_000_000_002, 'sequence' => '3']);
		$this->assertSame('7000000000000000001', $entry->getId());
		$this->assertSame('7000000000000000002', $entry->getCashbookId());
		$this->assertSame(3, $entry->getSequence());
	}

	public static function dataTax(): array {
		return [
			'lowest terms' => ['19/100', '19/100'],
			'one decimal' => ['190/1000', '19/100'],
			'two decimals' => ['1900/10000', '19/100'],
			'zero' => ['0/100', '0'],
		];
	}

	#[DataProvider('dataTax')]
	public function testTaxIsStoredInLowestTerms(string $tax, string $expected): void {
		$entry = $this->createEntry('1', '1');
		$entry->setTax(BigRational::of($tax));
		$this->assertSame($expected, $entry->getTaxRaw());
		$this->assertTrue($entry->getTax()?->isEqualTo($tax));
	}

	public function testTaxNull(): void {
		$entry = $this->createEntry('1', '1');
		$entry->setTax(null);
		$this->assertNull($entry->getTaxRaw());
		$this->assertNull($entry->getTax());
	}

	public function testReasonNull(): void {
		$entry = $this->createEntry('1', '1');
		$entry->setReason(null);
		$this->assertNull($entry->getReason());
		$this->assertNull($entry->getReasonRaw());
		$this->assertNull($entry->jsonSerialize()['reason']);
	}
}
