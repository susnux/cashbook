/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OCSResponse } from '@nextcloud/typings/ocs'
import type { ICashbook, ICashbookData } from '../models/Cashbook.ts'
import type { EntryReason, ICashbookEntry } from '../models/CashbookEntry.ts'

import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'

enum API_ENDPOINT {
	Cashbook = 'cashbooks',
}

interface ApiOptions {
	version: '1'
}

/**
 * Generate the OCS URL for an API endpoint of the cashbook app
 *
 * @param endpoint - The API endpoint to use
 * @param path - Optional sub path appended to the endpoint URL (including the leading slash)
 * @param options - Options for the API route
 */
function generateApiRoute(
	endpoint: API_ENDPOINT,
	path = '',
	options: ApiOptions = { version: '1' },
): string {
	const API_BASE = 'apps/cashbook/api'
	return generateOcsUrl(`${API_BASE}/v${options.version}/{endpoint}`, { endpoint }) + path
}

/**
 * Fetch list of available cashbooks for the current user
 */
export async function fetchCashbooks(): Promise<ICashbook[]> {
	const { data } = await axios.get<OCSResponse<ICashbook[]>>(generateApiRoute(API_ENDPOINT.Cashbook))
	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data
}

/**
 * Fetch a single cashbook by its id
 *
 * @param id - The cashbook id
 */
export async function fetchCashbook(id: string): Promise<ICashbook> {
	const { data } = await axios.get<OCSResponse<ICashbook>>(generateApiRoute(API_ENDPOINT.Cashbook, `/${id}`))
	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data
}

type RawCashbookEntry = Omit<ICashbookEntry, 'date'> & {
	date: string
}

/**
 * Convert the ISO date string of an entry returned by the API into a Date object
 *
 * @param entry - The raw entry as returned by the API
 */
function parseEntry(entry: RawCashbookEntry): ICashbookEntry {
	return {
		...entry,
		date: new Date(entry.date),
	}
}

type FetchEntryOption = {
	offset: number
	limit: number
}

/**
 * Fetch a paginated list of entries of a cashbook
 *
 * @param cashbook - The cashbook to fetch the entries for
 * @param options - Pagination options
 * @param options.offset - Number of entries to skip
 * @param options.limit - Maximum number of entries to fetch
 */
export async function fetchEntries(cashbook: ICashbook, { offset, limit }: FetchEntryOption): Promise<ICashbookEntry[]> {
	const { data } = await axios.get<OCSResponse<RawCashbookEntry[]>>(generateApiRoute(API_ENDPOINT.Cashbook, `/${cashbook.id}/entries?limit=${limit}&offset=${offset}`))
	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data.map(parseEntry)
}

type FetchEntryByDateOption = {
	begin: Date
	end: Date
	signal?: AbortSignal
}

/**
 * Fetch all entries of a cashbook within a date range
 *
 * @param cashbook - The cashbook to fetch the entries for
 * @param options - The date range to fetch
 * @param options.begin - Start of the date range (inclusive)
 * @param options.end - End of the date range (exclusive)
 * @param options.signal - Signal to abort the request
 */
export async function fetchEntriesByDate(cashbook: ICashbook, { begin, end, signal }: FetchEntryByDateOption): Promise<ICashbookEntry[]> {
	const { data } = await axios.get<OCSResponse<RawCashbookEntry[]>>(
		generateApiRoute(API_ENDPOINT.Cashbook, `/${cashbook.id}/entries/print`),
		{ params: { begin: begin.toISOString(), end: end.toISOString() }, signal },
	)
	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data.map(parseEntry)
}

export interface NewEntryData {
	/** An income or expense reason */
	reason: EntryReason
	/** The positive amount as decimal string, expenses are subtracted from the balance */
	amount: string
	text: string
	/** The tax rate (e.g. `0.19` for 19%) */
	tax: number | null
	/** The receipt number */
	reference: string
	/** The date of the receipt (`YYYY-MM-DD`) */
	referenceDate: string | null
}

/**
 * Add an income or expense entry after the last entry of a cashbook
 *
 * @param cashbook - The cashbook to add the entry to
 * @param entry - The entry to add
 * @return The added entry
 */
export async function addEntry(cashbook: ICashbook, entry: NewEntryData): Promise<ICashbookEntry> {
	const { data } = await axios.post<OCSResponse<RawCashbookEntry>>(
		generateApiRoute(API_ENDPOINT.Cashbook, `/${cashbook.id}/entries`),
		entry,
	)
	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return parseEntry(data.ocs.data)
}

/**
 * Create a new cashbook
 *
 * @param cashbook - The cashbook to create
 * @return The newly created cashbook
 */
export async function createCashbook(cashbook: ICashbookData): Promise<ICashbook> {
	const { name, account, currency } = cashbook
	const { data } = await axios.post<OCSResponse<ICashbook>>(
		generateApiRoute(API_ENDPOINT.Cashbook),
		{ name, account, currency },
	)

	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data
}

/**
 * Change the name and account of a cashbook, the currency can not be changed
 *
 * @param id - The id of the cashbook to update
 * @param cashbook - The new name and account
 * @return The updated cashbook
 */
export async function updateCashbook(id: string, cashbook: Pick<ICashbookData, 'name' | 'account'>): Promise<ICashbook> {
	const { name, account } = cashbook
	const { data } = await axios.put<OCSResponse<ICashbook>>(
		generateApiRoute(API_ENDPOINT.Cashbook, `/${id}`),
		{ name, account },
	)

	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return data.ocs.data
}

/**
 * Import a cashbook from a SumUp POS Pro (German) export.
 *
 * @param files - The cashbook CSV (`Kassenbuch_*.csv`) and optionally the daily closing report CSV
 * @param timezone - The timezone of the dates in the export (IANA timezone ID)
 * @return The newly created cashbook
 */
export async function importSumupCashbook(files: File[], timezone: string): Promise<ICashbook> {
	const formData = new FormData()
	formData.append('import', 'sumup-pos-pro-de')
	formData.append('timezone', timezone)
	for (const file of files) {
		formData.append('files[]', file)
	}

	const { data } = await axios.post<OCSResponse<{ id: string }>>(
		generateApiRoute(API_ENDPOINT.Cashbook, '/import'),
		formData,
	)

	if (data.ocs.meta.status !== 'ok') {
		throw new Error(data.ocs.meta.message, { cause: data.ocs })
	}

	return await fetchCashbook(data.ocs.data.id)
}

/**
 * Delete a cashbook
 *
 * @param cashbook - The cashbook to delete
 */
export async function deleteCashbook(cashbook: ICashbook): Promise<void> {
	await axios.delete(generateApiRoute(API_ENDPOINT.Cashbook, `/${cashbook.id}`))
}
