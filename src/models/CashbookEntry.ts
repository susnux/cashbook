/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { t } from '@nextcloud/l10n'

export enum EntryReason {
	DayOpening = 'day-opening',
	DayClosing = 'day-closing',
	Correction = 'correction',

	IncomeBankTransfer = 'income-bank',
	IncomeMisc = 'income-misc',
	IncomeSales = 'income-sales',

	ExpenseBankTransfer = 'expense-bank',
	ExpenseGoods = 'expense-goods',
	ExpenseSalary = 'expense-salary',
	ExpenseTips = 'expense-tips',
	ExpenseChange = 'expense-change',
	ExpenseRefund = 'expense-refund',
	ExpenseMisc = 'expense-misc',
}

export const EntryReasonReadable: Record<EntryReason, string> = Object.freeze({
	[EntryReason.DayOpening]: t('cashbook', 'Day opening'),
	[EntryReason.DayClosing]: t('cashbook', 'Day closing'),
	[EntryReason.Correction]: t('cashbook', 'Adjusting entry'),

	[EntryReason.IncomeBankTransfer]: t('cashbook', 'Adjusting entry'),
	[EntryReason.IncomeMisc]: t('cashbook', 'Miscellaneous income'),
	[EntryReason.IncomeSales]: t('cashbook', 'Sales'),

	[EntryReason.ExpenseBankTransfer]: t('cashbook', 'Transfer to bank'),
	[EntryReason.ExpenseChange]: t('cashbook', 'Change'),
	[EntryReason.ExpenseGoods]: t('cashbook', 'Purchases'),
	[EntryReason.ExpenseMisc]: t('cashbook', 'Miscellaneous expanses'),
	[EntryReason.ExpenseRefund]: t('cashbook', 'Refund'),
	[EntryReason.ExpenseSalary]: t('cashbook', 'Salaries'),
	[EntryReason.ExpenseTips]: t('cashbook', 'Tips'),
})

interface BaseEntry {
	id: number
	date: Date
	/** The balance after this entry as decimal string */
	balance: string
	text: string
	reference: string | null
	/** The tax rate (e.g. `0.19` for 19%) */
	tax: number | null

	reason: EntryReason | null

	/** Entry that amends this entry */
	amendment?: number
	/** Entry that is amended by this entry */
	original?: number
}

interface DebitEntry extends BaseEntry {
	/** The income as decimal string */
	debit: string
}

interface CreditEntry extends BaseEntry {
	/** The expense as decimal string */
	credit: string
}

export type ICashbookEntry = DebitEntry | CreditEntry | BaseEntry
