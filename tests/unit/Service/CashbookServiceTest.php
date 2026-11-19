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
use OCP\Config\IUserConfig;
use OCP\IDateTimeZone;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashbookServiceTest extends TestCase {

	private const string USER_ID = 'cashbook-service-test';
	/** A user whose timezone is a fixed offset instead of the timezone of a location */
	private const string USER_ID_OFFSET = 'cashbook-service-test-offset';
	/** The timezone setting of the users */
	private const array USER_TIMEZONES = [
		self::USER_ID => 'Europe/Berlin',
		self::USER_ID_OFFSET => 'Etc/GMT-1',
	];

	private CashbookService $service;
	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);
		$this->service = $this->createService('America/New_York');
	}

	/**
	 * @param string $serverTimezone The default timezone of the server
	 */
	private function createService(string $serverTimezone): CashbookService {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		$dateTimeZone = $this->createStub(IDateTimeZone::class);
		$dateTimeZone->method('getDefaultTimeZone')->willReturn(new \DateTimeZone($serverTimezone));
		$userConfig = $this->createStub(IUserConfig::class);
		$userConfig->method('getValueString')->willReturnCallback(
			static fn (string $userId, string $app, string $key): string => $app === 'core' && $key === 'timezone' ? (self::USER_TIMEZONES[$userId] ?? '') : '',
		);
		return new CashbookService(
			Server::get(IDBConnection::class),
			$l10n,
			$dateTimeZone,
			$userConfig,
			$this->cashbookMapper,
			$this->entryMapper,
		);
	}

	#[\Override]
	protected function tearDown(): void {
		$this->service->deleteByOwner(self::USER_ID);
		$this->service->deleteByOwner(self::USER_ID . '-other');
		$this->service->deleteByOwner(self::USER_ID_OFFSET);
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
		$this->assertSame('Europe/Berlin', $stored->getTimezone());
		$this->assertSame(self::USER_ID, $stored->getOwner());
	}

	public static function dataCreateWithTimezone(): array {
		return [
			'canonical' => ['America/New_York'],
			'UTC' => ['UTC'],
			'legacy name reported by browsers' => ['Europe/Kiev'],
		];
	}

	#[DataProvider('dataCreateWithTimezone')]
	public function testCreateWithTimezone(string $timezone): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR', '', $timezone);

		$stored = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame($timezone, $stored->getTimezone());
		$this->assertSame($timezone, $stored->getDateTimeZone()->getName());
	}

	public static function dataDefaultTimezone(): array {
		return [
			'timezone of the user' => [self::USER_ID, 'America/New_York', 'Europe/Berlin'],
			'fixed offset of the user' => [self::USER_ID_OFFSET, 'America/New_York', 'America/New_York'],
			'user without timezone' => [self::USER_ID . '-other', 'America/New_York', 'America/New_York'],
			'fixed offset of the server' => [self::USER_ID_OFFSET, 'Etc/GMT+5', 'UTC'],
		];
	}

	#[DataProvider('dataDefaultTimezone')]
	public function testCreateDefaultsToTimezoneOfTheUser(string $owner, string $serverTimezone, string $expected): void {
		$cashbook = $this->createService($serverTimezone)->create($owner, 'Shop', 'EUR');

		$this->assertSame($expected, $this->cashbookMapper->getCashbook($cashbook->getId())->getTimezone());
	}

	public function testBuildDoesNotStore(): void {
		$cashbook = $this->service->build(self::USER_ID, '  Shop ', 'usd', ' 1000 ');

		$this->assertNull($cashbook->getId());
		$this->assertSame('Shop', $cashbook->getName());
		$this->assertSame('1000', $cashbook->getAccount());
		$this->assertSame('USD', $cashbook->getCurrency());
		$this->assertSame('Europe/Berlin', $cashbook->getTimezone());
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
			'name too long' => [str_repeat('ä', 256), 'EUR', '', null, 'The name of the cashbook must not be longer than 255 characters.'],
			'account too long' => ['Shop', 'EUR', str_repeat('a', 256), null, 'The account must not be longer than 255 characters.'],
			'unknown currency' => ['Shop', 'ABC', '', null, 'Unknown currency.'],
			'currency name' => ['Shop', 'Euro', '', null, 'Unknown currency.'],
		];
	}

	#[DataProvider('dataCreateInvalid')]
	public function testCreateInvalid(string $name, string $currency, string $account, ?string $timezone, string $message): void {
		try {
			$this->service->create(self::USER_ID, $name, $currency, $account, $timezone);
			$this->fail('Creating the cashbook should have failed');
		} catch (\InvalidArgumentException $error) {
			$this->assertSame($message, $error->getMessage());
		}
		$this->assertSame([], $this->cashbookMapper->getCashbooksByOwner(self::USER_ID));
	}

	public static function dataCreateUnsupportedTimezone(): array {
		return [
			'unknown timezone' => ['Mars/Olympus'],
			'wrong case' => ['europe/berlin'],
			'empty' => [''],
			'offset' => ['+02:00'],
			'fixed offset zone' => ['Etc/GMT-1'],
			'UTC alias of the Etc area' => ['Etc/UTC'],
			'legacy fixed offset' => ['EST'],
			'legacy name without location' => ['GMT'],
		];
	}

	#[DataProvider('dataCreateUnsupportedTimezone')]
	public function testCreateUnsupportedTimezone(string $timezone): void {
		try {
			$this->service->create(self::USER_ID, 'Shop', 'EUR', '', $timezone);
			$this->fail('Creating the cashbook should have failed');
		} catch (InvalidInputException $error) {
			$this->assertSame('Unknown timezone.', $error->getMessage());
			$this->assertSame('translated: The selected timezone is not supported.', $error->getHint());
		}
		$this->assertSame([], $this->cashbookMapper->getCashbooksByOwner(self::USER_ID));
	}

	public function testUpdate(): void {
		$cashbook = $this->service->create(self::USER_ID, 'Shop', 'EUR', '1000', 'America/New_York');

		$this->service->update($cashbook, ' Market ', ' 1200 ');

		$stored = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame('Market', $stored->getName());
		$this->assertSame('1200', $stored->getAccount());
		$this->assertSame('EUR', $stored->getCurrency());
		$this->assertSame('America/New_York', $stored->getTimezone());
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
