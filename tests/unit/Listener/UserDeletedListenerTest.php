<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Listener;

use OCA\Cashbook\Db\CashbookMapper;
use OCA\Cashbook\Listener\UserDeletedListener;
use OCA\Cashbook\Service\CashbookService;
use OCP\IUser;
use OCP\Server;
use OCP\User\Events\UserDeletedEvent;
use PHPUnit\Framework\TestCase;

final class UserDeletedListenerTest extends TestCase {

	#[\Override]
	protected function tearDown(): void {
		$service = Server::get(CashbookService::class);
		$service->deleteByOwner('deleted-user');
		$service->deleteByOwner('remaining-user');
		parent::tearDown();
	}

	public function testDeletesCashbooksOfDeletedUser(): void {
		$service = Server::get(CashbookService::class);
		$mapper = Server::get(CashbookMapper::class);
		$service->create('deleted-user', 'Shop', 'EUR');
		$service->create('remaining-user', 'Shop', 'EUR');

		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn('deleted-user');
		Server::get(UserDeletedListener::class)->handle(new UserDeletedEvent($user));

		$this->assertSame([], $mapper->getCashbooksByOwner('deleted-user'));
		$this->assertCount(1, $mapper->getCashbooksByOwner('remaining-user'));
	}
}
