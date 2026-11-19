/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { join } from 'node:path'
import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { importReportFixture, SUMUP_FIXTURES } from '../support/utils/import.ts'

test.use({ timezoneId: 'Europe/Berlin', locale: 'en-US' })

/** Only the first day of the report fixture, 2 January in Auckland is 1 January in Berlin */
const SECOND_OF_JANUARY = 'period=custom&from=2025-01-02&to=2025-01-02'

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

	for (const page of ['app', 'export'] as const) {
		test(`shows the days and times of the cashbook timezone on the ${page} page`, async ({ page: browserPage }) => {
			const cashbookId = await importReportFixture(browserPage.request, 'Pacific/Auckland')
			await browserPage.goto(`apps/cashbook/${cashbookId}${page === 'export' ? '/export' : ''}?${SECOND_OF_JANUARY}`)

			const main = browserPage.getByRole('main')
			await expect(main.getByText('Timezone: Pacific/Auckland', { exact: true })).toBeVisible()
			const table = main.getByRole('table').first()
			const days = table.getByRole('columnheader', { name: /^Opened:/ })
			await expect(days).toHaveCount(1)
			await expect(days).toHaveAccessibleName(/^Opened: Jan 2, 2025, 9:00\sAM Closed: Jan 2, 2025, 6:00\sPM Z-number: Z-1 /)
			await expect(table.getByRole('cell', { name: /^Jan 2, 2025, (9:30|10:00)\sAM$/ })).toHaveCount(2)
		})

		test(`opens the current month of the cashbook timezone on the ${page} page`, async ({ page: browserPage }) => {
			const cashbookId = await importReportFixture(browserPage.request, 'Pacific/Auckland')
			// 1 February in Auckland, still 31 January in Berlin
			await browserPage.clock.install({ time: new Date('2025-01-31T12:00:00Z') })
			await browserPage.goto(`apps/cashbook/${cashbookId}${page === 'export' ? '/export' : ''}`)

			await expect(browserPage.getByRole('main').getByText('February 2025', { exact: true })).toBeVisible()
		})
	}
})

test.describe('Cashbook timezone of a browser with a fixed offset', () => {
	test.use({ timezoneId: 'Etc/GMT-1' })

	test.beforeEach(async ({ cashbookApp }) => {
		await cashbookApp.open()
	})

	test('creates the cashbook in UTC by default', async ({ cashbookApp }) => {
		await cashbookApp.navigation().getByRole('button', { name: 'New cashbook' }).click()
		await expect(cashbookApp.newCashbookDialog().getByText('UTC', { exact: true })).toBeVisible()
		await cashbookApp.newCashbookDialog().getByRole('button', { name: 'Cancel' }).click()

		const response = await cashbookApp.createCashbook({ name: 'Shop register' })

		expect((await response.json()).ocs.data.timezone).toBe('UTC')
	})
})
