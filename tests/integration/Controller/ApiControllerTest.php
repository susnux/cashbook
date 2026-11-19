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
		$this->assertIsString($cashbook['id']);
		$this->assertMatchesRegularExpression('/^\d+$/', $cashbook['id']);
		$this->assertSame([
			'id' => $cashbook['id'],
			'account' => '1000',
			'currency' => 'EUR',
			'name' => 'Shop',
			'owner' => $alice,
			'lastSequence' => 0,
			'balance' => '0.00',
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

	public function testAddCashbookEntries(): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
		$path = 'api/v1/cashbooks/' . $cashbook['id'] . '/entries';

		$income = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', $path, [
			RequestOptions::JSON => [
				'lastSequence' => 0,
				'reason' => 'income-bank',
				'amount' => '100.00',
				'text' => 'Cash float',
				'reference' => 'B-1',
				'referenceDate' => '2025-01-02',
			],
		]));
		$this->assertIsString($income['id']);
		$this->assertSame(1, $income['sequence']);
		$this->assertSame('100.00', $income['debit']);
		$this->assertSame('100.00', $income['balance']);
		$this->assertSame('Cash float', $income['text']);
		$this->assertSame('income-bank', $income['reason']);
		$this->assertSame('B-1', $income['reference']);
		$this->assertSame('2025-01-02', $income['referenceDate']);
		$this->assertNull($income['tax']);

		$expense = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', $path, [
			RequestOptions::JSON => ['lastSequence' => 1, 'reason' => 'expense-goods', 'amount' => '11.90', 'tax' => 0.19],
		]));
		$this->assertSame(2, $expense['sequence']);
		$this->assertSame('11.90', $expense['credit']);
		$this->assertSame('88.10', $expense['balance']);
		$this->assertSame(0.19, $expense['tax']);
		$this->assertNull($expense['reference']);
		$this->assertNull($expense['referenceDate']);

		$this->assertSame([$income, $expense], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', $path)));

		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id']));
		$this->assertSame(2, $cashbook['lastSequence']);
		$this->assertSame('88.10', $cashbook['balance']);
	}

	public function testAddCashbookEntryConflict(): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
		$path = 'api/v1/cashbooks/' . $cashbook['id'] . '/entries';
		$this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', $path, [
			RequestOptions::JSON => ['lastSequence' => 0, 'reason' => 'income-misc', 'amount' => '10'],
		]));

		// A second client still based on the empty cashbook
		$current = $this->assertOcsResponse(409, $this->ocsRequest($alice, 'POST', $path, [
			RequestOptions::JSON => ['lastSequence' => 0, 'reason' => 'expense-misc', 'amount' => '10'],
		]));
		$this->assertSame(
			$this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id'])),
			$current,
		);
		$this->assertSame(1, $current['lastSequence']);
		$this->assertSame('10.00', $current['balance']);
		$this->assertCount(1, $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', $path)));
	}

	public function testAddCashbookEntryRejectsNegativeLastSequence(): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
		$path = 'api/v1/cashbooks/' . $cashbook['id'] . '/entries';

		// The documented range is enforced by the framework, which responds without an OCS body
		$response = $this->ocsRequest($alice, 'POST', $path, [
			RequestOptions::JSON => ['lastSequence' => -1, 'reason' => 'income-misc', 'amount' => '1'],
		]);
		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', $path)));
	}

	public static function dataAddCashbookEntryInvalid(): array {
		return [
			'exceeds balance' => [['reason' => 'expense-misc', 'amount' => '0.01'], 'The expense exceeds the cash balance.'],
			'day opening' => [['reason' => 'day-opening', 'amount' => '1'], 'Only income and expense entries can be added.'],
			'negative amount' => [['reason' => 'income-misc', 'amount' => '-1'], 'The amount must be a positive decimal number.'],
			'invalid date' => [['reason' => 'income-misc', 'amount' => '1', 'referenceDate' => '2025-13-01'], 'The receipt date must be a valid date (YYYY-MM-DD).'],
		];
	}

	#[DataProvider('dataAddCashbookEntryInvalid')]
	public function testAddCashbookEntryInvalid(array $body, string $message): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));
		$path = 'api/v1/cashbooks/' . $cashbook['id'] . '/entries';

		$response = $this->ocsRequest($alice, 'POST', $path, [RequestOptions::JSON => $body + ['lastSequence' => 0]]);
		$this->assertOcsResponse(400, $response);
		$this->assertSame($message, json_decode((string)$response->getBody(), true)['ocs']['meta']['message']);
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', $path)));
	}

	public function testAddCashbookEntryUnknownReason(): void {
		$alice = $this->createUser();
		$cashbook = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks', [
			RequestOptions::JSON => ['name' => 'Shop', 'currency' => 'EUR'],
		]));

		// Unknown enum values are rejected by the framework, which responds without an OCS body
		$response = $this->ocsRequest($alice, 'POST', 'api/v1/cashbooks/' . $cashbook['id'] . '/entries', [
			RequestOptions::JSON => ['lastSequence' => 0, 'reason' => 'income-gift', 'amount' => '1'],
		]);
		$this->assertSame(400, $response->getStatusCode());
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
		$this->assertIsString($result['id']);

		$entries = $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $result['id'] . '/entries'));
		$this->assertSame([1, 2, 3], array_column($entries, 'sequence'));
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
		$this->assertOcsResponse(404, $this->ocsRequest($bob, 'POST', 'api/v1/cashbooks/' . $cashbook['id'] . '/entries', [
			RequestOptions::JSON => ['lastSequence' => 0, 'reason' => 'income-misc', 'amount' => '1'],
		]));
		$this->assertSame([], $this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id'] . '/entries')));

		$this->assertSame(
			$cashbook,
			$this->assertOcsResponse(200, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/' . $cashbook['id'])),
		);
	}

	public function testNonNumericCashbookIdIsNotFound(): void {
		$alice = $this->createUser();

		$this->assertSame(404, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/abc')->getStatusCode());
		$this->assertSame(404, $this->ocsRequest($alice, 'GET', 'api/v1/cashbooks/1x/entries')->getStatusCode());
	}
}
