/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { getBrowserTimezone } from './timezone.ts'

describe('getBrowserTimezone', () => {
	afterEach(() => {
		vi.restoreAllMocks()
	})

	/**
	 * Let the browser report the given timezone
	 *
	 * @param timeZone - The timezone reported by the browser
	 */
	function mockBrowserTimezone(timeZone: string) {
		const resolvedOptions = Intl.DateTimeFormat.prototype.resolvedOptions
		vi.spyOn(Intl.DateTimeFormat.prototype, 'resolvedOptions').mockImplementation(function(this: Intl.DateTimeFormat) {
			return { ...resolvedOptions.call(this), timeZone }
		})
	}

	it.each(['Europe/Berlin', 'Pacific/Auckland'])('returns the timezone %s of a location', (timezone) => {
		mockBrowserTimezone(timezone)
		expect(getBrowserTimezone()).toBe(timezone)
	})

	it.each(['Etc/GMT-1', 'Etc/UTC', 'UTC'])('falls back to UTC for %s', (timezone) => {
		mockBrowserTimezone(timezone)
		expect(getBrowserTimezone()).toBe('UTC')
	})
})
