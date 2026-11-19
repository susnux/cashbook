/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'

/**
 * Add an entry to a cashbook through the API, like another client of the user would
 *
 * @param request - The request context of the user
 * @param cashbookId - The id of the cashbook
 * @param entry - The request body of the new entry
 */
export async function addEntryWithApi(request: APIRequestContext, cashbookId: string, entry: Record<string, unknown>): Promise<void> {
	const response = await request.post(`/ocs/v2.php/apps/cashbook/api/v1/cashbooks/${cashbookId}/entries`, {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		data: entry,
	})
	if (!response.ok()) {
		throw new Error(`Failed to add the entry (${response.status()}): ${await response.text()}`)
	}
}
