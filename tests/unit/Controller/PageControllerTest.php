<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Controller;

use OCA\Cashbook\Controller\PageController;
use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Service\CashbookService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Server;
use PHPUnit\Framework\TestCase;

final class PageControllerTest extends TestCase {

	private const string USER_ID = 'page-controller-test';

	#[\Override]
	protected function tearDown(): void {
		Server::get(CashbookService::class)->deleteByOwner(self::USER_ID);
		parent::tearDown();
	}

	private function createController(): PageController {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')
			->willReturnCallback(static fn (string $route): string => 'https://cloud.example.com/' . $route);

		return new PageController(
			'cashbook',
			$this->createStub(IRequest::class),
			self::USER_ID,
			$urlGenerator,
			Server::get(CashbookMapper::class),
		);
	}

	public function testNotFound(): void {
		$response = $this->createController()->notFound();
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
	}

	public function testShowCashbook(): void {
		$cashbook = Server::get(CashbookService::class)->create(self::USER_ID, 'Shop', 'EUR');

		$response = $this->createController()->showCashbook($cashbook->getId());
		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('main', $response->getTemplateName());
	}

	public function testShowCashbookOfOtherUser(): void {
		$cashbook = Server::get(CashbookService::class)->create(self::USER_ID . '-other', 'Shop', 'EUR');

		try {
			$response = $this->createController()->showCashbook($cashbook->getId());
		} finally {
			Server::get(CashbookService::class)->delete($cashbook);
		}
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://cloud.example.com/cashbook.page.notFound', $response->getRedirectURL());
	}

	public function testShowMissingCashbook(): void {
		$response = $this->createController()->showCashbook(PHP_INT_MAX);
		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://cloud.example.com/cashbook.page.notFound', $response->getRedirectURL());
	}
}
