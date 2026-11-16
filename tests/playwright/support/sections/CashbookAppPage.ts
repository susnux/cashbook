/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Locator, Page, Response } from '@playwright/test'

export interface NewCashbookOptions {
	name: string
	account?: string
}

export interface NewEntryOptions {
	type: 'Income' | 'Expense'
	/** The readable name of the reason, the first reason of the type is used if not set */
	reason?: string
	amount: string
	/** The tax rate in percent */
	tax?: string
	text?: string
	reference?: string
	/** The receipt date as `YYYY-MM-DD` */
	receiptDate?: string
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

	editCashbookDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'Edit cashbook' })
	}

	deleteCashbookDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'Delete cashbook' })
	}

	newEntryDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'New entry' })
	}

	addEntryButton(): Locator {
		return this.page.getByRole('main').getByRole('button', { name: 'Add entry' })
	}

	entriesTable(): Locator {
		return this.page.getByRole('main').getByRole('table').first()
	}

	/**
	 * Get the table row of the entry with the given text
	 *
	 * @param text - The text of the entry
	 */
	entryRow(text: string): Locator {
		return this.entriesTable().getByRole('row').filter({ has: this.page.getByRole('cell', { name: text, exact: true }) })
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
	 * Open the "New entry" dialog of the current cashbook and fill it in
	 *
	 * @param options - The values to enter into the dialog
	 */
	async fillNewEntryDialog({ type, reason, amount, tax, text, reference, receiptDate }: NewEntryOptions): Promise<Locator> {
		await this.addEntryButton().click()
		const dialog = this.newEntryDialog()
		await dialog.waitFor()

		// the radio input is hidden, the visible button is its label
		await dialog.getByText(type, { exact: true }).click()
		if (reason) {
			await dialog.getByRole('combobox', { name: 'Reason' }).click()
			await this.page.getByRole('option', { name: reason, exact: true }).click()
		}
		await dialog.getByRole('spinbutton', { name: /^Amount/ }).fill(amount)
		if (tax) {
			await dialog.getByRole('spinbutton', { name: 'Tax rate (%)' }).fill(tax)
		}
		if (text) {
			await dialog.getByRole('textbox', { name: 'Text' }).fill(text)
		}
		if (reference) {
			await dialog.getByRole('textbox', { name: 'Receipt number' }).fill(reference)
		}
		if (receiptDate) {
			await dialog.getByLabel('Receipt date', { exact: true }).fill(receiptDate)
		}
		return dialog
	}

	/**
	 * Submit the "New entry" dialog and wait for the response of the server
	 */
	async submitNewEntryDialog(): Promise<Response> {
		const added = this.page.waitForResponse((response) => response.request().method() === 'POST'
			&& /\/apps\/cashbook\/api\/v1\/cashbooks\/\d+\/entries/.test(response.url()))
		await this.newEntryDialog().getByRole('button', { name: 'Add entry' }).click()
		return await added
	}

	/**
	 * Add an entry to the current cashbook through the "New entry" dialog
	 *
	 * @param options - The values to enter into the dialog
	 */
	async addEntry(options: NewEntryOptions): Promise<void> {
		await this.fillNewEntryDialog(options)
		await this.submitNewEntryDialog()
		await this.newEntryDialog().waitFor({ state: 'hidden' })
	}

	/**
	 * Run an action from the actions menu of a cashbook navigation entry
	 *
	 * @param name - Name of the cashbook
	 * @param action - Name of the action
	 */
	async runCashbookAction(name: string, action: string): Promise<void> {
		await this.cashbookNavigationItem(name).getByRole('button', { name: 'Actions' }).click()
		await this.page.getByRole('menuitem', { name: action }).click()
	}

	/**
	 * Open the "Edit cashbook" dialog of a cashbook
	 *
	 * @param name - Name of the cashbook to edit
	 */
	async openEditCashbookDialog(name: string): Promise<Locator> {
		await this.runCashbookAction(name, 'Edit')
		const dialog = this.editCashbookDialog()
		await dialog.waitFor()
		return dialog
	}

	/**
	 * Change name and account of a cashbook through the "Edit cashbook" dialog
	 *
	 * @param name - Current name of the cashbook
	 * @param options - The new values to enter into the dialog
	 */
	async editCashbook(name: string, options: NewCashbookOptions): Promise<void> {
		const dialog = await this.openEditCashbookDialog(name)
		await dialog.getByRole('textbox', { name: 'Cashbook name' }).fill(options.name)
		await dialog.getByRole('textbox', { name: 'Account' }).fill(options.account ?? '')

		const updated = this.page.waitForResponse((response) => response.request().method() === 'PUT'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks/'))
		await dialog.getByRole('button', { name: 'Save' }).click()
		await updated
	}

	/**
	 * Delete a cashbook using the action of its navigation entry
	 *
	 * @param name - Name of the cashbook to delete
	 */
	async deleteCashbook(name: string): Promise<void> {
		await this.runCashbookAction(name, 'Delete')

		const dialog = this.deleteCashbookDialog()
		const deleted = this.page.waitForResponse((response) => response.request().method() === 'DELETE'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks/'))
		await dialog.getByRole('button', { name: 'Delete cashbook' }).click()
		await deleted
	}
}
