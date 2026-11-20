/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Locator, Page, Response } from '@playwright/test'

export interface NewCashbookOptions {
	name: string
	account?: string
	/** The IANA timezone ID, the timezone of the browser is kept if not set */
	timezone?: string
}

export interface ImportCashbookOptions {
	/** Path of the cashbook export */
	cashbook: string
	/** Path of the daily closing report */
	report?: string
}

export interface ReverseEntryOptions {
	/** The reason for the reversal */
	reason: string
	reference?: string
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

	importCashbookDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'Import SumUp cashbook' })
	}

	newEntryDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'New entry' })
	}

	reverseEntryDialog(): Locator {
		return this.page.getByRole('dialog', { name: 'Reverse entry' })
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

	/**
	 * Get the table row of the entry with the given sequence number
	 *
	 * @param sequence - The sequence number of the entry
	 */
	entryRowBySequence(sequence: number): Locator {
		return this.entriesTable().locator(`#entry-${sequence}`)
	}

	/**
	 * Get the actions menu button of the entry with the given sequence number
	 *
	 * @param sequence - The sequence number of the entry
	 */
	entryActions(sequence: number): Locator {
		return this.entryRowBySequence(sequence).getByRole('button', { name: `Actions for entry #${sequence}` })
	}

	async open(): Promise<void> {
		await this.page.goto('apps/cashbook/')
		await this.navigation().getByRole('button', { name: 'New cashbook' }).waitFor()
	}

	/**
	 * Show a month of the current cashbook
	 *
	 * @param month - The month as `YYYY-MM`
	 */
	async showMonth(month: string): Promise<void> {
		const [year, monthNumber] = month.split('-').map(Number) as [number, number]
		const lastDay = new Date(Date.UTC(year, monthNumber, 0)).getUTCDate()
		await this.page.goto(this.page.url().replace(/\?.*$/, '') + `?period=month&from=${month}-01&to=${month}-${lastDay}`)
	}

	/**
	 * Create a cashbook through the "New cashbook" dialog
	 *
	 * @param options - The values to enter into the dialog
	 * @return The response of the create request
	 */
	async createCashbook({ name, account, timezone }: NewCashbookOptions): Promise<Response> {
		await this.navigation().getByRole('button', { name: 'New cashbook' }).click()

		const dialog = this.newCashbookDialog()
		await dialog.getByRole('textbox', { name: 'Cashbook name' }).fill(name)
		if (account) {
			await dialog.getByRole('textbox', { name: 'Account' }).fill(account)
		}
		if (timezone) {
			await this.selectTimezone(dialog.getByRole('combobox', { name: 'Timezone' }), timezone)
		}

		const created = this.page.waitForResponse((response) => response.request().method() === 'POST'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks'))
		await dialog.getByRole('button', { name: 'Create cashbook' }).click()
		return await created
	}

	/**
	 * Select a timezone with a timezone picker
	 *
	 * @param combobox - The combobox of the timezone picker
	 * @param timezone - The IANA timezone ID
	 */
	async selectTimezone(combobox: Locator, timezone: string): Promise<void> {
		await combobox.fill(timezone)
		await combobox.press('Enter')
	}

	/**
	 * Select a file with a file picker of the "Import SumUp cashbook" dialog
	 *
	 * @param picker - The label of the file picker
	 * @param path - Path of the file to select
	 */
	async selectImportFile(picker: string, path: string): Promise<void> {
		const fileChooser = this.page.waitForEvent('filechooser')
		await this.importCashbookDialog().getByRole('button', { name: picker }).click()
		await (await fileChooser).setFiles(path)
	}

	/**
	 * Open the "Import SumUp cashbook" dialog from the navigation
	 */
	async openImportCashbookDialog(): Promise<Locator> {
		await this.navigation().getByText('Import cashbook', { exact: true }).click()
		const dialog = this.importCashbookDialog()
		await dialog.waitFor()
		return dialog
	}

	/**
	 * Open the "Import SumUp cashbook" dialog, select the files and submit it.
	 * The export is imported in the timezone of the browser.
	 *
	 * @param options - The files to import
	 * @return The response of the import request
	 */
	async importCashbook({ cashbook, report }: ImportCashbookOptions): Promise<Response> {
		const dialog = await this.openImportCashbookDialog()

		await this.selectImportFile('Select cashbook export', cashbook)
		if (report) {
			await this.selectImportFile('Select daily closing report', report)
		}

		const imported = this.page.waitForResponse((response) => response.request().method() === 'POST'
			&& response.url().includes('/apps/cashbook/api/v1/cashbooks/import'))
		await dialog.getByRole('button', { name: 'Import cashbook' }).click()
		return await imported
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
	 * Open the "Reverse entry" dialog of an entry through its actions menu
	 *
	 * @param sequence - The sequence number of the entry to reverse
	 */
	async openReverseEntryDialog(sequence: number): Promise<Locator> {
		await this.entryActions(sequence).click()
		await this.page.getByRole('menuitem', { name: 'Reverse entry' }).click()
		const dialog = this.reverseEntryDialog()
		await dialog.waitFor()
		return dialog
	}

	/**
	 * Fill in the open "Reverse entry" dialog, submit it and wait for the response of the server
	 *
	 * @param options - The values to enter into the dialog
	 */
	async submitReverseEntryDialog({ reason, reference }: ReverseEntryOptions): Promise<Response> {
		const dialog = this.reverseEntryDialog()
		await dialog.getByRole('textbox', { name: 'Reason for the reversal' }).fill(reason)
		if (reference) {
			await dialog.getByRole('textbox', { name: 'Receipt number' }).fill(reference)
		}

		const reversed = this.page.waitForResponse((response) => response.request().method() === 'POST'
			&& /\/apps\/cashbook\/api\/v1\/cashbooks\/\d+\/entries\/\d+\/reverse/.test(response.url()))
		await dialog.getByRole('button', { name: 'Reverse entry' }).click()
		return await reversed
	}

	/**
	 * Reverse an entry through its actions menu and the "Reverse entry" dialog
	 *
	 * @param sequence - The sequence number of the entry to reverse
	 * @param options - The values to enter into the dialog
	 */
	async reverseEntry(sequence: number, options: ReverseEntryOptions): Promise<void> {
		await this.openReverseEntryDialog(sequence)
		await this.submitReverseEntryDialog(options)
		await this.reverseEntryDialog().waitFor({ state: 'hidden' })
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
