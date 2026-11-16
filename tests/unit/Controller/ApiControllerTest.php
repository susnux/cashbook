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
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\Server;
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
			$userId,
		);
	}

	private function newCashbook(string $owner, string $name = 'Cashbook'): Cashbook {
		$cashbook = new Cashbook();
		$cashbook->setName($name);
		$cashbook->setAccount('1000');
		$cashbook->setCurrency('EUR');
		$cashbook->setOwner($owner);
		return $cashbook;
	}

	private function createCashbook(string $owner, string $name = 'Cashbook'): Cashbook {
		$cashbook = $this->cashbookMapper->insert($this->newCashbook($owner, $name));
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
		$this->createController()->getCashbook(PHP_INT_MAX);
	}

	public function testGetCashbookOfOtherUser(): void {
		$cashbook = $this->createCashbook('bob');

		$this->expectException(OCSNotFoundException::class);
		$this->createController()->getCashbook($cashbook->getId());
	}

	public function testImportCashbook(): void {
		$files = ['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']];
		$request = $this->createMock(IRequest::class);
		$request->expects($this->once())
			->method('getUploadedFile')
			->with('files')
			->willReturn($files);

		$cashbook = $this->createCashbook('alice');
		$importer = $this->createMock(IImportService::class);
		$importer->expects($this->once())
			->method('import')
			->with($files, ['timezone' => 'Europe/Berlin'])
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
		$request->method('getUploadedFile')->willReturn(['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']]);
		$importer = $this->createStub(IImportService::class);
		$importer->method('import')->willThrowException(new \InvalidArgumentException('Invalid timezone option passed.'));
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid timezone option passed.');
		$this->createController('alice', $request, $container)->importCashbook(ImportFormat::SumupPosProGermany, 'Mars/Olympus');
	}

	public function testImportCashbookInvalidFiles(): void {
		$request = $this->createStub(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']]);
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

	public function testCreateCashbook(): void {
		$response = $this->createController()->createCashbook('Shop', 'EUR', '1000');
		$data = $response->getData();
		$this->cashbooks[] = $this->cashbookMapper->getCashbook($data['id']);

		$this->assertSame('Shop', $data['name']);
		$this->assertSame('alice', $data['owner']);
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
		$cashbook = $this->createCashbook('alice', 'Shop');

		$response = $this->createController()->updateCashbook($cashbook->getId(), 'Market', '1200');
		$this->assertSame([
			'id' => $cashbook->getId(),
			'account' => '1200',
			'currency' => 'EUR',
			'name' => 'Market',
			'owner' => 'alice',
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
		$this->createController()->updateCashbook(PHP_INT_MAX, 'Market');
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
		$this->createController()->deleteCashbook(PHP_INT_MAX);
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
