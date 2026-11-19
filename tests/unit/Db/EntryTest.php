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

	private function createEntry(string $amount, string $balance, ?string $text = 'Text'): Entry {
		$cashbook = new Cashbook();
		$cashbook->setCurrency('EUR');

		$entry = Entry::create(
			$cashbook,
			7,
			'alice',
			new \DateTimeImmutable('2025-01-02 10:00', new \DateTimeZone('UTC')),
			'',
			EntryReason::IncomeSales,
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
