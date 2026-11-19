/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { flushPromises, mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, defineComponent, h, useId } from 'vue'
import NcFilePicker from '@nextcloud/vue/components/NcFilePicker'
import ImportCashbookDialog from './ImportCashbookDialog.vue'

const { importSumupCashbook } = vi.hoisted(() => ({ importSumupCashbook: vi.fn() }))
vi.mock('../api/cashbooks.ts', () => ({ importSumupCashbook }))

const cashbookFile = new File(['cashbook'], 'Kassenbuch_2025.csv', { type: 'text/csv' })
const reportFile = new File(['report'], 'Tagesabschluss_2025.csv', { type: 'text/csv' })

/**
 * Mount the dialog
 */
async function mountDialog() {
	const wrapper = mount(ImportCashbookDialog, { attachTo: document.body })
	await flushPromises()
	return wrapper
}

/**
 * Pick a file with one of the file pickers of the dialog
 *
 * @param wrapper - The mounted dialog
 * @param picker - The file picker, 0 for the cashbook export and 1 for the daily closing report
 * @param file - The picked file
 */
async function pickFile(wrapper: Awaited<ReturnType<typeof mountDialog>>, picker: 0 | 1, file: File) {
	await wrapper.findAllComponents(NcFilePicker)[picker]!.vm.$emit('pick', [file])
}

/**
 * Get a button of the dialog by its label
 *
 * @param label - The label of the button
 */
function getButton(label: string): HTMLButtonElement {
	return [...document.querySelectorAll('button')]
		.find((element) => element.textContent?.trim() === label || element.getAttribute('aria-label') === label)!
}

/**
 * Click a dialog button and wait for the dialog to close
 *
 * @param label - The label of the button
 */
async function clickButton(label: string) {
	getButton(label).click()
	await flushPromises()
	vi.runAllTimers()
	await flushPromises()
}

describe('ImportCashbookDialog', () => {
	beforeEach(() => {
		vi.useFakeTimers()
		importSumupCashbook.mockReset()
	})

	afterEach(() => {
		vi.useRealTimers()
		document.body.innerHTML = ''
	})

	it('has a file picker for the cashbook export and one for the daily closing report', async () => {
		const wrapper = await mountDialog()

		const pickers = wrapper.findAllComponents(NcFilePicker)
		expect(pickers.map((picker) => picker.props('label'))).toEqual(['Select cashbook export', 'Select daily closing report'])
		expect(pickers.map((picker) => picker.props('multiple'))).toEqual([false, false])
	})

	it('describes each file field with its hint and the selected file', async () => {
		// The dialog is spawned as separate app next to the main app, which may use generated IDs as well
		const mainApp = createApp(defineComponent(() => {
			const id = useId()
			return () => h('p', { id }, 'Main app')
		}))
		mainApp.mount(document.body.appendChild(document.createElement('div')))
		const wrapper = await mountDialog()
		await pickFile(wrapper, 0, cashbookFile)

		const descriptions = [...document.querySelectorAll('fieldset')].map((fieldset) => fieldset.getAttribute('aria-describedby')!
			.split(' ')
			.map((id) => document.getElementById(id)!.textContent!.trim()))
		expect(descriptions).toEqual([
			['The export of the cash register book (Kassenbuch_….csv).', 'Kassenbuch_2025.csv'],
			['The export of the daily closings to import the day openings and closings.', 'No file selected'],
		])
		mainApp.unmount()
	})

	it('requires the cashbook export', async () => {
		const wrapper = await mountDialog()
		expect(getButton('Import cashbook').disabled).toBe(true)

		await pickFile(wrapper, 1, reportFile)
		expect(getButton('Import cashbook').disabled).toBe(true)

		await pickFile(wrapper, 0, cashbookFile)
		expect(getButton('Import cashbook').disabled).toBe(false)
		expect(document.body.textContent).toContain('Kassenbuch_2025.csv')
		expect(document.body.textContent).toContain('Tagesabschluss_2025.csv')
	})

	it('imports the cashbook export with the daily closing report and emits the cashbook', async () => {
		const cashbook = { id: '1', name: 'Shop', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 0, balance: '0.00' }
		importSumupCashbook.mockResolvedValue(cashbook)
		const wrapper = await mountDialog()

		await pickFile(wrapper, 0, cashbookFile)
		await pickFile(wrapper, 1, reportFile)
		await clickButton('Import cashbook')

		expect(importSumupCashbook).toHaveBeenCalledWith({ cashbook: cashbookFile, report: reportFile }, expect.any(String))
		expect(wrapper.emitted('close')).toEqual([[cashbook]])
	})

	it('imports without the removed daily closing report', async () => {
		importSumupCashbook.mockResolvedValue({ id: '1' })
		const wrapper = await mountDialog()

		await pickFile(wrapper, 0, cashbookFile)
		await pickFile(wrapper, 1, reportFile)
		await clickButton('Remove daily closing report')
		expect(document.body.textContent).not.toContain('Tagesabschluss_2025.csv')
		expect(document.activeElement).toBe(getButton('Select daily closing report'))

		await clickButton('Import cashbook')

		expect(importSumupCashbook).toHaveBeenCalledWith({ cashbook: cashbookFile, report: undefined }, expect.any(String))
	})

	it('shows the error and stays open if the import fails', async () => {
		importSumupCashbook.mockRejectedValue(Object.assign(new Error('failed'), {
			isAxiosError: true,
			response: { data: { ocs: { meta: { message: 'The selected cashbook export is not a valid SumUp cashbook export.' } } } },
		}))
		const wrapper = await mountDialog()

		await pickFile(wrapper, 0, reportFile)
		await clickButton('Import cashbook')

		expect(document.body.textContent).toContain('The selected cashbook export is not a valid SumUp cashbook export.')
		expect(wrapper.emitted('close')).toBeUndefined()
	})
})
