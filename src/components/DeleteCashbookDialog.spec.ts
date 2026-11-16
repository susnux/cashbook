/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import DeleteCashbookDialog from './DeleteCashbookDialog.vue'

const deleteCashbook = vi.hoisted(() => vi.fn())
vi.mock('../api/cashbooks.ts', () => ({ deleteCashbook }))

const cashbook = { id: 1, name: 'Shop', account: '', currency: 'EUR', owner: 'alice' }

/**
 * Find a dialog button by its text
 *
 * @param text - The button text
 */
function getButton(text: string): HTMLButtonElement {
	const button = [...document.querySelectorAll('button')].find((element) => element.textContent?.trim() === text)
	if (!button) {
		throw new Error(`Button "${text}" not found`)
	}
	return button
}

describe('DeleteCashbookDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		deleteCashbook.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('deletes the cashbook after confirmation', async () => {
		deleteCashbook.mockResolvedValue(undefined)
		const wrapper = mount(DeleteCashbookDialog, { props: { cashbook }, attachTo: document.body })
		await flushPromises()
		expect(document.body.textContent).toContain('"Shop"')

		getButton('Delete cashbook').click()
		await flushPromises()
		vi.runAllTimers()
		await flushPromises()

		expect(deleteCashbook).toHaveBeenCalledWith(cashbook)
		expect(wrapper.emitted('close')).toEqual([[true]])
	})

	it('does not delete the cashbook when cancelled', async () => {
		const wrapper = mount(DeleteCashbookDialog, { props: { cashbook }, attachTo: document.body })
		await flushPromises()

		getButton('Cancel').click()
		await flushPromises()
		vi.runAllTimers()
		await flushPromises()

		expect(deleteCashbook).not.toHaveBeenCalled()
		expect(wrapper.emitted('close')).toEqual([[false]])
	})

	it('shows the error and stays open if deleting fails', async () => {
		deleteCashbook.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'Server says no' } } } },
		}))
		const wrapper = mount(DeleteCashbookDialog, { props: { cashbook }, attachTo: document.body })
		await flushPromises()

		getButton('Delete cashbook').click()
		await flushPromises()
		vi.runAllTimers()
		await flushPromises()

		expect(document.body.textContent).toContain('Server says no')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
