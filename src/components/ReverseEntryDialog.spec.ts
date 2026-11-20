/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type * as CashbooksApi from '../api/cashbooks.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ReverseEntryDialog from './ReverseEntryDialog.vue'
import { CashbookConflictError } from '../api/cashbooks.ts'
import { EntryReason } from '../models/CashbookEntry.ts'

const { reverseEntry } = vi.hoisted(() => ({ reverseEntry: vi.fn() }))
vi.mock('../api/cashbooks.ts', async (importOriginal) => ({ ...await importOriginal<typeof CashbooksApi>(), reverseEntry }))

const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 2, balance: '100.00' }
const sale: ICashbookEntry = {
	id: '7012345678901234567',
	sequence: 2,
	date: new Date('2025-01-02T10:00:00Z'),
	balance: '100.00',
	text: 'Coffee',
	reference: null,
	referenceDate: null,
	tax: 0.19,
	reason: EntryReason.IncomeSales,
	reverses: null,
	reversedBy: null,
	debit: '10.00',
}

/**
 * Mount the dialog for an entry of the test cashbook
 *
 * @param entry - The entry to reverse
 */
async function mountDialog(entry: ICashbookEntry = sale) {
	const wrapper = mount(ReverseEntryDialog, { attachTo: document.body, props: { cashbook, entry } })
	await flushPromises()
	return wrapper
}

/**
 * Get a text field of the dialog by its label
 *
 * @param wrapper - The mounted dialog
 * @param label - The label of the text field
 */
function textField(wrapper: Awaited<ReturnType<typeof mountDialog>>, label: string) {
	return wrapper.findAllComponents(NcTextField).find((field) => field.props('label') === label)!
}

/**
 * Click a dialog button and wait for the dialog to close
 *
 * @param label - The label of the button
 */
async function clickButton(label: string) {
	const button = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === label)!
	button.click()
	await flushPromises()
	vi.runAllTimers()
	await flushPromises()
}

/**
 * Get the shown details of the reversed entry as pairs of term and description
 */
function details() {
	const terms = [...document.querySelectorAll('dt')].map((element) => element.textContent?.trim())
	const descriptions = [...document.querySelectorAll('dd')].map((element) => element.textContent?.trim())
	return terms.map((term, index) => [term, descriptions[index]])
}

describe('ReverseEntryDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		reverseEntry.mockReset()
		vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('shows the entry to reverse', async () => {
		await mountDialog()

		expect(details()).toEqual([
			['Entry', '#2'],
			['Date', 'Jan 2, 2025, 11:00 AM'],
			['Reason', 'Sales'],
			['Amount', 'Income: €10.00'],
			['Tax rate', '19%'],
			['Text', 'Coffee'],
		])
		expect(document.body.textContent).toContain('This cannot be undone.')
	})

	it('shows an expense without tax rate and text', async () => {
		await mountDialog({
			id: '3',
			sequence: 2,
			date: sale.date,
			balance: '96.50',
			text: '',
			reference: null,
			referenceDate: null,
			tax: null,
			reason: EntryReason.ExpenseGoods,
			reverses: null,
			reversedBy: null,
			credit: '3.50',
		})

		expect(details()).toEqual([
			['Entry', '#2'],
			['Date', 'Jan 2, 2025, 11:00 AM'],
			['Reason', 'Purchases'],
			['Amount', 'Expense: €3.50'],
		])
	})

	it('shows the amount of a negative income as negative income', async () => {
		const { debit, ...base } = sale as ICashbookEntry & { debit: string }
		await mountDialog({ ...base, credit: debit })

		expect(details()).toContainEqual(['Amount', 'Income: (€10.00)'])
	})

	it('requires the reason for the reversal', async () => {
		const wrapper = await mountDialog()

		const text = textField(wrapper, 'Reason for the reversal')
		expect(text.find('input').attributes('required')).toBeDefined()
		expect(textField(wrapper, 'Receipt number').find('input').attributes('required')).toBeUndefined()
	})

	it('does not send an invalid form', async () => {
		vi.mocked(HTMLFormElement.prototype.reportValidity).mockReturnValue(false)
		const wrapper = await mountDialog()

		await clickButton('Reverse entry')

		expect(reverseEntry).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('reverses the entry and emits the reversal', async () => {
		const reversal = { ...sale, id: '3', sequence: 3, debit: '-10.00', reverses: 2 }
		reverseEntry.mockResolvedValue(reversal)
		const wrapper = await mountDialog()

		await textField(wrapper, 'Reason for the reversal').vm.$emit('update:modelValue', 'Wrong amount')
		await textField(wrapper, 'Receipt number').vm.$emit('update:modelValue', 'S-1')
		await clickButton('Reverse entry')

		expect(reverseEntry).toHaveBeenCalledWith(cashbook, sale, { text: 'Wrong amount', reference: 'S-1' })
		expect(wrapper.emitted('close')).toEqual([[reversal]])
	})

	it('can not be closed while the entry is reversed', async () => {
		let resolve: (entry: ICashbookEntry) => void = () => {}
		reverseEntry.mockReturnValue(new Promise((resolver) => {
			resolve = resolver
		}))
		const wrapper = await mountDialog()

		await textField(wrapper, 'Reason for the reversal').vm.$emit('update:modelValue', 'Typo')
		await clickButton('Reverse entry')

		const keep = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === 'Keep entry')!
		expect(keep.disabled).toBe(true)
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await flushPromises()
		vi.runAllTimers()
		expect(wrapper.emitted('close')).toBeUndefined()

		const reversal = { ...sale, id: '3', sequence: 3, debit: '-10.00', reverses: 2 }
		resolve(reversal)
		await flushPromises()
		vi.runAllTimers()
		await flushPromises()
		expect(wrapper.emitted('close')).toEqual([[reversal]])
	})

	it('closes without reversing the entry', async () => {
		const wrapper = await mountDialog()

		await clickButton('Keep entry')

		expect(reverseEntry).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toEqual([[undefined]])
	})

	it('stays open and retries with the current cashbook if it was changed in the meantime', async () => {
		const current = { ...cashbook, lastSequence: 3, balance: '90.00' }
		const reversal = { ...sale, id: '4', sequence: 4, debit: '-10.00', reverses: 2 }
		reverseEntry.mockRejectedValueOnce(new CashbookConflictError(current)).mockResolvedValueOnce(reversal)
		const wrapper = await mountDialog()

		await textField(wrapper, 'Reason for the reversal').vm.$emit('update:modelValue', 'Typo')
		await clickButton('Reverse entry')

		expect(wrapper.emitted('conflict')).toEqual([[current]])
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(document.body.textContent).toContain('The cashbook was changed in the meantime.')
		expect(textField(wrapper, 'Reason for the reversal').props('modelValue')).toBe('Typo')

		await clickButton('Reverse entry')

		expect(reverseEntry).toHaveBeenLastCalledWith(current, sale, { text: 'Typo', reference: '' })
		expect(wrapper.emitted('close')).toEqual([[reversal]])
	})

	it('shows the error and stays open if reversing fails', async () => {
		reverseEntry.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'This entry was already reversed.' } } } },
		}))
		const wrapper = await mountDialog()

		await textField(wrapper, 'Reason for the reversal').vm.$emit('update:modelValue', 'Typo')
		await clickButton('Reverse entry')

		expect(document.body.textContent).toContain('This entry was already reversed.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
