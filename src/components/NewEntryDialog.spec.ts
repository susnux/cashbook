/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type * as CashbooksApi from '../api/cashbooks.ts'

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NewEntryDialog from './NewEntryDialog.vue'
import { CashbookConflictError } from '../api/cashbooks.ts'
import { EntryReason } from '../models/CashbookEntry.ts'

const { addEntry } = vi.hoisted(() => ({ addEntry: vi.fn() }))
vi.mock('../api/cashbooks.ts', async (importOriginal) => ({ ...await importOriginal<typeof CashbooksApi>(), addEntry }))

const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 2, balance: '100.00' }

/**
 * Mount the dialog for the test cashbook
 */
async function mountDialog() {
	const wrapper = mount(NewEntryDialog, { attachTo: document.body, props: { cashbook } })
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

describe('NewEntryDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		addEntry.mockReset()
		// happy-dom validates the step of number inputs with floating point arithmetic, e.g. 5 % 0.01 !== 0
		vi.spyOn(HTMLFormElement.prototype, 'reportValidity').mockReturnValue(true)
	})

	afterEach(() => {
		vi.restoreAllMocks()
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('offers the income reasons by default', async () => {
		const wrapper = await mountDialog()

		const select = wrapper.findComponent(NcSelect)
		expect(select.props('options').map(({ label }: { label: string }) => label)).toEqual(['Sales', 'Transfer from bank', 'Miscellaneous income'])
		expect(select.props('modelValue')).toEqual({ value: EntryReason.IncomeSales, label: 'Sales' })
	})

	it('switches to the expense reasons', async () => {
		const wrapper = await mountDialog()

		await wrapper.findComponent(NcRadioGroup).vm.$emit('update:modelValue', 'expense')

		const select = wrapper.findComponent(NcSelect)
		expect(select.props('options')).toHaveLength(7)
		expect(select.props('modelValue')).toEqual({ value: EntryReason.ExpenseGoods, label: 'Purchases' })
	})

	it('shows the cash balance', async () => {
		await mountDialog()

		expect(document.body.textContent).toContain('Cash balance: €100.00')
	})

	it('updates the cash balance and stays open if the cashbook was changed in the meantime', async () => {
		const current = { ...cashbook, lastSequence: 3, balance: '90.00' }
		const entry = { id: '4', sequence: 4, date: new Date(), balance: '95.00', debit: '5.00' }
		addEntry.mockRejectedValueOnce(new CashbookConflictError(current)).mockResolvedValueOnce(entry)
		const wrapper = await mountDialog()

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '5')
		await clickButton('Add entry')

		expect(wrapper.emitted('conflict')).toEqual([[current]])
		expect(wrapper.emitted('close')).toBeUndefined()
		expect(document.body.textContent).toContain('Cash balance: €90.00')
		expect(document.body.textContent).toContain('The cashbook was changed in the meantime.')
		expect(textField(wrapper, 'Amount (EUR)').props('modelValue')).toBe('5')

		await clickButton('Add entry')

		expect(addEntry).toHaveBeenLastCalledWith(current, expect.objectContaining({ amount: '5' }))
		expect(wrapper.emitted('close')).toEqual([[entry]])
	})

	it('limits the receipt date to the current day of the cashbook', async () => {
		vi.setSystemTime(new Date('2025-06-30T22:30:00Z'))
		const wrapper = mount(NewEntryDialog, { attachTo: document.body, props: { cashbook: { ...cashbook, timezone: 'Pacific/Auckland' } } })
		await flushPromises()

		expect(wrapper.findComponent(NcDateTimePickerNative).props('max')).toEqual(new Date(2025, 6, 1))
	})

	it('uses the minor unit of the currency for the amount', async () => {
		const wrapper = await mountDialog()

		const amount = textField(wrapper, 'Amount (EUR)')
		expect(amount.attributes('step') ?? amount.find('input').attributes('step')).toBe('0.01')
	})

	it('adds the entry with all values and emits it', async () => {
		const entry = { id: '3', sequence: 3, date: new Date(), balance: '88.10', credit: '11.90' }
		addEntry.mockResolvedValue(entry)
		const wrapper = await mountDialog()

		await wrapper.findComponent(NcRadioGroup).vm.$emit('update:modelValue', 'expense')
		await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { value: EntryReason.ExpenseRefund, label: 'Refund' })
		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '11.90')
		await textField(wrapper, 'Tax rate (%)').vm.$emit('update:modelValue', '19')
		await textField(wrapper, 'Text').vm.$emit('update:modelValue', 'Returned goods')
		await textField(wrapper, 'Receipt number').vm.$emit('update:modelValue', 'R-7')
		await wrapper.findComponent(NcDateTimePickerNative).vm.$emit('update:modelValue', new Date(2025, 0, 31))

		await clickButton('Add entry')

		expect(addEntry).toHaveBeenCalledWith(cashbook, {
			reason: EntryReason.ExpenseRefund,
			amount: '11.90',
			text: 'Returned goods',
			tax: 0.19,
			reference: 'R-7',
			referenceDate: '2025-01-31',
		})
		expect(wrapper.emitted('close')).toEqual([[entry]])
	})

	it('sends no tax rate and receipt date if they are empty', async () => {
		addEntry.mockResolvedValue({ id: 1 })
		const wrapper = await mountDialog()

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '5')
		await clickButton('Add entry')

		expect(addEntry).toHaveBeenCalledWith(cashbook, expect.objectContaining({ reason: EntryReason.IncomeSales, tax: null, referenceDate: null }))
		expect(wrapper.emitted('close')).toEqual([[{ id: 1 }]])
	})

	it('can not be closed while the entry is added', async () => {
		let resolve: (entry: unknown) => void = () => {}
		addEntry.mockReturnValue(new Promise((resolver) => {
			resolve = resolver
		}))
		const wrapper = await mountDialog()

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '5')
		await clickButton('Add entry')

		const cancel = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === 'Cancel')!
		expect(cancel.disabled).toBe(true)
		document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await flushPromises()
		vi.runAllTimers()
		expect(wrapper.emitted('close')).toBeUndefined()

		const entry = { id: '3', sequence: 3, date: new Date(), balance: '105.00', debit: '5.00' }
		resolve(entry)
		await flushPromises()
		vi.runAllTimers()
		await flushPromises()
		expect(wrapper.emitted('close')).toEqual([[entry]])
	})

	it('does not send an invalid form', async () => {
		vi.mocked(HTMLFormElement.prototype.reportValidity).mockReturnValue(false)
		const wrapper = await mountDialog()

		await clickButton('Add entry')

		expect(addEntry).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('shows the error and stays open if adding fails', async () => {
		addEntry.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'The expense exceeds the cash balance.' } } } },
		}))
		const wrapper = await mountDialog()

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '5')
		await clickButton('Add entry')

		expect(document.body.textContent).toContain('The expense exceeds the cash balance.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('clears the error when the input is changed', async () => {
		addEntry.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'The expense exceeds the cash balance.' } } } },
		}))
		const wrapper = await mountDialog()

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '500')
		await clickButton('Add entry')
		expect(document.body.textContent).toContain('The expense exceeds the cash balance.')

		await textField(wrapper, 'Amount (EUR)').vm.$emit('update:modelValue', '50')
		expect(document.body.textContent).not.toContain('The expense exceeds the cash balance.')
	})
})
