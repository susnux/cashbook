<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Listener;

use OCA\Cashbook\Service\CashbookService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/**
 * Delete the cashbooks of deleted users
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class UserDeletedListener implements IEventListener {

	/** @psalm-suppress PossiblyUnusedMethod Created by the DI container */
	public function __construct(
		private CashbookService $cashbookService,
	) {
	}

	/**
	 * @param UserDeletedEvent $event
	 */
	#[\Override]
	public function handle(Event $event): void {
		$this->cashbookService->deleteByOwner($event->getUser()->getUID());
	}
}
