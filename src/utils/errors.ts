/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { OCSResponse } from '@nextcloud/typings/ocs'

import { isAxiosError } from '@nextcloud/axios'

/**
 * Get the error message an OCS API returned for a failed request
 *
 * @param error - The error thrown by the request
 * @return The message of the server or `undefined` if there is none
 */
export function getOcsErrorMessage(error: unknown): string | undefined {
	if (!isAxiosError<Partial<OCSResponse>>(error)) {
		return undefined
	}
	return error.response?.data?.ocs?.meta?.message || undefined
}
