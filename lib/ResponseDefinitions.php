<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook;

/**
 * @psalm-type CashbookCashbook = array{
 *     id: int,
 *     name: string,
 *     account: string,
 *     currency: string,
 *     owner: string,
 * }
 *
 * @psalm-type CashbookEntryReason = 'day-opening'|'day-closing'|'correction'|'income-bank'|'income-sales'|'income-misc'|'expense-bank'|'expense-goods'|'expense-salary'|'expense-tips'|'expense-change'|'expense-refund'|'expense-misc'
 *
 * Amounts of money are decimal strings (e.g. `"12.50"`) to keep their precision,
 * `debit` is set for income and `credit` for expenses, both are omitted for entries without an amount.
 * `referenceDate` is the date of the receipt (`YYYY-MM-DD`).
 *
 * @psalm-type CashbookEntry = array{
 *     id: int,
 *     balance: string,
 *     debit?: string,
 *     credit?: string,
 *     text: string,
 *     date: string,
 *     reason: ?CashbookEntryReason,
 *     reference: ?string,
 *     referenceDate: ?string,
 *     tax: ?float,
 * }
 *
 * @psalm-suppress UnusedClass Only used as a container for the response type definitions
 */
final class ResponseDefinitions {
}
