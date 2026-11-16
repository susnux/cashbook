/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/cashbook-app.ts'

test.describe('Edit cashbook', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
		await cashbookApp.createCashbook({ name: 'Shop register', account: '1000' })
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')
	})

	test('shows the current values and a fixed currency', async ({ cashbookApp }) => {
		const dialog = await cashbookApp.openEditCashbookDialog('Shop register')

		await expect(dialog.getByRole('textbox', { name: 'Cashbook name' })).toHaveValue('Shop register')
		await expect(dialog.getByRole('textbox', { name: 'Account' })).toHaveValue('1000')
		await expect(dialog.getByRole('combobox', { name: 'Currency' })).toBeDisabled()

		await dialog.getByRole('button', { name: 'Cancel' }).click()
		await expect(dialog).toBeHidden()
		await expect(cashbookApp.cashbookNavigationItem('Shop register')).toBeVisible()
	})

	test('renames the cashbook and changes the account', async ({ cashbookApp, page }) => {
		const url = page.url()

		await cashbookApp.editCashbook('Shop register', { name: 'Market stall', account: '1200' })

		await expect(cashbookApp.editCashbookDialog()).toBeHidden()
		await expect(cashbookApp.cashbookNavigationItem('Market stall')).toBeVisible()
		await expect(cashbookApp.cashbookNavigationItem('Shop register')).toHaveCount(0)
		await expect(cashbookApp.cashbookHeading()).toHaveText('Market stall')
		expect(page.url()).toBe(url)

		// the changes are persisted
		await page.reload()
		await expect(cashbookApp.cashbookHeading()).toHaveText('Market stall')
		const dialog = await cashbookApp.openEditCashbookDialog('Market stall')
		await expect(dialog.getByRole('textbox', { name: 'Cashbook name' })).toHaveValue('Market stall')
		await expect(dialog.getByRole('textbox', { name: 'Account' })).toHaveValue('1200')
	})

	test('keeps the dialog open when saving fails', async ({ cashbookApp, page }) => {
		await page.route('**/apps/cashbook/api/v1/cashbooks/*', (route) => route.request().method() === 'PUT'
			? route.fulfill({
					status: 400,
					contentType: 'application/json',
					body: JSON.stringify({ ocs: { meta: { status: 'failure', statuscode: 400, message: 'The account is too long' }, data: [] } }),
				})
			: route.fallback())

		await cashbookApp.editCashbook('Shop register', { name: 'Market stall', account: '1200' })

		const dialog = cashbookApp.editCashbookDialog()
		await expect(dialog).toBeVisible()
		await expect(dialog.getByText('The account is too long')).toBeVisible()
		await expect(cashbookApp.cashbookNavigationItem('Shop register')).toBeVisible()
		await expect(cashbookApp.cashbookNavigationItem('Market stall')).toHaveCount(0)
	})
})
