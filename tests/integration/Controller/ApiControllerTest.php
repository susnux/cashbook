<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Integration\Controller;

use GuzzleHttp\RequestOptions;
use OCA\Cashbook\Tests\Integration\IntegrationTestCase;

final class ApiControllerTest extends IntegrationTestCase {

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
