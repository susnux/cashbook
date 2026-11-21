/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { addEntryWithApi } from '../support/utils/entries.ts'

test.use({ timezoneId: 'Europe/Berlin', locale: 'en-US' })

test.describe('Reverse entry', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
		await cashbookApp.createCashbook({ name: 'Shop register' })
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')
		await cashbookApp.addEntry({ type: 'Income', reason: 'Transfer from bank', amount: '100', text: 'Cash float' })
		await cashbookApp.addEntry({ type: 'Income', reason: 'Sales', amount: '10', tax: '19', text: 'Coffee' })
	})

	test('reverses a sale and links both entries', async ({ cashbookApp, page }) => {
		const dialog = await cashbookApp.openReverseEntryDialog(2)
		await expect(dialog.getByRole('definition')).toHaveText(['#2', /\S/, 'Sales', 'Income: €10.00', '19%', 'Coffee'])
		await expect(dialog.getByText('This cannot be undone.')).toBeVisible()

		const response = await cashbookApp.submitReverseEntryDialog({ reason: 'Wrong amount', reference: 'S-1' })
		expect(response.status()).toBe(200)
		await expect(dialog).toBeHidden()

		const reversal = cashbookApp.entryRowBySequence(3)
		await expect(reversal.getByRole('cell')).toHaveText(['3', /\S/, 'Sales', /^Wrong amount\s*Reverses #2$/, 'S-1', '19%', '(€10.00)', '', '€100.00', ''])
		const original = cashbookApp.entryRowBySequence(2)
		await expect(original.getByRole('cell').nth(3)).toHaveText(/^Coffee\s*Reversed by #3$/)

		// reversed entries and reversals can not be reversed again
		await expect(cashbookApp.reverseEntryButton(1)).toBeVisible()
		await expect(cashbookApp.reverseEntryButton(2)).toHaveCount(0)
		await expect(cashbookApp.reverseEntryButton(3)).toHaveCount(0)

		const summary = page.getByRole('region', { name: 'Totals of the period' })
		await expect(summary.getByRole('definition')).toHaveText(['€0.00', '€100.00', '€0.00', '€100.00'])

		await original.getByRole('link', { name: 'Reversed by #3' }).click()
		await expect(page).toHaveURL(/#entry-3$/)
		await reversal.getByRole('link', { name: 'Reverses #2' }).click()
		await expect(page).toHaveURL(/#entry-2$/)
	})

	test('shows the links but no actions on the export page', async ({ cashbookApp, page }) => {
		await cashbookApp.reverseEntry(2, { reason: 'Wrong amount' })
		await expect(cashbookApp.entryRowBySequence(3)).toBeVisible()

		await page.goto(page.url().replace(/\/?(\?.*)?$/, '/export'))
		await expect(page.getByRole('button', { name: 'Print' })).toBeVisible()
		await expect(cashbookApp.entryRowBySequence(2).getByRole('link', { name: 'Reversed by #3' })).toBeVisible()
		await expect(cashbookApp.entryRowBySequence(3).getByRole('link', { name: 'Reverses #2' })).toBeVisible()
		await expect(cashbookApp.entryRowBySequence(1).getByRole('cell')).toHaveCount(9)
		await expect(page.getByRole('button', { name: /^Reverse entry/ })).toHaveCount(0)

		// the sequence numbers the notes refer to are printed
		await page.emulateMedia({ media: 'print' })
		await expect(cashbookApp.entryRowBySequence(3).getByRole('cell').first()).toBeVisible()
		await expect(cashbookApp.entryRowBySequence(3).getByRole('cell').first()).toHaveText('3')
	})

	test('takes no space for the actions and fits long numbers when printed', async ({ cashbookApp, page }) => {
		await page.emulateMedia({ media: 'print' })

		const actionsHeader = cashbookApp.entriesTable().locator('thead th').last()
		expect((await actionsHeader.boundingBox())?.width ?? 0).toBeLessThan(1)
		await expect(cashbookApp.reverseEntryButton(2)).toBeHidden()

		// the sequence number of a long running cashbook fits into its column
		const sequence = cashbookApp.entryRowBySequence(1).getByRole('cell').first()
		await sequence.evaluate((cell) => {
			cell.textContent = '12345'
		})
		expect(await sequence.evaluate((cell) => cell.scrollWidth <= cell.clientWidth)).toBe(true)
	})

	test('requires the reason for the reversal and can be cancelled', async ({ cashbookApp, page }) => {
		let requests = 0
		page.on('request', (request) => {
			if (request.method() === 'POST' && request.url().includes('/reverse')) {
				requests++
			}
		})

		const dialog = await cashbookApp.openReverseEntryDialog(2)
		const reason = dialog.getByRole('textbox', { name: 'Reason for the reversal' })
		await dialog.getByRole('button', { name: 'Reverse entry' }).click()
		await expect(reason).toHaveJSProperty('validity.valueMissing', true)

		await dialog.getByRole('button', { name: 'Keep entry' }).click()
		await expect(dialog).toBeHidden()
		expect(requests).toBe(0)
		await expect(cashbookApp.entryRowBySequence(3)).toHaveCount(0)
		await expect(cashbookApp.reverseEntryButton(2)).toBeVisible()
	})

	test('rejects a reversal exceeding the cash balance until the input is changed', async ({ cashbookApp }) => {
		await cashbookApp.addEntry({ type: 'Expense', amount: '105', text: 'Bank deposit' })

		const dialog = await cashbookApp.openReverseEntryDialog(1)
		const response = await cashbookApp.submitReverseEntryDialog({ reason: 'Typo' })
		expect(response.status()).toBe(400)
		const error = dialog.getByText('The reversal exceeds the cash balance.')
		await expect(error).toBeVisible()

		await dialog.getByRole('textbox', { name: 'Reason for the reversal' }).fill('Wrong amount')
		await expect(error).toBeHidden()
	})

	test('reloads the cashbook if it was changed in the meantime', async ({ cashbookApp, page }) => {
		const dialog = await cashbookApp.openReverseEntryDialog(2)

		const cashbookId = page.url().match(/\/apps\/cashbook\/(\d+)/)![1]!
		await addEntryWithApi(page.request, cashbookId, { lastSequence: 2, reason: 'income-misc', amount: '5', text: 'Other device' })

		const conflict = await cashbookApp.submitReverseEntryDialog({ reason: 'Wrong amount' })
		expect(conflict.status()).toBe(409)
		const warning = dialog.getByText('The cashbook was changed in the meantime.')
		await expect(warning).toBeVisible()
		await dialog.getByRole('textbox', { name: 'Receipt number' }).fill('R-1')
		await expect(warning).toBeHidden()

		const reversed = await cashbookApp.submitReverseEntryDialog({ reason: 'Wrong amount' })
		expect(reversed.status()).toBe(200)
		await expect(dialog).toBeHidden()

		await expect(cashbookApp.entryRow('Other device').getByRole('cell').first()).toHaveText('3')
		await expect(cashbookApp.entryRowBySequence(4).getByRole('cell').nth(3)).toHaveText(/^Wrong amount\s*Reverses #2$/)
		await expect(cashbookApp.entryRowBySequence(4).getByRole('cell').nth(8)).toHaveText('€105.00')
	})
})
