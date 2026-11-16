/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Locator, Page } from '@playwright/test'

export interface NewCashbookOptions {
	name: string
	account?: string
}

/**
 * Page object for the main view of the cashbook app
 */
export class CashbookAppPage {
	constructor(private readonly page: Page) {}

	navigation(): Locator {
		return this.page.getByRole('navigation')
	}

	cashbookList(): Locator {
		return this.navigation().getByRole('list', { name: 'Your cashbooks' })
	}

	cashbookNavigationItem(name: string): Locator {
		return this.cashbookList().getByRole('listitem').filter({ has: this.page.getByRole('link', { name, exact: true }) })
	}

	cashbookHeading(): Locator {
		return this.page.getByRole('main').getByRole('heading', { level: 2 })
	}

	emptyContent(name: string): Locator {
		return this.page.getByRole('main').getByText(name, { exact: true })
	}

	newCashbookDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'New cashbook' })
	}

	deleteCashbookDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'Delete cashbook' })
	}

	async open(): Promise<void> {
		await this.page.goto('apps/cashbook/')
		await this.navigation().getByRole('button', { name: 'New cashbook' }).waitFor()
	}

	/**
	 * Create a cashbook through the "New cashbook" dialog
	 *
	 * @param options - The values to enter into the dialog
	 */
	async createCashbook({ name, account }: NewCashbookOptions): Promise<void> {
		await this.navigation().getByRole('button', { name: 'New cashbook' }).click()

		const dialog = this.newCashbookDialog()
		await dialog.getByRole('textbox', { name: 'Cashbook name' }).fill(name)
		if (account) {
			await dialog.getByRole('textbox', { name: 'Account' }).fill(account)
		}

		const created = this.page.waitForResponse((response) => response.request().method() === 'POST'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks'))
		await dialog.getByRole('button', { name: 'Create cashbook' }).click()
		await created
	}

	/**
	 * Delete a cashbook using the action of its navigation entry
	 *
	 * @param name - Name of the cashbook to delete
	 */
	async deleteCashbook(name: string): Promise<void> {
		await this.cashbookNavigationItem(name).getByRole('button', { name: 'Delete' }).click()

		const dialog = this.deleteCashbookDialog()
		const deleted = this.page.waitForResponse((response) => response.request().method() === 'DELETE'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks/'))
		await dialog.getByRole('button', { name: 'Delete cashbook' }).click()
		await deleted
	}
}
