/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { afterEach, describe, expect, it, vi } from 'vitest'
import { getBrowserTimezone, getDay, getToday } from './timezone.ts'

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

describe('getDay', () => {
	it.each([
		// midnight in Auckland (UTC+13 in January) is 11:00 UTC of the previous day
		['2025-01-01T10:59:00Z', 'Pacific/Auckland', '2025-01-01'],
		['2025-01-01T11:00:00Z', 'Pacific/Auckland', '2025-01-02'],
		['2025-01-01T11:00:00Z', 'UTC', '2025-01-01'],
		['2025-01-01T07:59:00Z', 'America/Los_Angeles', '2024-12-31'],
		// legacy name some browsers report
		['2025-07-01T21:00:00Z', 'Europe/Kiev', '2025-07-02'],
	])('%s in %s', (date, timeZone, expected) => {
		expect(getDay(new Date(date), timeZone)).toBe(expected)
	})

	it('uses the timezone of the browser by default', () => {
		const date = new Date('2025-01-01T11:00:00Z')
		expect(getDay(date)).toBe(getDay(date, Intl.DateTimeFormat().resolvedOptions().timeZone))
	})
})

describe('getToday', () => {
	afterEach(() => {
		vi.useRealTimers()
	})

	it('returns the current day in the timezone', () => {
		vi.useFakeTimers({ now: new Date('2025-06-30T22:30:00Z') })

		expect(getToday('UTC')).toBe('2025-06-30')
		expect(getToday('Europe/Berlin')).toBe('2025-07-01')
	})
})
