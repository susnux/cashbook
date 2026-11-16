/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NewCashbookDialog from './NewCashbookDialog.vue'

const createCashbook = vi.hoisted(() => vi.fn())
vi.mock('../api/cashbooks.ts', () => ({ createCashbook }))

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
 * Click the create button and wait for the dialog to close
 */
async function clickCreate() {
	const button = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === 'Create cashbook')!
	button.click()
	await flushPromises()
	vi.runAllTimers()
	await flushPromises()
}

describe('NewCashbookDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		createCashbook.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('creates the cashbook with the currency code and emits it', async () => {
		const cashbook = { id: 1, name: 'Shop', account: '', currency: 'USD', owner: 'alice' }
		createCashbook.mockResolvedValue(cashbook)
		const wrapper = await mountFilledDialog()

		await clickCreate()

		expect(createCashbook).toHaveBeenCalledWith({ name: 'Shop', account: '', currency: 'USD' })
		expect(wrapper.emitted('close')).toEqual([[cashbook]])
	})

	it('shows the error and stays open if creating fails', async () => {
		createCashbook.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'Unknown currency.' } } } },
		}))
		const wrapper = await mountFilledDialog()

		await clickCreate()

		expect(document.body.textContent).toContain('Unknown currency.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
