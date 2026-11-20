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
 * `timezone` is the IANA timezone ID of the cashbook, its business days are calendar days in this timezone.
 *
 * @psalm-type CashbookCashbook = array{
 *     id: string,
 *     name: string,
 *     account: string,
 *     currency: string,
 *     owner: string,
 *     timezone: string,
 *     lastSequence: int,
 *     balance: string,
 * }
 *
 * @psalm-type CashbookEntryReason = 'day-opening'|'day-closing'|'income-bank'|'income-sales'|'income-misc'|'income-correction'|'expense-bank'|'expense-goods'|'expense-salary'|'expense-tips'|'expense-change'|'expense-refund'|'expense-misc'|'expense-correction'
 *
 * Amounts of money are decimal strings (e.g. `"12.50"`) to keep their precision,
 * `debit` is set for income and `credit` for expenses, both are omitted for entries without an amount.
 * An adjusting entry (`income-correction` or `expense-correction`) keeps the sign it was booked with,
 * so it is shown with a negative amount if it lowers the income or expense (e.g. `debit: "-0.50"`).
 * `referenceDate` is the date of the receipt (`YYYY-MM-DD`).
 * `sequence` is the gapless number of the entry within its cashbook, starting at 1.
 * A reversal negates the amount of the entry it reverses and is shown on the same side with a negative amount
 * (e.g. `debit: "-10.00"` for a reversed sale). `reverses` is the sequence number of the reversed entry
 * and `reversedBy` the sequence number of the reversal, both are null for entries without a reversal.
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
 *     reverses: ?int,
 *     reversedBy: ?int,
 * }
 *
 * @psalm-suppress UnusedClass Only used as a container for the response type definitions
 */
final class ResponseDefinitions {
}
