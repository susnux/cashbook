<?php

declare(strict_types=1);

/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Cashbook\Db;

/**
 * The reason (category) of a cashbook entry
 */
enum EntryReason: string {
	case DayOpening = 'day-opening';
	case DayClosing = 'day-closing';

	case Correction = 'correction';

	case IncomeBankTransfer = 'income-bank';
	case IncomeSales = 'income-sales';
	case IncomeMisc = 'income-misc';

	case ExpenseBankTransfer = 'expense-bank';
	case ExpenseGoods = 'expense-goods';
	case ExpenseSalary = 'expense-salary';
	case ExpenseTips = 'expense-tips';
	case ExpenseChange = 'expense-change';
	case ExpenseRefund = 'expense-refund';
	case ExpenseMisc = 'expense-misc';

	public function isIncome(): bool {
		return str_starts_with($this->value, 'income-');
	}

	public function isExpense(): bool {
		return str_starts_with($this->value, 'expense-');
	}
}
