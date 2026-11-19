/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { APIRequestContext } from '@playwright/test'

import { readFile } from 'node:fs/promises'
import { join } from 'node:path'

export const SUMUP_FIXTURES = join(import.meta.dirname, '..', '..', '..', 'fixtures', 'sumup')

/**
 * Import the SumUp fixture with day reports (`Kassenbuch_report.csv` and `Tagesabschluss_report.csv`)
 * as a new cashbook of the user the request context is authenticated as.
 *
 * @param request - The request context of the user
 * @param timezone - The timezone of the dates in the fixture
 * @return The id of the imported cashbook
 */
export async function importReportFixture(request: APIRequestContext, timezone: string): Promise<string> {
	const form = new FormData()
	form.append('import', 'sumup-pos-pro-de')
	form.append('timezone', timezone)
	for (const [field, name] of Object.entries({ cashbook: 'Kassenbuch_report.csv', report: 'Tagesabschluss_report.csv' })) {
		form.append(`files[${field}]`, new Blob([await readFile(join(SUMUP_FIXTURES, name))], { type: 'text/csv' }), name)
	}

	const response = await request.post('/ocs/v2.php/apps/cashbook/api/v1/cashbooks/import', {
		headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
		multipart: form,
	})
	if (!response.ok()) {
		throw new Error(`Failed to import the cashbook (${response.status()}): ${await response.text()}`)
	}
	const { ocs } = await response.json()
	return ocs.data.id
}
