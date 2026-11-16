<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\Cashbook\Tests\Integration\IntegrationTestCase;
use Psr\Http\Message\ResponseInterface;

final class PageControllerTest extends IntegrationTestCase {

	public function testExportCashbook(): void {
		$alice = $this->createUser();
		$cashbook = $this->createCashbook($alice);

		$response = $this->pageRequest($alice, $cashbook['id'] . '/export');
		$this->assertSame(200, $response->getStatusCode());
	}

	public function testExportCashbookOfOtherUserIsNotAccessible(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();
		$cashbook = $this->createCashbook($alice);

		$this->assertRedirectsToNotFound($this->pageRequest($bob, $cashbook['id'] . '/export'));
		$this->assertRedirectsToNotFound($this->pageRequest($bob, (string)$cashbook['id']));
	}

	public function testExportMissingCashbook(): void {
		$alice = $this->createUser();

		$this->assertRedirectsToNotFound($this->pageRequest($alice, PHP_INT_MAX . '/export'));
	}

	/**
	 * @return array{id: int, ...}
	 */
	private function createCashbook(string $userId): array {
		/** @var array{id: int, ...} */
		return $this->assertOcsResponse(200, $this->ocsRequest($userId, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
	}

	private function assertRedirectsToNotFound(ResponseInterface $response): void {
		$this->assertSame(303, $response->getStatusCode());
		$this->assertStringEndsWith('/apps/cashbook/not-found', $response->getHeaderLine('Location'));
	}
}
