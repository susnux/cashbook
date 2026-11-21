/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { expect, test } from '../support/fixtures/cashbook-app.ts'

test.describe('Cashbook lifecycle', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
	})

	test('shows the empty view without cashbooks', async ({ cashbookApp }) => {
		await expect(cashbookApp.emptyContent('No cashbook selected')).toBeVisible()
		await expect(cashbookApp.cashbookList().getByRole('listitem')).toHaveCount(0)
	})

	test('creates, opens and deletes a cashbook', async ({ cashbookApp, page }) => {
		await cashbookApp.createCashbook({ name: 'Shop register', account: '1000' })

		await expect(cashbookApp.newCashbookDialog()).toBeHidden()
		await expect(cashbookApp.cashbookNavigationItem('Shop register')).toBeVisible()
		await expect(page).toHaveURL(/\/apps\/cashbook\/\d+/)
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')
		await expect(cashbookApp.emptyContent('No entries')).toBeVisible()

		// the cashbook is persisted and the deep link is served by the server
		await page.reload()
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')

		await cashbookApp.deleteCashbook('Shop register')

		await expect(cashbookApp.deleteCashbookDialog()).toBeHidden()
		await expect(cashbookApp.cashbookNavigationItem('Shop register')).toHaveCount(0)
		await expect(page).toHaveURL(/\/apps\/cashbook\/?$/)
		await expect(cashbookApp.emptyContent('No cashbook selected')).toBeVisible()
	})

	test('keeps the dialog open when creating the cashbook fails until the input is changed', async ({ cashbookApp, page }) => {
		await page.route('**/apps/cashbook/api/v1/cashbooks', (route) => route.request().method() === 'POST'
			? route.fulfill({
					status: 400,
					contentType: 'application/json',
					body: JSON.stringify({ ocs: { meta: { status: 'failure', statuscode: 400, message: 'Invalid currency' }, data: [] } }),
				})
			: route.fallback())

		await cashbookApp.createCashbook({ name: 'Broken' })

		const dialog = cashbookApp.newCashbookDialog()
		const error = dialog.getByText('Invalid currency')
		await expect(dialog).toBeVisible()
		await expect(error).toBeVisible()
		await expect(cashbookApp.cashbookNavigationItem('Broken')).toHaveCount(0)

		await dialog.getByRole('textbox', { name: 'Cashbook name' }).fill('Fixed')
		await expect(error).toBeHidden()
	})

	test('shows not found for an unknown cashbook', async ({ cashbookApp, page }) => {
		await page.goto('apps/cashbook/999999')
		await expect(cashbookApp.emptyContent('Cashbook not found')).toBeVisible()
	})
})
