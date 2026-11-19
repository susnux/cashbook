<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Controller;

use Brick\Math\BigRational;
use Brick\Money\Money;
use OCA\Cashbook\Controller\ApiController;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\Entry;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Db\EntryReason;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ImportService\ImportFormat;
use OCA\Cashbook\ImportService\SumupPosProGermany;
use OCA\Cashbook\Service\CashbookService;
use OCA\Cashbook\Service\EntryService;
use OCP\AppFramework\Http;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use OCP\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class ApiControllerTest extends TestCase {

	private CashbookMapper $cashbookMapper;
	private EntryMapper $entryMapper;
	/** @var list<Cashbook> */
	private array $cashbooks = [];

	#[\Override]
	protected function setUp(): void {
		parent::setUp();
		$this->cashbookMapper = Server::get(CashbookMapper::class);
		$this->entryMapper = Server::get(EntryMapper::class);
	}

	#[\Override]
	protected function tearDown(): void {
		$qb = Server::get(IDBConnection::class)->getQueryBuilder();
		$qb->delete('cashbook_cashbook_entries')
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter('alice')))
			->executeStatement();
		foreach ($this->cashbooks as $cashbook) {
			$this->cashbookMapper->delete($cashbook);
		}
		$this->cashbooks = [];
		parent::tearDown();
	}

	private function createController(?string $userId = 'alice', ?IRequest $request = null, ?ContainerInterface $container = null): ApiController {
		return new ApiController(
			$request ?? $this->createStub(IRequest::class),
			$container ?? $this->createStub(ContainerInterface::class),
			$this->createStub(LoggerInterface::class),
			$this->cashbookMapper,
			$this->entryMapper,
			Server::get(CashbookService::class),
			new EntryService(
				Server::get(IDBConnection::class),
				$this->createL10N(),
				Server::get(ITimeFactory::class),
				$this->cashbookMapper,
				$this->entryMapper,
			),
			$this->createL10N(),
			$userId,
		);
	}

	private function createL10N(): IL10N {
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => 'translated: ' . vsprintf($text, $parameters));
		return $l10n;
	}

	private function newCashbook(string $owner, string $name = 'Cashbook', string $timezone = 'UTC'): Cashbook {
		$cashbook = new Cashbook();
		$cashbook->setName($name);
		$cashbook->setAccount('1000');
		$cashbook->setCurrency('EUR');
		$cashbook->setTimezone($timezone);
		$cashbook->setOwner($owner);
		return $cashbook;
	}

	private function createCashbook(string $owner, string $name = 'Cashbook', string $timezone = 'UTC'): Cashbook {
		$cashbook = $this->cashbookMapper->insert($this->newCashbook($owner, $name, $timezone));
		$this->cashbooks[] = $cashbook;
		return $cashbook;
	}

	public function testIndexWithoutUser(): void {
		$this->createCashbook('alice');

		$response = $this->createController(null)->index();
		$this->assertSame([], $response->getData());
	}

	public function testIndex(): void {
		$cashbook = $this->createCashbook('alice', 'Shop');
		$this->createCashbook('bob');

		$response = $this->createController()->index();
		$this->assertSame([
			[
				'id' => $cashbook->getId(),
				'account' => '1000',
				'currency' => 'EUR',
				'name' => 'Shop',
				'owner' => 'alice',
				'timezone' => 'UTC',
				'lastSequence' => 0,
				'balance' => '0.00',
			],
		], $response->getData());
	}

	public function testGetCashbook(): void {
		$cashbook = $this->createCashbook('alice');

		$response = $this->createController()->getCashbook($cashbook->getId());
		$this->assertSame($cashbook->getId(), $response->getData()['id']);
	}

	public function testGetCashbookNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->createController()->getCashbook((string)PHP_INT_MAX);
	}

	public function testGetCashbookOfOtherUser(): void {
		$cashbook = $this->createCashbook('bob');

		$this->expectException(OCSNotFoundException::class);
		$this->createController()->getCashbook($cashbook->getId());
	}

	public function testGetCashbookEntries(): void {
		$cashbook = $this->createCashbook('alice');
		$balance = Money::of('0', 'EUR');
		for ($index = 0; $index < 3; $index++) {
			$balance = $balance->plus('1.00');
			$this->entryMapper->insert(Entry::create(
				$cashbook,
				$index + 1,
				'alice',
				new \DateTimeImmutable('2025-01-02 10:0' . $index, new \DateTimeZone('UTC')),
				'',
				EntryReason::IncomeSales,
				Money::of('1.00', 'EUR'),
				$balance,
			));
		}

		$response = $this->createController()->getCashbookEntries($cashbook->getId(), 1, 50);
		$this->assertSame(['2.00', '3.00'], array_column($response->getData(), 'balance'));
	}

	public function testImportCashbook(): void {
		$files = [
			'name' => ['cashbook' => 'Kassenbuch_a.csv', 'report' => 'Tagesabschluss_a.csv'],
			'tmp_name' => ['cashbook' => '/tmp/a.csv', 'report' => '/tmp/b.csv'],
			'error' => ['cashbook' => UPLOAD_ERR_OK, 'report' => UPLOAD_ERR_OK],
		];
		$request = $this->createMock(IRequest::class);
		$request->expects($this->once())
			->method('getUploadedFile')
			->with('files')
			->willReturn($files);

		$cashbook = $this->createCashbook('alice');
		$importer = $this->createMock(IImportService::class);
		$importer->expects($this->once())
			->method('import')
			->with(['cashbook' => '/tmp/a.csv', 'report' => '/tmp/b.csv'], ['timezone' => 'Europe/Berlin'])
			->willReturn($cashbook);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->once())
			->method('get')
			->with(SumupPosProGermany::class)
			->willReturn($importer);

		$response = $this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany, 'Europe/Berlin');
		$this->assertSame(['id' => $cashbook->getId()], $response->getData());
	}

	public function testImportCashbookInvalidOptions(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['cashbook' => 'Kassenbuch_a.csv'], 'tmp_name' => ['cashbook' => '/tmp/a.csv'], 'error' => ['cashbook' => UPLOAD_ERR_OK]]);
		$importer = $this->createStub(IImportService::class);
		$importer->method('import')->willThrowException(new \InvalidArgumentException('Invalid timezone option passed.'));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid timezone option passed.');
		$this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany, 'Mars/Olympus');
	}

	public function testImportCashbookSkipsEmptyFileInputs(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn([
			'name' => ['cashbook' => 'Kassenbuch_a.csv', 'report' => ''],
			'tmp_name' => ['cashbook' => '/tmp/a.csv', 'report' => ''],
			'error' => ['cashbook' => UPLOAD_ERR_OK, 'report' => UPLOAD_ERR_NO_FILE],
		]);
		$cashbook = $this->createCashbook('alice');
		$importer = $this->createMock(IImportService::class);
		$importer->expects($this->once())
			->method('import')
			->with(['cashbook' => '/tmp/a.csv'], [])
			->willReturn($cashbook);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$response = $this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany);
		$this->assertSame(['id' => $cashbook->getId()], $response->getData());
	}

	public static function dataImportCashbookFailedUpload(): array {
		$files = static fn (int ...$errors): array => [
			'name' => array_map(static fn (int $error): string => 'file.csv', $errors),
			'tmp_name' => array_map(static fn (int $error): string => '/tmp/file.csv', $errors),
			'error' => $errors,
		];
		return [
			'no upload' => [null, 'No files provided'],
			'not an upload' => ['files', 'No files provided'],
			'empty upload' => [$files(), 'No files provided'],
			'no file selected' => [$files(cashbook: UPLOAD_ERR_NO_FILE), 'No files provided'],
			'file too large' => [$files(cashbook: UPLOAD_ERR_OK, report: UPLOAD_ERR_INI_SIZE), 'translated: The uploaded file is too large.'],
			'form size exceeded' => [$files(cashbook: UPLOAD_ERR_FORM_SIZE), 'translated: The uploaded file is too large.'],
			'partial upload' => [$files(cashbook: UPLOAD_ERR_OK, report: UPLOAD_ERR_PARTIAL), 'translated: The upload of the files failed.'],
			'mismatching fields' => [['name' => ['cashbook' => 'a.csv', 'report' => 'b.csv'], 'tmp_name' => ['cashbook' => '/tmp/a.csv'], 'error' => ['cashbook' => UPLOAD_ERR_OK, 'report' => UPLOAD_ERR_OK]], 'Files need to be uploaded as files[<name>]'],
			'single file field' => [['name' => 'a.csv', 'tmp_name' => '/tmp/a.csv', 'error' => UPLOAD_ERR_OK], 'Files need to be uploaded as files[<name>]'],
			'unnamed files' => [['name' => ['a.csv'], 'tmp_name' => ['/tmp/a.csv'], 'error' => [UPLOAD_ERR_OK]], 'Files need to be uploaded as files[<name>]'],
			'nested files' => [['name' => ['cashbook' => ['a.csv']], 'tmp_name' => ['cashbook' => ['/tmp/a.csv']], 'error' => ['cashbook' => [UPLOAD_ERR_OK]]], 'Files need to be uploaded as files[<name>]'],
			'malformed file next to a failed upload' => [[
				'name' => ['cashbook' => 'a.csv', 'report' => 'b.csv'],
				'tmp_name' => ['cashbook' => '/tmp/a.csv', 'report' => ['/tmp/b.csv']],
				'error' => ['cashbook' => UPLOAD_ERR_INI_SIZE, 'report' => UPLOAD_ERR_OK],
			], 'Files need to be uploaded as files[<name>]'],
		];
	}

	#[DataProvider('dataImportCashbookFailedUpload')]
	public function testImportCashbookFailedUpload(mixed $upload, string $message): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn($upload);
		$importer = $this->createMock(IImportService::class);
		$importer->expects($this->never())->method('import');
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage($message);
		$this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany);
	}

	public function testImportCashbookInvalidFiles(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['cashbook' => 'Kassenbuch_a.csv'], 'tmp_name' => ['cashbook' => '/tmp/a.csv'], 'error' => ['cashbook' => UPLOAD_ERR_OK]]);
		$importer = $this->createStub(IImportService::class);
		$importer->method('import')->willThrowException(new InvalidInputException('English message', 'Translated message'));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Translated message');
		$this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany);
	}

	public function testGetCashbookEntriesPrintCollapsesSales(): void {
		$cashbook = $this->createCashbook('alice');
		$entries = [
			[null, '10.00', '19/100'],
			['', '5.00', '19/100'],
			[null, '1.00', '7/100'],
			['Voucher', '2.00', '7/100'],
			[null, '-3.00', null],
		];
		$balance = Money::of('100', 'EUR');
		foreach ($entries as $index => [$text, $amount, $tax]) {
			$balance = $balance->plus($amount);
			$entry = Entry::create(
				$cashbook,
				$index + 1,
				'alice',
				new \DateTimeImmutable('2025-01-02 10:0' . $index, new \DateTimeZone('UTC')),
				'',
				$tax === null ? EntryReason::ExpenseMisc : EntryReason::IncomeSales,
				Money::of($amount, 'EUR'),
				$balance,
				$tax === null ? null : BigRational::of($tax),
			);
			$entry->setText($text);
			$this->entryMapper->insert($entry);
		}

		$response = $this->createController()->getCashbookEntriesPrint($cashbook->getId(), '2025-01-02T00:00:00Z', '2025-01-03T00:00:00Z');
		$this->assertEquals([
			['debit' => '15.00', 'balance' => '115.00', 'text' => ''],
			['debit' => '1.00', 'balance' => '116.00', 'text' => ''],
			['debit' => '2.00', 'balance' => '118.00', 'text' => 'Voucher'],
			['credit' => '3.00', 'balance' => '115.00', 'text' => ''],
		], array_map(
			static fn (array $entry): array => array_intersect_key($entry, ['debit' => true, 'credit' => true, 'balance' => true, 'text' => true]),
			$response->getData(),
		));
	}

	public static function dataGetCashbookEntriesPrintDays(): array {
		return [
			'plain days in the timezone of the cashbook' => ['2025-01-02', '2025-01-03', [2, 3]],
			'date times with offset' => ['2025-01-02T00:00:00Z', '2025-01-03T00:00:00Z', [3, 4]],
		];
	}

	/**
	 * @param list<int> $sequences
	 */
	#[DataProvider('dataGetCashbookEntriesPrintDays')]
	public function testGetCashbookEntriesPrintDays(string $begin, string $end, array $sequences): void {
		// Midnight in Auckland (UTC+13 in January) is 11:00 UTC of the previous day
		$cashbook = $this->createCashbook('alice', timezone: 'Pacific/Auckland');
		$times = ['2025-01-01 10:59', '2025-01-01 11:00', '2025-01-02 10:59', '2025-01-02 11:00', '2025-01-03 00:00'];
		$balance = Money::of('0', 'EUR');
		foreach ($times as $index => $time) {
			$balance = $balance->plus('1');
			$this->entryMapper->insert(Entry::create(
				$cashbook,
				$index + 1,
				'alice',
				new \DateTimeImmutable($time, new \DateTimeZone('UTC')),
				'',
				EntryReason::IncomeMisc,
				Money::of('1', 'EUR'),
				$balance,
			));
		}

		$response = $this->createController()->getCashbookEntriesPrint($cashbook->getId(), $begin, $end);
		$this->assertSame($sequences, array_column($response->getData(), 'sequence'));
	}

	public function testGetCashbookEntriesPrintOfOtherUserWithInvalidDate(): void {
		$cashbook = $this->createCashbook('bob');

		$this->expectException(OCSNotFoundException::class);
		$this->createController()->getCashbookEntriesPrint($cashbook->getId(), 'invalid', 'invalid');
	}

	public function testGetCashbookEntriesPrintInvalidDate(): void {
		$cashbook = $this->createCashbook('alice');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid date param passed');
		$this->createController()->getCashbookEntriesPrint($cashbook->getId(), 'invalid', '2025-01-03');
	}

	public static function dataGetCashbookEntriesPrintLimit(): array {
		return [
			'at the limit' => [ApiController::MAX_PRINT_ENTRIES, true],
			'above the limit' => [ApiController::MAX_PRINT_ENTRIES + 1, false],
		];
	}

	#[DataProvider('dataGetCashbookEntriesPrintLimit')]
	public function testGetCashbookEntriesPrintLimit(int $count, bool $allowed): void {
		$cashbook = $this->createCashbook('alice');
		$amount = Money::of('1.00', 'EUR');
		$db = Server::get(IDBConnection::class);
		$db->beginTransaction();
		for ($index = 0; $index < $count; $index++) {
			$this->entryMapper->insert(Entry::create(
				$cashbook,
				$index + 1,
				'alice',
				new \DateTimeImmutable('2025-01-02 10:00', new \DateTimeZone('UTC')),
				'Sale ' . $index,
				EntryReason::IncomeSales,
				$amount,
				$amount,
			));
		}
		$db->commit();

		$controller = $this->createController();
		if (!$allowed) {
			$this->expectException(OCSBadRequestException::class);
			$this->expectExceptionMessage('translated: The period contains more than 20000 entries. Choose a shorter period.');
		}
		$response = $controller->getCashbookEntriesPrint($cashbook->getId(), '2025-01-02T00:00:00Z', '2025-01-03T00:00:00Z');
		$this->assertCount($count, $response->getData());
	}

	public function testAddCashbookEntry(): void {
		$cashbook = $this->createCashbook('alice');

		$response = $this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::IncomeBankTransfer, '100', 'Cash float', null, 'R-1', '2025-01-02');
		$data = $response->getData();
		$this->assertSame('100.00', $data['debit']);
		$this->assertSame('100.00', $data['balance']);
		$this->assertSame('Cash float', $data['text']);
		$this->assertSame('R-1', $data['reference']);
		$this->assertSame('2025-01-02', $data['referenceDate']);

		$response = $this->createController()->addCashbookEntry($cashbook->getId(), 1, EntryReason::ExpenseGoods, '30.5', tax: 0.19);
		$this->assertSame('69.50', $response->getData()['balance']);
		$this->assertSame(
			['100.00', '69.50'],
			array_column($this->createController()->getCashbookEntries($cashbook->getId())->getData(), 'balance'),
		);
	}

	public function testAddCashbookEntryUpdatesCashbook(): void {
		$cashbook = $this->createCashbook('alice');
		$this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::IncomeMisc, '12.5');

		$data = $this->createController()->getCashbook($cashbook->getId())->getData();
		$this->assertSame(1, $data['lastSequence']);
		$this->assertSame('12.50', $data['balance']);
		$this->assertSame([$data], $this->createController()->index()->getData());
	}

	public function testAddCashbookEntryConflict(): void {
		$cashbook = $this->createCashbook('alice');
		$this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::IncomeMisc, '10');

		$response = $this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::ExpenseMisc, '10');
		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame($this->createController()->getCashbook($cashbook->getId())->getData(), $response->getData());
		$this->assertSame(1, $response->getData()['lastSequence']);
		$this->assertSame('10.00', $response->getData()['balance']);
		$this->assertCount(1, $this->createController()->getCashbookEntries($cashbook->getId())->getData());
	}

	public function testAddCashbookEntryExceedingBalance(): void {
		$cashbook = $this->createCashbook('alice');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('translated: The expense exceeds the cash balance.');
		$this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::ExpenseGoods, '1');
	}

	public function testAddCashbookEntryInvalid(): void {
		$cashbook = $this->createCashbook('alice');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Only income and expense entries can be added.');
		$this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::DayOpening, '1');
	}

	public function testAddCashbookEntryNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->createController()->addCashbookEntry((string)PHP_INT_MAX, 0, EntryReason::IncomeMisc, '1');
	}

	public static function dataAddCashbookEntryOfOtherUser(): array {
		return [
			'other user' => ['alice'],
			'no user' => [null],
		];
	}

	#[DataProvider('dataAddCashbookEntryOfOtherUser')]
	public function testAddCashbookEntryOfOtherUser(?string $userId): void {
		$cashbook = $this->createCashbook('bob');

		try {
			$this->createController($userId)->addCashbookEntry($cashbook->getId(), 0, EntryReason::IncomeMisc, '1');
			$this->fail('Adding an entry to a foreign cashbook should fail');
		} catch (OCSNotFoundException) {
		}
		$this->assertNull($this->entryMapper->getLastEntry($cashbook));
	}

	public function testCreateCashbook(): void {
		$response = $this->createController()->createCashbook('Shop', 'EUR', '1000');
		$data = $response->getData();
		$this->cashbooks[] = $this->cashbookMapper->getCashbook($data['id']);

		$this->assertSame('Shop', $data['name']);
		$this->assertSame('alice', $data['owner']);
	}

	public function testCreateCashbookWithTimezone(): void {
		$response = $this->createController()->createCashbook('Shop', 'EUR', '', 'America/New_York');
		$data = $response->getData();
		$this->cashbooks[] = $this->cashbookMapper->getCashbook($data['id']);

		$this->assertSame('America/New_York', $data['timezone']);
		$this->assertSame('America/New_York', $this->cashbookMapper->getCashbook($data['id'])->getTimezone());
	}

	public function testCreateCashbookInvalidTimezone(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('The selected timezone is not supported.');
		$this->createController()->createCashbook('Shop', 'EUR', '', 'Etc/GMT-2');
	}

	public function testCreateCashbookEmptyName(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('The name of the cashbook must not be empty.');
		$this->createController()->createCashbook(' ', 'EUR');
	}

	public function testCreateCashbookInvalid(): void {
		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Unknown currency');
		$this->createController()->createCashbook('Shop', 'Euro');
	}

	public function testUpdateCashbook(): void {
		$cashbook = $this->createCashbook('alice', 'Shop', 'Europe/Berlin');
		$this->createController()->addCashbookEntry($cashbook->getId(), 0, EntryReason::IncomeMisc, '5');

		$response = $this->createController()->updateCashbook($cashbook->getId(), 'Market', '1200');
		$this->assertSame([
			'id' => $cashbook->getId(),
			'account' => '1200',
			'currency' => 'EUR',
			'name' => 'Market',
			'owner' => 'alice',
			'timezone' => 'Europe/Berlin',
			'lastSequence' => 1,
			'balance' => '5.00',
		], $response->getData());

		$stored = $this->cashbookMapper->getCashbook($cashbook->getId());
		$this->assertSame('Market', $stored->getName());
		$this->assertSame('1200', $stored->getAccount());
	}

	public function testUpdateCashbookEmptyName(): void {
		$cashbook = $this->createCashbook('alice');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('The name of the cashbook must not be empty.');
		$this->createController()->updateCashbook($cashbook->getId(), ' ');
	}

	public function testUpdateCashbookNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->createController()->updateCashbook((string)PHP_INT_MAX, 'Market');
	}

	public function testUpdateCashbookOfOtherUser(): void {
		$cashbook = $this->createCashbook('bob', 'Shop');

		try {
			$this->createController()->updateCashbook($cashbook->getId(), 'Market');
			$this->fail('Updating a foreign cashbook should fail');
		} catch (OCSNotFoundException) {
		}
		$this->assertSame('Shop', $this->cashbookMapper->getCashbook($cashbook->getId())->getName());
	}

	public function testDeleteCashbook(): void {
		$cashbook = $this->cashbookMapper->insert($this->newCashbook('alice'));

		$this->createController()->deleteCashbook($cashbook->getId());
		$this->assertSame([], $this->cashbookMapper->getCashbooksByOwner('alice'));
	}

	public function testDeleteCashbookNotFound(): void {
		$this->expectException(OCSNotFoundException::class);
		$this->createController()->deleteCashbook((string)PHP_INT_MAX);
	}

	public function testDeleteCashbookOfOtherUser(): void {
		$cashbook = $this->createCashbook('bob');

		try {
			$this->createController()->deleteCashbook($cashbook->getId());
			$this->fail('Deleting a foreign cashbook should fail');
		} catch (OCSNotFoundException) {
		}
		$this->assertSame($cashbook->getId(), $this->cashbookMapper->getCashbook($cashbook->getId())->getId());
	}
}
