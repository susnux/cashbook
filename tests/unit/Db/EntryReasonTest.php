<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Tests\Unit\Db;

use OCA\Cashbook\Db\EntryReason;
use PHPUnit\Framework\Attributes\DataProvider;
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

	public static function dataDirection(): array {
		return array_map(
			static fn (EntryReason $reason): array => [
				$reason,
				str_starts_with($reason->name, 'Income'),
				str_starts_with($reason->name, 'Expense'),
				str_ends_with($reason->name, 'Correction'),
			],
			array_combine(array_column(EntryReason::cases(), 'value'), EntryReason::cases()),
		);
	}

	#[DataProvider('dataDirection')]
	public function testDirection(EntryReason $reason, bool $income, bool $expense, bool $correction): void {
		$this->assertSame($income, $reason->isIncome());
		$this->assertSame($expense, $reason->isExpense());
		$this->assertSame($correction, $reason->isCorrection());
	}
}
