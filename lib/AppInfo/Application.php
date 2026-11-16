<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\AppInfo;

use OCA\Cashbook\Listener\UserDeletedListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\User\Events\UserDeletedEvent;

/**
 * @psalm-api Loaded by the server
 */
final class Application extends App implements IBootstrap {
	public const string APP_ID = 'cashbook';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	#[\Override]
	public function register(IRegistrationContext $context): void {
		// Register composer autoloader
		include_once __DIR__ . '/../../vendor/autoload.php';

		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
	}

	#[\Override]
	public function boot(IBootContext $context): void {
	}
}
