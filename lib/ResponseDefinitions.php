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
 * @psalm-type CashbookEntry = array{
 *     id: int,
 *     balance: float,
 *     debit?: float,
 *     credit?: float,
 *     text: string,
 *     date: string,
 *     reason: CashbookEntryReason,
 *     reference: ?string,
 *     tax: ?float,
 * }
 *
 * @psalm-suppress UnusedClass Only used as a container for the response type definitions
 */
final class ResponseDefinitions {
}
