/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { DOMWrapper, VueWrapper } from '@vue/test-utils'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CashbookTable from './CashbookTable.vue'
import { EntryReason } from '../models/CashbookEntry.ts'

const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', lastSequence: 0, balance: '0.00' }

/**
 * Create a cashbook entry
 *
 * @param id - The entry id
 * @param reason - The reason of the entry
 * @param amount - Optional debit (positive) or credit (negative) amount
 * @param tax - The tax rate
 */
function entry(id: number, reason: EntryReason | null, amount?: number, tax: number | null = null): ICashbookEntry {
	const base = { id: String(id), sequence: id, reason, date: new Date(2025, 0, 2, 10, id), balance: '100.00', text: `Entry ${id}`, reference: null, referenceDate: null, tax }
	if (amount === undefined) {
		return base
	}
	return amount < 0 ? { ...base, credit: (-amount).toFixed(2) } : { ...base, debit: amount.toFixed(2) }
}

/**
 * Get the rows of the entries, without the day headers and totals
 *
 * @param wrapper - The table or one of its sections
 */
function entryRows(wrapper: VueWrapper | DOMWrapper<Element>) {
	return wrapper.findAll('tbody tr').filter((row) => !row.find('th').exists())
}

describe('CashbookTable', () => {
	it('groups entries by day and keeps entries outside of days', () => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				entries: [
					entry(1, EntryReason.IncomeSales, 1),
					entry(2, EntryReason.DayOpening),
					entry(3, EntryReason.IncomeSales, 2),
					entry(4, EntryReason.DayClosing),
					entry(5, EntryReason.ExpenseMisc, -3),
					entry(6, EntryReason.DayOpening),
					entry(7, EntryReason.IncomeBankTransfer, 4),
				],
			},
		})

		const sections = wrapper.findAll('tbody')
		expect(sections).toHaveLength(4)
		expect(sections.map((section) => entryRows(section).map((row) => row.get('td').text()))).toEqual([
			['1'],
			['3'],
			['5'],
			['7'],
		])
		expect(sections[0]!.text()).toContain('Date:')
		expect(sections[1]!.text()).toContain('Closed:')
		expect(sections[3]!.text()).not.toContain('Closed:')
		expect(sections[3]!.text()).toContain('Transfer from bank')
	})

	it('shows the sequence number instead of the id', () => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [{ ...entry(5, EntryReason.IncomeSales, 1), id: '7012345678901234567' }] },
		})

		expect(entryRows(wrapper).map((row) => row.get('td').text())).toEqual(['5'])
	})

	it('groups entries outside of days by calendar day', () => {
		const first = { ...entry(1, EntryReason.IncomeSales, 1), date: new Date(2025, 0, 2, 23, 59) }
		const second = { ...entry(2, EntryReason.IncomeSales, 2), date: new Date(2025, 0, 3, 0, 1) }
		const third = { ...entry(3, EntryReason.IncomeSales, 3), date: new Date(2025, 0, 3, 12, 0) }
		const wrapper = mount(CashbookTable, { props: { cashbook, entries: [first, second, third] } })

		const sections = wrapper.findAll('tbody')
		expect(sections.map((section) => entryRows(section).map((row) => row.get('td').text()))).toEqual([
			['1'],
			['2', '3'],
		])
	})

	it('spans the day header over all columns', () => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [entry(1, EntryReason.DayOpening), entry(2, EntryReason.IncomeSales, 1, 0.19)] },
		})

		const columns = wrapper.findAll('thead th').length
		expect(wrapper.get('tbody th').attributes('colspan')).toBe(String(columns))
		for (const row of wrapper.findAll('tbody tr').slice(1)) {
			const cells = row.findAll('td, th').reduce((sum, cell) => sum + Number(cell.attributes('colspan') ?? 1), 0)
			expect(cells).toBe(columns)
		}
	})

	it('stripes every second entry of a section', () => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				entries: [
					entry(1, EntryReason.DayOpening),
					entry(2, EntryReason.IncomeSales, 1),
					entry(3, EntryReason.IncomeSales, 1),
					entry(4, EntryReason.IncomeSales, 1),
				],
			},
		})

		expect(entryRows(wrapper).map((row) => row.classes().length > 0)).toEqual([false, true, false])
	})

	it('shows debit and credit in their columns', () => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [entry(1, EntryReason.IncomeSales, 12.5), entry(2, null, -3)] },
		})

		const [income, expense] = entryRows(wrapper).map((row) => row.findAll('td').map((cell) => cell.text()))
		expect(income!.slice(6, 8)).toEqual(['€12.50', ''])
		expect(expense!.slice(6, 8)).toEqual(['', '€3.00'])
		expect(expense![2]).toBe('')
	})

	it('shows the receipt number and date', () => {
		const withReceipt = { ...entry(1, EntryReason.ExpenseGoods, -3), reference: 'R-1', referenceDate: '2025-01-01' }
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [withReceipt, entry(2, EntryReason.IncomeSales, 1)] },
		})

		const receipts = entryRows(wrapper).map((row) => row.findAll('td')[4]!.text())
		expect(receipts).toEqual(['R-1Jan 1, 2025', ''])
	})

	it('shows the totals and included taxes of each day', () => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				entries: [
					entry(1, EntryReason.DayOpening),
					entry(2, EntryReason.IncomeSales, 10, 0.19),
					entry(3, EntryReason.IncomeSales, 20, 0.19),
					entry(4, EntryReason.ExpenseMisc, -5),
					entry(5, EntryReason.IncomeSales, 10, 0.07),
					entry(6, EntryReason.DayClosing),
					entry(7, EntryReason.DayOpening),
				],
			},
		})

		const [day, emptyDay] = wrapper.findAll('tbody')
		const totals = day!.findAll('tr').slice(-3).map((row) => row.findAll('td, th').map((cell) => cell.text()))
		expect(totals).toEqual([
			['', 'Total of the day', '€40.00', '€5.00', ''],
			['', 'Included tax', '19%', '€4.79', '€0.00', ''],
			['', 'Included tax', '7%', '€0.65', '€0.00', ''],
		])
		expect(emptyDay!.text()).not.toContain('Total of the day')
	})
})
