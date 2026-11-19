/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { join } from 'node:path'
import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { SUMUP_FIXTURES } from '../support/utils/import.ts'

const CASHBOOK_EXPORT = join(SUMUP_FIXTURES, 'Kassenbuch_report.csv')
const DAILY_CLOSING_REPORT = join(SUMUP_FIXTURES, 'Tagesabschluss_report.csv')

test.use({ timezoneId: 'Europe/Berlin', locale: 'en-US' })

test.describe('Import cashbook', () => {
	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
	})

	test('imports the cashbook export with the daily closing report', async ({ cashbookApp, page }) => {
		const response = await cashbookApp.importCashbook({ cashbook: CASHBOOK_EXPORT, report: DAILY_CLOSING_REPORT })

		expect(response.ok()).toBe(true)
		await expect(cashbookApp.importCashbookDialog()).toBeHidden()
		await expect(cashbookApp.cashbookNavigationItem('Test Shop')).toBeVisible()
		await expect(cashbookApp.cashbookHeading()).toHaveText('Test Shop')

		await page.goto(page.url().replace(/\?.*$/, '') + '?period=month&from=2025-01-01&to=2025-01-31')
		const days = cashbookApp.entriesTable().getByRole('columnheader', { name: /^Opened:/ })
		await expect(days).toHaveCount(2)
		await expect(days.nth(0)).toHaveAccessibleName(/Z-number: Z-1 /)
		await expect(days.nth(1)).toHaveAccessibleName(/Z-number: Z-2 /)
	})

	test('imports the cashbook export without a daily closing report', async ({ cashbookApp, page }) => {
		const response = await cashbookApp.importCashbook({ cashbook: CASHBOOK_EXPORT })

		expect(response.ok()).toBe(true)
		await expect(cashbookApp.cashbookHeading()).toHaveText('Test Shop')

		await page.goto(page.url().replace(/\?.*$/, '') + '?period=month&from=2025-01-01&to=2025-01-31')
		await expect(cashbookApp.entriesTable().getByRole('row')).not.toHaveCount(0)
		await expect(cashbookApp.entriesTable().getByRole('columnheader', { name: /^Opened:/ })).toHaveCount(0)
	})

	test('requires the cashbook export and allows removing the daily closing report', async ({ cashbookApp }) => {
		await cashbookApp.navigation().getByText('Import cashbook', { exact: true }).click()
		const dialog = cashbookApp.importCashbookDialog()
		const importButton = dialog.getByRole('button', { name: 'Import cashbook' })

		await cashbookApp.selectImportFile('Select daily closing report', DAILY_CLOSING_REPORT)
		await expect(dialog.getByText('Tagesabschluss_report.csv', { exact: true })).toBeVisible()
		await expect(importButton).toBeDisabled()

		await cashbookApp.selectImportFile('Select cashbook export', CASHBOOK_EXPORT)
		await expect(dialog.getByText('Kassenbuch_report.csv', { exact: true })).toBeVisible()
		await expect(importButton).toBeEnabled()

		await dialog.getByRole('button', { name: 'Remove daily closing report' }).click()
		await expect(dialog.getByText('Tagesabschluss_report.csv', { exact: true })).toBeHidden()
		await expect(importButton).toBeEnabled()
	})

	test('keeps the dialog open if the files are swapped', async ({ cashbookApp }) => {
		const response = await cashbookApp.importCashbook({ cashbook: DAILY_CLOSING_REPORT, report: CASHBOOK_EXPORT })

		expect(response.status()).toBe(400)
		const dialog = cashbookApp.importCashbookDialog()
		await expect(dialog).toBeVisible()
		await expect(dialog.getByText('The selected cashbook export is not a valid SumUp cashbook export.')).toBeVisible()
		await expect(cashbookApp.cashbookList().getByRole('listitem')).toHaveCount(0)
	})
})
