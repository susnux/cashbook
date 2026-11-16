/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest'
import { getOcsErrorMessage } from './errors.ts'

/**
 * Create an axios error with the given response body
 *
 * @param data - The response body
 */
function createAxiosError(data: unknown) {
	return Object.assign(new Error('Request failed'), {
		isAxiosError: true,
		response: { data, status: 400 },
	})
}

describe('getOcsErrorMessage', () => {
	it('returns the OCS message of the response', () => {
		const error = createAxiosError({ ocs: { meta: { status: 'failure', statuscode: 400, message: 'Invalid timezone.' }, data: [] } })
		expect(getOcsErrorMessage(error)).toBe('Invalid timezone.')
	})

	it('ignores empty OCS messages', () => {
		const error = createAxiosError({ ocs: { meta: { status: 'failure', statuscode: 400, message: '' }, data: [] } })
		expect(getOcsErrorMessage(error)).toBeUndefined()
	})

	it('ignores responses that are not OCS responses', () => {
		expect(getOcsErrorMessage(createAxiosError('<html></html>'))).toBeUndefined()
	})

	it('ignores other errors', () => {
		expect(getOcsErrorMessage(new Error('Network down'))).toBeUndefined()
	})
})
