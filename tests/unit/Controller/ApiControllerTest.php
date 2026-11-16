<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Controller;

use OCA\Cashbook\Controller\ApiController;
use OCA\Cashbook\Db\Cashbook;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Db\EntryMapper;
use OCA\Cashbook\Exception\InvalidInputException;
use OCA\Cashbook\ImportService\IImportService;
use OCA\Cashbook\ImportService\SumupPosProGermany;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\AppFramework\OCS\OCSNotFoundException;
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
		foreach ($this->cashbooks as $cashbook) {
			$this->cashbookMapper->delete($cashbook);
		}
		$this->cashbooks = [];
		parent::tearDown();
	}

	private function createController(?string $userId = 'alice', ?IRequest $request = null, ?ContainerInterface $container = null): ApiController {
		return new ApiController(
			$request ?? $this->createMock(IRequest::class),
			$container ?? $this->createMock(ContainerInterface::class),
			$this->createMock(LoggerInterface::class),
			$this->cashbookMapper,
			$this->entryMapper,
			$userId,
		);
	}

	private function createCashbook(string $owner, string $name = 'Cashbook'): Cashbook {
		$cashbook = new Cashbook();
		$cashbook->setName($name);
		$cashbook->setAccount('1000');
		$cashbook->setCurrency('EUR');
		$cashbook->setOwner($owner);
		$cashbook = $this->cashbookMapper->insert($cashbook);
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

	public static function dataInvalidPagination(): array {
		return [
			'negative offset' => [-1, 20],
			'zero limit' => [0, 0],
			'limit too large' => [0, 51],
		];
	}

	#[DataProvider('dataInvalidPagination')]
	public function testGetCashbookEntriesInvalidPagination(int $offset, int $limit): void {
		$cashbook = $this->createCashbook('alice');

		$this->expectException(OCSBadRequestException::class);
		$this->createController()->getCashbookEntries($cashbook->getId(), $offset, $limit);
	}

	public static function dataUnsupportedImportFormat(): array {
		return [
			'unknown identifier' => ['unknown'],
			'class name' => [SumupPosProGermany::class],
		];
	}

	#[DataProvider('dataUnsupportedImportFormat')]
	public function testImportCashbookUnsupportedFormat(string $import): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['a.csv'], 'tmp_name' => ['/tmp/a.csv']]);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->expectException(OCSBadRequestException::class);
		$this->createController('alice', $request, $container)->importCashbook($import);
	}

	public function testImportCashbook(): void {
		$files = ['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']];
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->with('files')->willReturn($files);

		$cashbook = $this->createCashbook('alice');
		$importer = $this->createMock(IImportService::class);
		$importer->expects($this->once())
			->method('import')
			->with($files, ['timezone' => 'Europe/Berlin'])
			->willReturn($cashbook);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(SumupPosProGermany::class)->willReturn($importer);

		$response = $this->createController('alice', $request, $container)->importCashbook('sumup-pos-pro-de', 'Europe/Berlin');
		$this->assertSame(['id' => $cashbook->getId()], $response->getData());
	}

	public function testImportCashbookInvalidOptions(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']]);
		$importer = $this->createMock(IImportService::class);
		$importer->method('import')->willThrowException(new \InvalidArgumentException('Invalid timezone option passed.'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Invalid timezone option passed.');
		$this->createController('alice', $request, $container)->importCashbook('sumup-pos-pro-de', 'Mars/Olympus');
	}

	public function testImportCashbookInvalidFiles(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getUploadedFile')->willReturn(['name' => ['Kassenbuch_a.csv'], 'tmp_name' => ['/tmp/a.csv']]);
		$importer = $this->createMock(IImportService::class);
		$importer->method('import')->willThrowException(new InvalidInputException('English message', 'Translated message'));
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($importer);

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Translated message');
		$this->createController('alice', $request, $container)->importCashbook('sumup-pos-pro-de');
	}
}
