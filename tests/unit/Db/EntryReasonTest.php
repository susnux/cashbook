<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Db;

use OCA\Cashbook\Db\EntryReason;
use PHPUnit\Framework\TestCase;

final class EntryReasonTest extends TestCase {

	/**
	 * The `CashbookEntryReason` response type can not reference the enum (not supported by the OpenAPI extractor),
	 * so make sure the documented values stay in sync with the enum cases.
	 */
	public function testOpenApiSchemaMatchesEnum(): void {
		$openapi = json_decode(file_get_contents(__DIR__ . '/../../../openapi.json'), true, flags: JSON_THROW_ON_ERROR);

		$this->assertSame(
			array_column(EntryReason::cases(), 'value'),
			$openapi['components']['schemas']['EntryReason']['enum'],
		);
	}
}
