/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NewCashbookDialog from './NewCashbookDialog.vue'

const { createCashbook, updateCashbook } = vi.hoisted(() => ({ createCashbook: vi.fn(), updateCashbook: vi.fn() }))
vi.mock('../api/cashbooks.ts', () => ({ createCashbook, updateCashbook }))

/**
 * Mount the dialog and fill in the name and currency
 */
async function mountFilledDialog() {
	const wrapper = mount(NewCashbookDialog, { attachTo: document.body })
	await flushPromises()

	await wrapper.findAllComponents(NcTextField)[0]!.vm.$emit('update:modelValue', 'Shop')
	await wrapper.findComponent(NcSelect).vm.$emit('update:modelValue', { value: 'USD', label: 'US Dollar (USD)' })
	return wrapper
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

describe('NewCashbookDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		createCashbook.mockReset()
		updateCashbook.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('creates the cashbook with the currency code and emits it', async () => {
		const cashbook = { id: 1, name: 'Shop', account: '', currency: 'USD', owner: 'alice' }
		createCashbook.mockResolvedValue(cashbook)
		const wrapper = await mountFilledDialog()

		await clickButton('Create cashbook')

		expect(createCashbook).toHaveBeenCalledWith({ name: 'Shop', account: '', currency: 'USD' })
		expect(wrapper.emitted('close')).toEqual([[cashbook]])
	})

	it('shows the error and stays open if creating fails', async () => {
		createCashbook.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'Unknown currency.' } } } },
		}))
		const wrapper = await mountFilledDialog()

		await clickButton('Create cashbook')

		expect(document.body.textContent).toContain('Unknown currency.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})

	it('updates the name and account of an existing cashbook', async () => {
		const cashbook = { id: 1, name: 'Shop', account: '1000', currency: 'USD', owner: 'alice' }
		const updated = { ...cashbook, name: 'Market', account: '1200' }
		updateCashbook.mockResolvedValue(updated)
		const wrapper = mount(NewCashbookDialog, { attachTo: document.body, props: { cashbook } })
		await flushPromises()

		expect(document.body.textContent).toContain('Edit cashbook')
		expect(wrapper.findComponent(NcSelect).props('disabled')).toBe(true)
		const [nameField, accountField] = wrapper.findAllComponents(NcTextField)
		expect(nameField!.props('modelValue')).toBe('Shop')
		await nameField!.vm.$emit('update:modelValue', 'Market')
		await accountField!.vm.$emit('update:modelValue', '1200')

		await clickButton('Save')

		expect(createCashbook).not.toHaveBeenCalled()
		expect(updateCashbook).toHaveBeenCalledWith(1, { name: 'Market', account: '1200', currency: 'USD' })
		expect(wrapper.emitted('close')).toEqual([[updated]])
	})
})
