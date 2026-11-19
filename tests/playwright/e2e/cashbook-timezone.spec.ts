/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { join } from 'node:path'
import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { SUMUP_FIXTURES } from '../support/utils/import.ts'

test.use({ timezoneId: 'Europe/Berlin', locale: 'en-US' })

test.describe('Cashbook timezone', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
	})

	test('creates the cashbook in the timezone of the browser by default', async ({ cashbookApp }) => {
		const response = await cashbookApp.createCashbook({ name: 'Shop register' })

		expect((await response.json()).ocs.data.timezone).toBe('Europe/Berlin')
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')

		const dialog = await cashbookApp.openEditCashbookDialog('Shop register')
		await expect(dialog.getByText('Berlin', { exact: true })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'Timezone' })).toBeDisabled()
	})

	test('creates the cashbook in the selected timezone', async ({ cashbookApp, page }) => {
		const response = await cashbookApp.createCashbook({ name: 'Shop register', timezone: 'Pacific/Auckland' })

		expect((await response.json()).ocs.data.timezone).toBe('Pacific/Auckland')
		await expect(cashbookApp.cashbookHeading()).toHaveText('Shop register')

		await page.reload()
		const dialog = await cashbookApp.openEditCashbookDialog('Shop register')
		await expect(dialog.getByText('Auckland', { exact: true })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'Timezone' })).toBeDisabled()
	})

	test('imports the cashbook in the timezone of the export', async ({ cashbookApp }) => {
		const response = await cashbookApp.importCashbook({ cashbook: join(SUMUP_FIXTURES, 'Kassenbuch_basic.csv') })

		expect(response.ok()).toBe(true)
		await expect(cashbookApp.cashbookHeading()).toHaveText('Test Shop')
		const dialog = await cashbookApp.openEditCashbookDialog('Test Shop')
		await expect(dialog.getByText('Berlin', { exact: true })).toBeVisible()
		await expect(dialog.getByRole('combobox', { name: 'Timezone' })).toBeDisabled()
	})
})
