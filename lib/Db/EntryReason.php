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

	case IncomeBankTransfer = 'income-bank';
	case IncomeSales = 'income-sales';
	case IncomeMisc = 'income-misc';
	/** Adjusting entry on the income side, its amount is signed as booked (e.g. -0.50 to lower an income) */
	case IncomeCorrection = 'income-correction';

	case ExpenseBankTransfer = 'expense-bank';
	case ExpenseGoods = 'expense-goods';
	case ExpenseSalary = 'expense-salary';
	case ExpenseTips = 'expense-tips';
	case ExpenseChange = 'expense-change';
	case ExpenseRefund = 'expense-refund';
	case ExpenseMisc = 'expense-misc';
	/** Adjusting entry on the expense side, its amount is signed as booked (e.g. 0.50 to lower an expense) */
	case ExpenseCorrection = 'expense-correction';

	public function isIncome(): bool {
		return str_starts_with($this->value, 'income-');
	}

	public function isExpense(): bool {
		return str_starts_with($this->value, 'expense-');
	}

	/**
	 * Adjusting entries are imported, they can not be added manually.
	 */
	public function isCorrection(): bool {
		return $this === self::IncomeCorrection || $this === self::ExpenseCorrection;
	}
}
