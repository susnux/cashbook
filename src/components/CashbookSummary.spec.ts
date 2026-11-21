/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CashbookSummary from './CashbookSummary.vue'
import { EntryReason } from '../models/CashbookEntry.ts'

const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 0, balance: '0.00' }

const base = { reason: EntryReason.IncomeSales, date: new Date(2025, 0, 2), text: '', reference: null, referenceDate: null, reverses: null, reversedBy: null }
const entries: ICashbookEntry[] = [
	{ ...base, id: '1', sequence: 1, reason: EntryReason.DayOpening, balance: '100.00', tax: null },
	{ ...base, id: '2', sequence: 2, balance: '130.00', debit: '30.00', tax: 0.19 },
	{ ...base, id: '3', sequence: 3, reason: EntryReason.ExpenseMisc, balance: '125.00', credit: '5.00', tax: null },
	{ ...base, id: '4', sequence: 4, balance: '135.00', debit: '10.00', tax: 0.07 },
]

describe('CashbookSummary', () => {
	it('shows the balances and the totals of the period', () => {
		const wrapper = mount(CashbookSummary, { props: { cashbook, entries } })

		const balances = Object.fromEntries(wrapper.findAll('dl > div').map((item) => [item.get('dt').text(), item.get('dd').text()]))
		expect(balances).toEqual({
			'Opening balance': '€100.00',
			Income: '€40.00',
			Expenses: '€5.00',
			'Closing balance': '€135.00',
		})
	})

	it('shows the balance before the period without entries', () => {
		const wrapper = mount(CashbookSummary, { props: { cashbook, entries: [], balance: '135.00' } })

		expect(wrapper.findAll('dd').map((item) => item.text())).toEqual(['€135.00', '€0.00', '€0.00', '€135.00'])
	})

	it('ignores the balance before the period with entries', () => {
		const wrapper = mount(CashbookSummary, { props: { cashbook, entries, balance: '50.00' } })

		expect(wrapper.findAll('dd').map((item) => item.text())).toEqual(['€100.00', '€40.00', '€5.00', '€135.00'])
	})

	it('shows the taxes per rate', () => {
		const wrapper = mount(CashbookSummary, { props: { cashbook, entries } })

		const rows = wrapper.findAll('tbody tr, tfoot tr').map((row) => row.findAll('th, td').map((cell) => cell.text()))
		expect(rows).toEqual([
			['19%', '€30.00', '€25.21', '€4.79', '€0.00', '€0.00', '€0.00'],
			['7%', '€10.00', '€9.35', '€0.65', '€0.00', '€0.00', '€0.00'],
			['No tax rate', '€0.00', '', '', '€5.00', '', ''],
			['Total', '€40.00', '', '€5.44', '€5.00', '', '€0.00'],
		])
	})
})
