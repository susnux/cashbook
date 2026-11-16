<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\Cashbook\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ApiControllerTest extends IntegrationTestCase {

	private const string FIXTURES = __DIR__ . '/../../fixtures/sumup/';

	public function testCreateCashbook(): void {
		$alice = $this->createUser();

		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR', 'account' => '1000'],
		]));
		$this->assertIsInt($cashbook['id']);
		$this->assertSame([
			'id' => $cashbook['id'],
			'account' => '1000',
			'currency' => 'EUR',
			'name' => 'Shop',
			'owner' => $alice,
		], $cashbook);

		$this->assertSame(
			$cashbook,
			$this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id'])),
		);
		$this->assertSame(
			[$cashbook],
			$this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks')),
		);
	}

	public function testGetCashbookEntriesRejectsOutOfRangePagination(): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
		$path = 'api/v1/cashbooks/' . $cashbook['id'] . '/entries';

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', $path . '?offset=0&limit=50')));
		// The documented ranges are enforced by the framework, which responds without an OCS body
		foreach (['offset=0&limit=51', 'offset=0&limit=0', 'offset=-1&limit=20'] as $query) {
			$this->assertSame(400, $this->ocsRequest($alice, 'GET', $path . '?' . $query)->getStatusCode(), $query);
		}
	}

	public static function dataImportCashbook(): array {
		return [
			'multi-file field' => ['files[]'],
			'single file field' => ['files'],
		];
	}

	#[DataProvider('dataImportCashbook')]
	public function testImportCashbook(string $field): void {
		$alice = $this->createUser();

		$result = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks/import', [
			RequestOptions::MULTIPART => [
				['name' => 'import', 'contents' => 'sumup-pos-pro-de'],
				['name' => 'timezone', 'contents' => 'UTC'],
				['name' => $field, 'contents' => fopen(self::FIXTURES . 'Kassenbuch_basic.csv', 'r'), 'filename' => 'Kassenbuch_basic.csv'],
			],
		]));
		$this->assertIsInt($result['id']);

		$entries = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $result['id'] . '/entries'));
		$this->assertSame(['1300.50', '1280.50', '1285.50'], array_column($entries, 'balance'));
	}

	public function testImportCashbookWithoutFile(): void {
		$alice = $this->createUser();

		$this->assertOcsResponse(400, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks/import', [
			RequestOptions::MULTIPART => [
				['name' => 'import', 'contents' => 'sumup-pos-pro-de'],
				// An empty file input is sent without a file name
				['name' => 'files[]', 'contents' => '', 'filename' => ''],
			],
		]));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks')));
	}

	public function testCashbookOfOtherUserIsNotAccessible(): void {
		$alice = $this->createUser();
		$bob = $this->createUser();

		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));

		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($bob, 'GET', 'api/v1/cashbooks')));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'GET', 'api/v1/cashbooks/' . $cashbook['id']));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'GET', 'api/v1/cashbooks/' . $cashbook['id'] . '/entries'));
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'DELETE', 'api/v1/cashbooks/' . $cashbook['id']));

		$this->assertSame(
			$cashbook,
			$this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id'])),
		);
	}
}
