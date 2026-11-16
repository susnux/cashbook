/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { describe, expect, it } from 'vitest'
import { EntryReason } from '../models/CashbookEntry.ts'
import { calculateTotals } from './totals.ts'

/**
 * Create a cashbook entry
 *
 * @param balance - The balance after the entry
 * @param amount - Optional debit (positive) or credit (negative, with leading `-`) amount
 * @param tax - The tax rate
 */
function entry(balance: string, amount?: string, tax: number | null = null): ICashbookEntry {
	const base = { id: 1, reason: EntryReason.IncomeSales, date: new Date(2025, 0, 2), balance, text: '', reference: null, referenceDate: null, tax }
	if (amount === undefined) {
		return { ...base, reason: EntryReason.DayOpening }
	}
	return amount.startsWith('-') ? { ...base, credit: amount.slice(1) } : { ...base, debit: amount }
}

describe('calculateTotals', () => {
	it('returns zero totals without entries', () => {
		expect(calculateTotals([])).toEqual({
			openingBalance: null,
			closingBalance: null,
			income: '0',
			expenses: '0',
			incomeTax: '0',
			expensesTax: '0',
			taxes: [],
		})
	})

	it('sums income, expenses and included taxes per rate', () => {
		const totals = calculateTotals([
			entry('100.00'),
			entry('110.00', '10.00', 0.19),
			entry('130.00', '20.00', 0.19),
			entry('125.00', '-5.00'),
			entry('135.00', '10.00', 0.07),
			entry('136.00', '1.00', 0.07),
			entry('124.10', '-11.90', 0.19),
		])

		expect(totals).toEqual({
			openingBalance: '100.00',
			closingBalance: '124.10',
			income: '41.00',
			expenses: '16.90',
			incomeTax: '5.51',
			expensesTax: '1.90',
			taxes: [
				{
					rate: 0.19,
					income: { gross: '30.00', net: '25.21', tax: '4.79' },
					expenses: { gross: '11.90', net: '10.00', tax: '1.90' },
				},
				{
					rate: 0.07,
					income: { gross: '11.00', net: '10.28', tax: '0.72' },
					expenses: { gross: '0.00', net: '0.00', tax: '0.00' },
				},
				{
					rate: null,
					income: { gross: '0.00', net: null, tax: null },
					expenses: { gross: '5.00', net: null, tax: null },
				},
			],
		})
	})

	it('calculates the opening balance from the first entry', () => {
		const totals = calculateTotals([entry('110.00', '10.00'), entry('105.00', '-5.00')])
		expect(totals.openingBalance).toBe('100.00')
		expect(totals.closingBalance).toBe('105.00')
	})

	it('rounds the tax half away from zero', () => {
		expect(calculateTotals([entry('0.05', '0.05', 1)]).incomeTax).toBe('0.03')
		expect(calculateTotals([entry('0.04', '0.04', 1)]).incomeTax).toBe('0.02')
	})

	it('sums amounts without losing precision', () => {
		const totals = calculateTotals([
			entry('9007199254740993.10', '9007199254740993.10'),
			entry('9007199254740993.35', '0.25'),
		])
		expect(totals.income).toBe('9007199254740993.35')
	})

	it('uses the highest precision of all amounts', () => {
		expect(calculateTotals([entry('1.5', '1.5'), entry('1.75', '0.25')]).income).toBe('1.75')
	})

	it('supports negative balances', () => {
		const totals = calculateTotals([entry('-0.50', '-1.50')])
		expect(totals.openingBalance).toBe('1.00')
		expect(totals.closingBalance).toBe('-0.50')
	})
})
