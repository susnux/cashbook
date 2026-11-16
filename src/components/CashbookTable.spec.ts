/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CashbookTable from './CashbookTable.vue'
import { EntryReason } from '../models/CashbookEntry.ts'

const cashbook = { id: 1, name: 'Shop', account: '', currency: 'EUR', owner: 'alice' }

/**
 * Create a cashbook entry
 *
 * @param id - The entry id
 * @param reason - The reason of the entry
 * @param amount - Optional debit (positive) or credit (negative) amount
 */
function entry(id: number, reason: EntryReason | null, amount?: number): ICashbookEntry {
	const base = { id, reason, date: new Date(2025, 0, 2, 10, id), balance: '100.00', text: `Entry ${id}`, reference: null, tax: null }
	if (amount === undefined) {
		return base
	}
	return amount < 0 ? { ...base, credit: String(-amount) } : { ...base, debit: String(amount) }
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
		expect(sections.map((section) => section.findAll('td:first-child').map((cell) => cell.text()))).toEqual([
			['1'],
			['3'],
			['5'],
			['7'],
		])
		expect(sections[1]!.text()).toContain('Closed:')
		expect(sections[3]!.text()).not.toContain('Closed:')
		expect(sections[3]!.text()).toContain('Transfer from bank')
	})

	it('spans the day header over all columns', () => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [entry(1, EntryReason.DayOpening), entry(2, EntryReason.IncomeSales, 1)] },
		})

		const columns = wrapper.findAll('thead th').length
		expect(wrapper.get('tbody th').attributes('colspan')).toBe(String(columns))
		expect(wrapper.get('tbody tr:last-child').findAll('td')).toHaveLength(columns)
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

		const rows = wrapper.findAll('tbody tr').slice(1)
		expect(rows.map((row) => row.classes().length > 0)).toEqual([false, true, false])
	})

	it('shows debit and credit in their columns', () => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, entries: [entry(1, EntryReason.IncomeSales, 12.5), entry(2, null, -3)] },
		})

		const [income, expense] = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))
		expect(income!.slice(5, 7)).toEqual(['€12.50', ''])
		expect(expense!.slice(5, 7)).toEqual(['', '€3.00'])
		expect(expense![2]).toBe('')
	})
})
