<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Service;

use Brick\Money\Money;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\Service\CashbookService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashbookServiceTest extends TestCase {

	private const string USER_ID = 'cashbook-service-test';

	private CashbookService $service;
	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);

		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		$this->service = new CashbookService(
			Server::get(IDBConnection::class),
			$l10n,
			$this->cashbookMapper,
			$this->entryMapper,
		);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->service->deleteByOwner(self::USER_ID);
		$this->service->deleteByOwner(self::USER_ID . '-other');
		parent::tearDown();
	}

	private function addEntry(Cashbook $cashbook): void {
		$this->entryMapper->insert(Entry::create(
			$cashbook,
			$this->countEntries($cashbook) + 1,
			self::USER_ID,
			new \DateTimeImmutable(),
			'',
			EntryReason::IncomeSales,
			Money::of('1', $cashbook->getCurrency()),
			Money::of('1', $cashbook->getCurrency()),
		));
	}

	private function countEntries(Cashbook $cashbook): int {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->select($qb->func()->count('*'))
			->from('cashbook_cashbook_entries')
			->where($qb->expr()->eq('cashbook_id', $qb->createNamedParameter($cashbook->getId())));
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testCreate(): void {
		$cashbook = $this->service->create(self::USER_ID, '  Shop ', 'usd', ' 1000 ');

		$stored = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame('Shop', $stored->getName());
		$this->assertSame('1000', $stored->getAccount());
		$this->assertSame('USD', $stored->getCurrency());
		$this->assertSame(self::USER_ID, $stored->getOwner());
	}

	public function testBuildDoesNotStore(): void {
		$cashbook = $this->service->build(self::USER_ID, '  Shop ', 'usd', ' 1000 ');

		$this->assertNull($cashbook->getId());
		$this->assertSame('Shop', $cashbook->getName());
		$this->assertSame('1000', $cashbook->getAccount());
		$this->assertSame('USD', $cashbook->getCurrency());
		$this->assertSame(self::USER_ID, $cashbook->getOwner());
		$this->assertSame([], $this->cashbookMapper->getCashbooksByOwner(self::USER_ID));
	}

	public function testCreateWithEmptyName(): void {
		try {
			$this->service->create(self::USER_ID, ' ', 'EUR');
			$this->fail('Creating the cashbook should have failed');
		} catch (InvalidInputException $error) {
			$this->assertSame('The name of the cashbook must not be empty.', $error->getMessage());
			$this->assertSame('translated: The name of the cashbook must not be empty.', $error->getHint());
		}
	}

	public static function dataCreateInvalid(): array {
		return [
			'name too long' => [str_repeat('ä', 256), 'EUR', '', 'The name of the cashbook must not be longer than 255 characters.'],
			'account too long' => ['Shop', 'EUR', str_repeat('a', 256), 'The account must not be longer than 255 characters.'],
			'unknown currency' => ['Shop', 'ABC', '', 'Unknown currency.'],
			'currency name' => ['Shop', 'Euro', '', 'Unknown currency.'],
		];
	}

	#[DataProvider('dataCreateInvalid')]
	public function testCreateInvalid(string $name, string $currency, string $account, string $message): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->create(self::USER_ID, $name, $currency, $account);
	}

	public function testUpdate(): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR', '1000');

		$this->service->update($cashbook, ' Market ', ' 1200 ');

		$stored = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame('Market', $stored->getName());
		$this->assertSame('1200', $stored->getAccount());
		$this->assertSame('EUR', $stored->getCurrency());
		$this->assertSame(self::USER_ID, $stored->getOwner());
	}

	public function testUpdateWithEmptyName(): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR');

		try {
			$this->service->update($cashbook, ' ');
			$this->fail('Updating the cashbook should have failed');
		} catch (InvalidInputException $error) {
			$this->assertSame('The name of the cashbook must not be empty.', $error->getMessage());
			$this->assertSame('translated: The name of the cashbook must not be empty.', $error->getHint());
		}
		$this->assertSame('Shop', $this->cashbookMapper->getCashbook($cashbook->getId())->getName());
	}

	public static function dataUpdateInvalid(): array {
		return [
			'name too long' => [str_repeat('ä', 256), '', 'The name of the cashbook must not be longer than 255 characters.'],
			'account too long' => ['Shop', str_repeat('a', 256), 'The account must not be longer than 255 characters.'],
		];
	}

	#[DataProvider('dataUpdateInvalid')]
	public function testUpdateInvalid(string $name, string $account, string $message): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->service->update($cashbook, $name, $account);
	}

	public function testDeleteRemovesEntries(): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR');
		$other = $this->service->create(self::USER_ID, 'Other', 'EUR');
		$this->addEntry($cashbook);
		$this->addEntry($cashbook);
		$this->addEntry($other);

		$this->service->delete($cashbook);

		$this->assertSame(0, $this->countEntries($cashbook));
		$this->assertSame(1, $this->countEntries($other));
		$this->expectException(DoesNotExistException::class);
		$this->cashbookMapper->getCashbook($cashbook->getId());
	}

	public function testDeleteByOwner(): void {
		$first = $this->service->create(self::USER_ID, 'First', 'EUR');
		$second = $this->service->create(self::USER_ID, 'Second', 'EUR');
		$foreign = $this->service->create(self::USER_ID . '-other', 'Foreign', 'EUR');
		$this->addEntry($first);
		$this->addEntry($second);
		$this->addEntry($foreign);

		$this->service->deleteByOwner(self::USER_ID);

		$this->assertSame([], $this->cashbookMapper->getCashbooksByOwner(self::USER_ID));
		$this->assertSame(0, $this->countEntries($first));
		$this->assertSame(0, $this->countEntries($second));
		$this->assertSame(1, $this->countEntries($foreign));
	}
}
