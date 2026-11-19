<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook;

/**
 * Ids are Snowflake IDs, sent as strings as they exceed the safe integer range of JavaScript.
 * `lastSequence` and `balance` of a cashbook are the sequence number and the cash balance after its last entry,
 * `0` and zero for a cashbook without entries.
 *
 * @psalm-type CashbookCashbook = array{
 *     id: string,
 *     name: string,
 *     account: string,
 *     currency: string,
 *     owner: string,
 *     lastSequence: int,
 *     balance: string,
 * }
 *
 * @psalm-type CashbookEntryReason = 'day-opening'|'day-closing'|'correction'|'income-bank'|'income-sales'|'income-misc'|'expense-bank'|'expense-goods'|'expense-salary'|'expense-tips'|'expense-change'|'expense-refund'|'expense-misc'
 *
 * Amounts of money are decimal strings (e.g. `"12.50"`) to keep their precision,
 * `debit` is set for income and `credit` for expenses, both are omitted for entries without an amount.
 * `referenceDate` is the date of the receipt (`YYYY-MM-DD`).
 * `sequence` is the gapless number of the entry within its cashbook, starting at 1.
 *
 * @psalm-type CashbookEntry = array{
 *     id: string,
 *     sequence: int,
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
