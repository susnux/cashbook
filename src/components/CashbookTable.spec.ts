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

const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 0, balance: '0.00' }

/**
 * Create a cashbook entry
 *
 * @param id - The entry id
 * @param reason - The reason of the entry
 * @param amount - Optional debit (positive) or credit (negative) amount
 * @param tax - The tax rate
 */
function entry(id: number, reason: EntryReason | null, amount?: number, tax: number | null = null): ICashbookEntry {
	const base = { id: String(id), sequence: id, reason, date: new Date(2025, 0, 2, 10, id), balance: '100.00', text: `Entry ${id}`, reference: null, referenceDate: null, tax, reverses: null, reversedBy: null }
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
		const first = { ...entry(1, EntryReason.IncomeSales, 1), date: new Date('2025-01-02T23:59:00+01:00') }
		const second = { ...entry(2, EntryReason.IncomeSales, 2), date: new Date('2025-01-03T00:01:00+01:00') }
		const third = { ...entry(3, EntryReason.IncomeSales, 3), date: new Date('2025-01-03T12:00:00+01:00') }
		const wrapper = mount(CashbookTable, { props: { cashbook, entries: [first, second, third] } })

		const sections = wrapper.findAll('tbody')
		expect(sections.map((section) => entryRows(section).map((row) => row.get('td').text()))).toEqual([
			['1'],
			['2', '3'],
		])
	})

	it('groups and shows the entries in the timezone of the cashbook', () => {
		// midnight in Auckland (UTC+13 in January) is 11:00 UTC of the previous day
		const first = { ...entry(1, EntryReason.IncomeSales, 1), date: new Date('2025-01-01T10:59:00Z') }
		const second = { ...entry(2, EntryReason.IncomeSales, 2), date: new Date('2025-01-01T11:00:00Z') }
		const third = { ...entry(3, EntryReason.IncomeSales, 3), date: new Date('2025-01-02T10:00:00Z') }
		const wrapper = mount(CashbookTable, {
			props: { cashbook: { ...cashbook, timezone: 'Pacific/Auckland' }, entries: [first, second, third] },
		})

		const sections = wrapper.findAll('tbody')
		expect(sections.map((section) => entryRows(section).map((row) => row.get('td').text()))).toEqual([
			['1'],
			['2', '3'],
		])
		expect(sections.map((section) => section.get('th').text().replace(/\s+/g, ' '))).toEqual([
			'Date:Jan 1, 2025',
			'Date:Jan 2, 2025',
		])
		const times = entryRows(wrapper).map((row) => row.findAll('td')[1]!.text().replace(/\s+/g, ' '))
		expect(times).toEqual(['Jan 1, 2025, 11:59 PM', 'Jan 2, 2025, 12:00 AM', 'Jan 2, 2025, 11:00 PM'])
	})

	it.each([false, true])('spans the day header over all columns (readonly: %s)', (readonly) => {
		const wrapper = mount(CashbookTable, {
			props: { cashbook, readonly, entries: [entry(1, EntryReason.DayOpening), entry(2, EntryReason.IncomeSales, 1, 0.19)] },
		})

		const columns = wrapper.findAll('thead th').length
		expect(columns).toBe(readonly ? 9 : 10)
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
		expect(entryRows(wrapper)[1]!.findAll('td')[7]!.attributes('data-reversal')).toBeUndefined()
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
			['', 'Total of the day', '€40.00', '€5.00', '', ''],
			['', 'Included tax', '19%', '€4.79', '€0.00', '', ''],
			['', 'Included tax', '7%', '€0.65', '€0.00', '', ''],
		])
		expect(emptyDay!.text()).not.toContain('Total of the day')
	})

	it('offers to reverse only income and expense entries that are not reversed', () => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				entries: [
					entry(1, EntryReason.DayOpening),
					entry(2, EntryReason.IncomeSales, 10),
					entry(3, EntryReason.ExpenseGoods, -5),
					entry(4, EntryReason.Correction, 1),
					entry(5, null, 1),
					{ ...entry(6, EntryReason.IncomeMisc, 3), reversedBy: 7 },
					{ ...entry(7, EntryReason.IncomeMisc), debit: '-3.00', reverses: 6 },
					entry(8, EntryReason.IncomeSales),
					entry(9, EntryReason.DayClosing),
				],
			},
		})

		expect(entryRows(wrapper).map((row) => [row.get('td').text(), row.find('button').exists()])).toEqual([
			['2', true],
			['3', true],
			['4', false],
			['5', false],
			['6', false],
			['7', false],
			['8', false],
		])
	})

	it('emits the entry to reverse', async () => {
		const sale = entry(1, EntryReason.IncomeSales, 10)
		const wrapper = mount(CashbookTable, { props: { cashbook, entries: [sale] } })

		await wrapper.get('button[aria-label="Reverse entry #1"]').trigger('click')

		expect(wrapper.emitted('reverse')).toEqual([[sale]])
	})

	it('hides the actions if readonly', () => {
		const wrapper = mount(CashbookTable, { props: { cashbook, readonly: true, entries: [entry(1, EntryReason.IncomeSales, 10)] } })

		expect(wrapper.find('button').exists()).toBe(false)
		expect(entryRows(wrapper)[0]!.findAll('td')).toHaveLength(9)
	})

	it.each([false, true])('links reversals and reversed entries (readonly: %s)', (readonly) => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				readonly,
				entries: [
					{ ...entry(2, EntryReason.IncomeSales, 10), reversedBy: 4 },
					{ ...entry(3, EntryReason.IncomeSales, 5), reversedBy: 9 },
					{ ...entry(4, EntryReason.IncomeSales), debit: '-10.00', reverses: 2 },
					{ ...entry(5, EntryReason.IncomeSales), debit: '-1.00', reverses: 1 },
				],
			},
		})

		const rows = entryRows(wrapper)
		expect(rows.map((row) => row.attributes('id'))).toEqual(['entry-2', 'entry-3', 'entry-4', 'entry-5'])
		expect(rows.map((row) => row.findAll('td')[3]!.text())).toEqual([
			'Entry 2Reversed by #4',
			'Entry 3Reversed by #9',
			'Entry 4Reverses #2',
			'Entry 5Reverses #1',
		])
		// only entries that are shown are linked
		expect(rows.map((row) => row.find('a').exists() ? row.get('a').attributes('href') : null)).toEqual(['#entry-4', null, '#entry-2', null])
	})

	it('shows reversals as negative amounts on the side of the reversed entry', () => {
		const wrapper = mount(CashbookTable, {
			props: {
				cashbook,
				entries: [
					{ ...entry(1, EntryReason.IncomeSales), debit: '-10.00', reverses: 7 },
					{ ...entry(2, EntryReason.ExpenseGoods), credit: '-3.00', reverses: 8 },
				],
			},
		})

		const [income, expense] = entryRows(wrapper).map((row) => row.findAll('td').map((cell) => cell.text()).slice(6, 8))
		expect(income).toEqual(['(€10.00)', ''])
		expect(expense).toEqual(['', '(€3.00)'])
		// reversed expenses add to the balance, so they are not marked as expense
		expect(entryRows(wrapper)[1]!.findAll('td')[7]!.attributes('data-reversal')).toBe('true')
	})
})
