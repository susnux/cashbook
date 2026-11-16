/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbookEntry } from '../models/CashbookEntry.ts'

/** Amounts are decimal strings, `net` and `tax` are `null` if the tax rate is unknown */
export interface TaxAmounts {
	gross: string
	net: string | null
	tax: string | null
}

export interface TaxTotal {
	/** The tax rate (e.g. `0.19` for 19%), `null` for entries without a tax rate */
	rate: number | null
	income: TaxAmounts
	expenses: TaxAmounts
}

/** Totals of a list of entries, all amounts are decimal strings */
export interface Totals {
	/** The balance before the first entry, `null` without entries */
	openingBalance: string | null
	/** The balance after the last entry, `null` without entries */
	closingBalance: string | null
	income: string
	expenses: string
	/** Tax included in the income */
	incomeTax: string
	/** Tax included in the expenses */
	expensesTax: string
	/** Totals per tax rate, ordered by descending rate with entries without a tax rate last */
	taxes: TaxTotal[]
}

const AMOUNT_PATTERN = /^(-?)(\d+)(?:\.(\d+))?$/

/** Tax rates are converted to integers with this precision */
const RATE_PRECISION = 1_000_000n

/**
 * Calculate the income, expenses and included taxes of entries.
 *
 * Taxes are calculated from the gross sum per tax rate and rounded to the precision of the amounts.
 *
 * @param entries - The entries in chronological order
 */
export function calculateTotals(entries: ICashbookEntry[]): Totals {
	const scale = Math.max(0, ...entries.flatMap((entry) => [entry.balance, getDebit(entry), getCredit(entry)].map(getScale)))

	let income = 0n
	let expenses = 0n
	const byRate = new Map<number | null, { income: bigint, expenses: bigint }>()
	for (const entry of entries) {
		const debit = toUnits(getDebit(entry), scale)
		const credit = toUnits(getCredit(entry), scale)
		if (debit === 0n && credit === 0n) {
			continue
		}

		income += debit
		expenses += credit
		const group = byRate.get(entry.tax) ?? { income: 0n, expenses: 0n }
		group.income += debit
		group.expenses += credit
		byRate.set(entry.tax, group)
	}

	let incomeTax = 0n
	let expensesTax = 0n
	const taxes: TaxTotal[] = []
	for (const [rate, group] of byRate) {
		const groupIncomeTax = rate === null ? null : includedTax(group.income, rate)
		const groupExpensesTax = rate === null ? null : includedTax(group.expenses, rate)
		incomeTax += groupIncomeTax ?? 0n
		expensesTax += groupExpensesTax ?? 0n
		taxes.push({
			rate,
			income: toTaxAmounts(group.income, groupIncomeTax, scale),
			expenses: toTaxAmounts(group.expenses, groupExpensesTax, scale),
		})
	}
	taxes.sort((a, b) => (b.rate ?? -1) - (a.rate ?? -1))

	const first = entries.at(0)
	const last = entries.at(-1)
	return {
		openingBalance: first
			? fromUnits(toUnits(first.balance, scale) - toUnits(getDebit(first), scale) + toUnits(getCredit(first), scale), scale)
			: null,
		closingBalance: last ? fromUnits(toUnits(last.balance, scale), scale) : null,
		income: fromUnits(income, scale),
		expenses: fromUnits(expenses, scale),
		incomeTax: fromUnits(incomeTax, scale),
		expensesTax: fromUnits(expensesTax, scale),
		taxes,
	}
}

/**
 * @param entry - The entry
 */
function getDebit(entry: ICashbookEntry): string {
	return 'debit' in entry ? entry.debit : '0'
}

/**
 * @param entry - The entry
 */
function getCredit(entry: ICashbookEntry): string {
	return 'credit' in entry ? entry.credit : '0'
}

/**
 * Get the number of fraction digits of a decimal string
 *
 * @param amount - The decimal string
 */
function getScale(amount: string): number {
	return amount.split('.')[1]?.length ?? 0
}

/**
 * Convert a decimal string to an integer of the smallest unit of the given scale
 *
 * @param amount - The decimal string, with at most `scale` fraction digits
 * @param scale - The number of fraction digits
 */
function toUnits(amount: string, scale: number): bigint {
	const match = amount.match(AMOUNT_PATTERN)
	if (!match) {
		throw new TypeError(`Invalid amount "${amount}"`)
	}
	const units = BigInt(match[2] + (match[3] ?? '').padEnd(scale, '0'))
	return match[1] ? -units : units
}

/**
 * Convert an integer of the smallest unit of the given scale to a decimal string
 *
 * @param units - The amount in the smallest unit
 * @param scale - The number of fraction digits
 */
function fromUnits(units: bigint, scale: number): string {
	const digits = (units < 0n ? -units : units).toString().padStart(scale + 1, '0')
	const integer = digits.slice(0, digits.length - scale)
	const fraction = scale > 0 ? `.${digits.slice(digits.length - scale)}` : ''
	return `${units < 0n ? '-' : ''}${integer}${fraction}`
}

/**
 * @param gross - The gross amount in the smallest unit
 * @param tax - The included tax in the smallest unit, `null` if unknown
 * @param scale - The number of fraction digits
 */
function toTaxAmounts(gross: bigint, tax: bigint | null, scale: number): TaxAmounts {
	return {
		gross: fromUnits(gross, scale),
		net: tax === null ? null : fromUnits(gross - tax, scale),
		tax: tax === null ? null : fromUnits(tax, scale),
	}
}

/**
 * Get the tax included in a gross amount, rounded half away from zero
 *
 * @param gross - The gross amount in the smallest unit
 * @param rate - The tax rate (e.g. `0.19` for 19%)
 */
function includedTax(gross: bigint, rate: number): bigint {
	const rateUnits = BigInt(Math.round(rate * Number(RATE_PRECISION)))
	const dividend = gross * rateUnits
	const divisor = RATE_PRECISION + rateUnits
	const absolute = dividend < 0n ? -dividend : dividend
	const quotient = (2n * absolute + divisor) / (2n * divisor)
	return dividend < 0n ? -quotient : quotient
}
