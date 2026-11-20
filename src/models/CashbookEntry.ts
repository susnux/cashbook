/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

export enum EntryReason {
	DayOpening = 'day-opening',
	DayClosing = 'day-closing',

	IncomeBankTransfer = 'income-bank',
	IncomeMisc = 'income-misc',
	IncomeSales = 'income-sales',
	IncomeCorrection = 'income-correction',

	ExpenseBankTransfer = 'expense-bank',
	ExpenseGoods = 'expense-goods',
	ExpenseSalary = 'expense-salary',
	ExpenseTips = 'expense-tips',
	ExpenseChange = 'expense-change',
	ExpenseRefund = 'expense-refund',
	ExpenseMisc = 'expense-misc',
	ExpenseCorrection = 'expense-correction',
}

export const EntryReasonReadable: Record<EntryReason, string> = Object.freeze({
	[EntryReason.DayOpening]: t('cashbook', 'Day opening'),
	[EntryReason.DayClosing]: t('cashbook', 'Day closing'),

	[EntryReason.IncomeBankTransfer]: t('cashbook', 'Transfer from bank'),
	[EntryReason.IncomeMisc]: t('cashbook', 'Miscellaneous income'),
	[EntryReason.IncomeSales]: t('cashbook', 'Sales'),
	[EntryReason.IncomeCorrection]: t('cashbook', 'Adjusting entry'),

	[EntryReason.ExpenseBankTransfer]: t('cashbook', 'Transfer to bank'),
	[EntryReason.ExpenseChange]: t('cashbook', 'Change'),
	[EntryReason.ExpenseGoods]: t('cashbook', 'Purchases'),
	[EntryReason.ExpenseMisc]: t('cashbook', 'Miscellaneous expenses'),
	[EntryReason.ExpenseRefund]: t('cashbook', 'Refund'),
	[EntryReason.ExpenseSalary]: t('cashbook', 'Salaries'),
	[EntryReason.ExpenseTips]: t('cashbook', 'Tips'),
	[EntryReason.ExpenseCorrection]: t('cashbook', 'Adjusting entry'),
})

/** Reasons of income entries that can be added manually */
export const IncomeReasons: readonly EntryReason[] = Object.freeze([
	EntryReason.IncomeSales,
	EntryReason.IncomeBankTransfer,
	EntryReason.IncomeMisc,
])

/** Reasons of expense entries that can be added manually */
export const ExpenseReasons: readonly EntryReason[] = Object.freeze([
	EntryReason.ExpenseGoods,
	EntryReason.ExpenseBankTransfer,
	EntryReason.ExpenseSalary,
	EntryReason.ExpenseTips,
	EntryReason.ExpenseChange,
	EntryReason.ExpenseRefund,
	EntryReason.ExpenseMisc,
])

interface BaseEntry {
	/** The Snowflake ID, a string as it exceeds the safe integer range */
	id: string
	/** The gapless number of the entry within its cashbook, starting at 1 */
	sequence: number
	date: Date
	/** The balance after this entry as decimal string */
	balance: string
	text: string
	/** The receipt number */
	reference: string | null
	/** The date of the receipt (`YYYY-MM-DD`) */
	referenceDate: string | null
	/** The tax rate (e.g. `0.19` for 19%) */
	tax: number | null

	reason: EntryReason | null
	/** The sequence number of the entry this entry reverses */
	reverses: number | null
	/** The sequence number of the entry that reverses this entry */
	reversedBy: number | null
}

interface DebitEntry extends BaseEntry {
	/** The income as decimal string, negative for the reversal of an income or an adjusting entry lowering the income */
	debit: string
}

interface CreditEntry extends BaseEntry {
	/** The expense as decimal string, negative for the reversal of an expense or an adjusting entry lowering the expenses */
	credit: string
}

export type ICashbookEntry = DebitEntry | CreditEntry | BaseEntry

/**
 * Check if a reason is an income reason, including adjusting entries on the income side
 *
 * @param reason - The reason to check
 */
export function isIncomeReason(reason: EntryReason | null): boolean {
	return reason?.startsWith('income-') ?? false
}

/**
 * Check if a reason is an expense reason, including adjusting entries on the expense side
 *
 * @param reason - The reason to check
 */
export function isExpenseReason(reason: EntryReason | null): boolean {
	return reason?.startsWith('expense-') ?? false
}

/**
 * Check if an entry can be reversed: only income and expense entries with an amount that are neither a reversal nor reversed
 *
 * @param entry - The entry to check
 */
export function isReversible(entry: ICashbookEntry): boolean {
	return ('debit' in entry || 'credit' in entry)
		&& (isIncomeReason(entry.reason) || isExpenseReason(entry.reason))
		&& entry.reverses === null
		&& entry.reversedBy === null
}
