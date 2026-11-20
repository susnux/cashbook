/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { addEntryWithApi } from '../support/utils/entries.ts'

test.use({ timezoneId: 'Europe/Berlin', locale: 'en-US' })

test.describe('Add entry', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
		await cashbookApp.createCashbook({ name: 'Shop register' })
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')
		await expect(cashbookApp.emptyContent('No entries')).toBeVisible()
	})

	test('adds income and expenses to a new cashbook', async ({ cashbookApp, page }) => {
		await cashbookApp.addEntry({
			type: 'Income',
			reason: 'Transfer from bank',
			amount: '100',
			text: 'Cash float',
			reference: 'B-1',
			receiptDate: '2025-01-31',
		})

		const income = cashbookApp.entryRow('Cash float')
		await expect(income.getByRole('cell')).toHaveText(['1', /\S/, 'Transfer from bank', 'Cash float', /B-1/, '', '€100.00', '', '€100.00', ''])
		await expect(income.getByRole('cell').nth(4)).toContainText('Jan 31, 2025')

		await cashbookApp.addEntry({
			type: 'Expense',
			reason: 'Purchases',
			amount: '11.90',
			tax: '19',
			text: 'Coffee beans',
		})

		const expense = cashbookApp.entryRow('Coffee beans')
		await expect(expense.getByRole('cell')).toHaveText(['2', /\S/, 'Purchases', 'Coffee beans', '', '19%', '', '€11.90', '€88.10', ''])

		const summary = page.getByRole('region', { name: 'Totals of the period' })
		await expect(summary.getByRole('definition')).toHaveText(['€0.00', '€100.00', '€11.90', '€88.10'])

		// the entries are persisted in the order they were added
		await page.reload()
		const rows = cashbookApp.entriesTable().getByRole('row').filter({ has: page.getByRole('cell', { name: /^(Cash float|Coffee beans)$/ }) })
		await expect(rows).toHaveCount(2)
		await expect(rows.nth(0)).toContainText('Cash float')
		await expect(rows.nth(1)).toContainText('Coffee beans')
	})

	test('only offers the reasons of the selected type', async ({ cashbookApp, page }) => {
		await cashbookApp.addEntryButton().click()
		const dialog = cashbookApp.newEntryDialog()
		const reason = dialog.getByRole('combobox', { name: 'Reason' })

		await reason.click()
		await expect(page.getByRole('option')).toHaveText(['Sales', 'Transfer from bank', 'Miscellaneous income'])
		await page.keyboard.press('Escape')

		await dialog.getByText('Expense', { exact: true }).click()
		await expect(dialog.getByRole('radio', { name: 'Expense' })).toBeChecked()
		await reason.click()
		await expect(page.getByRole('option')).toHaveText(['Purchases', 'Transfer to bank', 'Salaries', 'Tips', 'Change', 'Refund', 'Miscellaneous expenses'])
	})

	test('reloads the cashbook if it was changed in the meantime', async ({ cashbookApp, page }) => {
		await cashbookApp.addEntry({ type: 'Income', reason: 'Transfer from bank', amount: '100', text: 'Cash float' })
		const dialog = await cashbookApp.fillNewEntryDialog({ type: 'Expense', amount: '80', text: 'Late purchase' })
		await expect(dialog.getByText('Cash balance: €100.00')).toBeVisible()

		const cashbookId = page.url().match(/\/apps\/cashbook\/(\d+)/)![1]!
		await addEntryWithApi(page.request, cashbookId, { lastSequence: 1, reason: 'expense-goods', amount: '30', text: 'Other device' })

		const conflict = await cashbookApp.submitNewEntryDialog()
		expect(conflict.status()).toBe(409)
		await expect(dialog.getByText('The cashbook was changed in the meantime.')).toBeVisible()
		await expect(dialog.getByText('Cash balance: €70.00')).toBeVisible()

		// the expense is checked against the new balance
		const exceeding = await cashbookApp.submitNewEntryDialog()
		expect(exceeding.status()).toBe(400)
		await expect(dialog.getByText('The expense exceeds the cash balance.')).toBeVisible()

		await dialog.getByRole('spinbutton', { name: /^Amount/ }).fill('70')
		const added = await cashbookApp.submitNewEntryDialog()
		expect(added.status()).toBe(200)
		await expect(dialog).toBeHidden()

		await expect(cashbookApp.entryRow('Other device').getByRole('cell')).toHaveText(['2', /\S/, 'Purchases', 'Other device', '', '', '', '€30.00', '€70.00', ''])
		await expect(cashbookApp.entryRow('Late purchase').getByRole('cell').first()).toHaveText('3')
	})

	test('rejects an expense exceeding the cash balance', async ({ cashbookApp }) => {
		const dialog = await cashbookApp.fillNewEntryDialog({ type: 'Expense', amount: '5', text: 'Too much' })
		const response = await cashbookApp.submitNewEntryDialog()
		expect(response.status()).toBe(400)

		await expect(dialog.getByText('The expense exceeds the cash balance.')).toBeVisible()
		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).toBeHidden()
		await expect(cashbookApp.emptyContent('No entries')).toBeVisible()
	})

	test('validates the amount before sending it', async ({ cashbookApp, page }) => {
		let requests = 0
		page.on('request', (request) => {
			if (request.method() === 'POST' && request.url().includes('/entries')) {
				requests++
			}
		})

		const dialog = await cashbookApp.fillNewEntryDialog({ type: 'Income', amount: '1.005' })
		const amount = dialog.getByRole('spinbutton', { name: 'Amount (EUR)' })
		await dialog.getByRole('button', { name: 'Add entry' }).click()
		await expect(amount).toHaveJSProperty('validity.valid', false)

		await amount.fill('')
		await dialog.getByRole('button', { name: 'Add entry' }).click()
		await expect(amount).toHaveJSProperty('validity.valueMissing', true)

		await expect(dialog).toBeVisible()
		expect(requests).toBe(0)
	})

	test('shows the period of the added entry', async ({ cashbookApp, page }) => {
		await cashbookApp.showMonth('2025-01')
		await expect(page.getByText('January 2025', { exact: true })).toBeVisible()

		await cashbookApp.addEntry({ type: 'Income', amount: '20', text: 'First sale' })

		await expect(page).not.toHaveURL(/from=2025-01-01/)
		await expect(page).toHaveURL(/period=month&from=\d{4}-\d{2}-01&/)
		await expect(cashbookApp.entryRow('First sale')).toBeVisible()
	})

	test('can not add entries on the export page', async ({ cashbookApp, page }) => {
		await page.goto(page.url().replace(/\/?(\?.*)?$/, '/export'))
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')
		await expect(page.getByRole('button', { name: 'Print' })).toBeVisible()
		await expect(page.getByRole('button', { name: 'Add entry' })).toHaveCount(0)
	})
})
