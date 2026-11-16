/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { createRandomUser } from '@nextcloud/e2e-test-server/playwright'
import { request as playwrightRequest } from '@playwright/test'
import { expect, test } from '../support/fixtures/cashbook-app.ts'
import { importReportFixture } from '../support/utils/import.ts'
import { retryWithBackoff } from '../support/utils/retry.ts'

const TIMEZONE = 'Europe/Berlin'
const JANUARY_2025 = 'period=month&from=2025-01-01&to=2025-01-31'

test.use({ timezoneId: TIMEZONE, locale: 'en-US' })

test.describe('Export cashbook', () => {
	let cashbookId: number

	test.beforeEach(async ({ page }) => {
		cashbookId = await importReportFixture(page.request, TIMEZONE)
	})

	test('opens the export page with the selected period', async ({ cashbookApp, page }) => {
		await page.goto(`apps/cashbook/${cashbookId}?${JANUARY_2025}`)
		await expect(cashbookApp.cashbookHeading()).toHaveText('Test Shop')

		const popupPromise = page.waitForEvent('popup')
		await page.getByRole('main').getByRole('link', { name: 'Export' }).click()
		const exportPage = await popupPromise

		await expect(exportPage).toHaveURL(new RegExp(`/apps/cashbook/${cashbookId}/export\\?${JANUARY_2025}$`))
		await expect(exportPage.getByRole('main').getByRole('heading', { level: 2 })).toHaveText('Test Shop')
		await expect(exportPage.getByText('January 2025', { exact: true })).toBeVisible()
		await expect(exportPage.getByRole('button', { name: 'Print' })).toBeVisible()
		// the export page is rendered without the app navigation
		await expect(exportPage.getByRole('navigation')).toHaveCount(0)
	})

	test('shows the totals of each day and of the period', async ({ page }) => {
		await page.goto(`apps/cashbook/${cashbookId}/export?${JANUARY_2025}`)

		const table = page.getByRole('main').getByRole('table').first()
		// one entry before the first day opening, two opened days and one entry after the last day closing
		const dayTotals = table.getByRole('row').filter({ hasText: 'Total of the day' })
		await expect(dayTotals).toHaveCount(4)
		await expect(dayTotals.nth(1).getByRole('cell')).toHaveText(['', '€20.00', '€5.00', ''])
		const includedTax = table.getByRole('row').filter({ hasText: 'Included tax' })
		await expect(includedTax).toHaveCount(4)
		await expect(includedTax.nth(1).getByRole('cell')).toHaveText(['', '19%', '€3.19', '€0.00', ''])

		const summary = page.getByRole('region', { name: 'Totals of the period' })
		await expect(summary.getByRole('definition')).toHaveText(['€90.00', '€41.00', '€5.00', '€126.00'])

		const taxes = summary.getByRole('table', { name: 'Taxes per rate' })
		await expect(taxes.getByRole('row', { name: '19%' }).getByRole('cell')).toHaveText(['€30.00', '€25.21', '€4.79', '€0.00', '€0.00', '€0.00'])
		await expect(taxes.getByRole('row', { name: '7%' }).getByRole('cell')).toHaveText(['€11.00', '€10.28', '€0.72', '€0.00', '€0.00', '€0.00'])
		await expect(taxes.getByRole('row', { name: 'No tax rate' }).getByRole('cell')).toHaveText(['€0.00', '', '', '€5.00', '', ''])
		await expect(taxes.getByRole('row', { name: 'Total' }).getByRole('cell')).toHaveText(['€41.00', '', '€5.51', '€5.00', '', '€0.00'])
	})

	test('updates the totals when changing the period', async ({ page }) => {
		await page.goto(`apps/cashbook/${cashbookId}/export?${JANUARY_2025}`)
		const summary = page.getByRole('region', { name: 'Totals of the period' })
		await expect(summary.getByRole('definition').nth(1)).toHaveText('€41.00')

		await page.getByRole('button', { name: 'Next period' }).click()

		await expect(page).toHaveURL(/period=month&from=2025-02-01&to=2025-02-28/)
		await expect(page.getByText('No entries', { exact: true })).toBeVisible()
		await expect(summary).toHaveCount(0)
	})

	test('shows the error of the server if the entries can not be loaded', async ({ cashbookApp, page }) => {
		await page.route('**/apps/cashbook/api/v1/cashbooks/*/entries/print?*', (route) => route.fulfill({
			status: 400,
			json: { ocs: { meta: { status: 'failure', statuscode: 400, message: 'The period contains too many entries.' }, data: [] } },
		}))
		await page.goto(`apps/cashbook/${cashbookId}/export?${JANUARY_2025}`)

		await expect(cashbookApp.emptyContent('Could not load the entries')).toBeVisible()
		await expect(page.getByRole('main').getByText('The period contains too many entries.')).toBeVisible()
	})

	test('opens the print dialog', async ({ page }) => {
		await page.addInitScript(() => {
			window.print = () => {
				document.body.dataset.printed = 'true'
			}
		})
		await page.goto(`apps/cashbook/${cashbookId}/export?${JANUARY_2025}`)

		await page.getByRole('button', { name: 'Print' }).click()
		await expect(page.locator('body')).toHaveAttribute('data-printed', 'true')
	})

	test('does not show the cashbook of another user', async ({ baseURL, cashbookApp, page }) => {
		const other = await retryWithBackoff('Creating a random user', () => createRandomUser())
		const otherRequest = await playwrightRequest.newContext({
			baseURL,
			httpCredentials: { username: other.userId, password: other.password, send: 'always' },
		})
		try {
			const otherCashbookId = await importReportFixture(otherRequest, TIMEZONE)

			await page.goto(`apps/cashbook/${otherCashbookId}/export?${JANUARY_2025}`)
			await expect(page).toHaveURL(/\/apps\/cashbook\/not-found$/)
			await expect(cashbookApp.emptyContent('Cashbook not found')).toBeVisible()
		} finally {
			await otherRequest.dispose()
			await runOcc(['user:delete', other.userId], { failOnError: false })
		}
	})
})
