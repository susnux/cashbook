/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Period } from './period.ts'

import { describe, expect, it, vi } from 'vitest'
import { addDays, formatPeriod, getPeriod, parseDay, periodFromQuery, periodToQuery, shiftPeriod } from './period.ts'

vi.mock('@nextcloud/l10n', () => ({
	getCanonicalLocale: () => 'en-US',
	t: (app: string, text: string, vars: Record<string, unknown>) => text.replace(/{(\w+)}/g, (_, key) => String(vars[key])),
}))

/**
 * Get the period as days for easier comparison
 *
 * @param period - The period
 */
function days(period: Period) {
	return periodToQuery(period)
}

describe('addDays', () => {
	it.each([
		['2025-01-31', 1, '2025-02-01'],
		['2025-03-01', -1, '2025-02-28'],
		['2024-03-01', -1, '2024-02-29'],
		['2024-12-31', 1, '2025-01-01'],
		// days around the start and end of daylight saving time in Europe
		['2025-03-29', 2, '2025-03-31'],
		['2025-10-27', -2, '2025-10-25'],
	])('%s + %i', (day, days, expected) => {
		expect(addDays(day, days)).toBe(expected)
	})
})

describe('getPeriod', () => {
	it.each([
		['month', { period: 'month', from: '2025-02-01', to: '2025-02-28' }],
		['quarter', { period: 'quarter', from: '2025-01-01', to: '2025-03-31' }],
		['year', { period: 'year', from: '2025-01-01', to: '2025-12-31' }],
		['custom', { period: 'custom', from: '2025-02-14', to: '2025-02-14' }],
	] as const)('%s', (type, expected) => {
		expect(days(getPeriod(type, '2025-02-14'))).toEqual(expected)
	})
})

describe('shiftPeriod', () => {
	it('moves months across years', () => {
		expect(days(shiftPeriod(getPeriod('month', '2025-01-31'), -1))).toEqual({ period: 'month', from: '2024-12-01', to: '2024-12-31' })
		expect(days(shiftPeriod(getPeriod('month', '2024-12-01'), 1))).toEqual({ period: 'month', from: '2025-01-01', to: '2025-01-31' })
	})

	it('moves quarters', () => {
		expect(days(shiftPeriod(getPeriod('quarter', '2025-01-01'), -1))).toEqual({ period: 'quarter', from: '2024-10-01', to: '2024-12-31' })
	})

	it('moves custom periods over the start of daylight saving time by their length', () => {
		const period = periodFromQuery({ period: 'custom', from: '2025-03-29', to: '2025-03-31' }, '2025-06-15')
		expect(days(shiftPeriod(period, 1))).toEqual({ period: 'custom', from: '2025-04-01', to: '2025-04-03' })
	})

	it('moves custom periods by their length', () => {
		const period = periodFromQuery({ period: 'custom', from: '2025-03-28', to: '2025-04-03' }, '2025-06-15')
		expect(days(shiftPeriod(period, 1))).toEqual({ period: 'custom', from: '2025-04-04', to: '2025-04-10' })
		expect(days(shiftPeriod(period, -1))).toEqual({ period: 'custom', from: '2025-03-21', to: '2025-03-27' })
	})
})

describe('formatPeriod', () => {
	it.each([
		['month', 'February 2025'],
		['quarter', 'Q1 2025'],
		['year', '2025'],
	] as const)('%s', (type, expected) => {
		expect(formatPeriod(getPeriod(type, '2025-02-14'))).toBe(expected)
	})

	it('formats custom periods as range of days', () => {
		const label = formatPeriod(periodFromQuery({ period: 'custom', from: '2025-02-01', to: '2025-02-14' }, '2025-06-15'))
		// ICU uses different (thin) spaces around the range separator
		expect(label.replace(/\s/g, ' ')).toBe('Feb 1 – 14, 2025')
	})
})

describe('periodFromQuery', () => {
	const today = '2025-06-15'

	it('falls back to the current month', () => {
		expect(days(periodFromQuery({}, today))).toEqual({ period: 'month', from: '2025-06-01', to: '2025-06-30' })
		expect(days(periodFromQuery({ period: 'week', from: '2025-01-01' }, today))).toEqual({ period: 'month', from: '2025-06-01', to: '2025-06-30' })
		expect(days(periodFromQuery({ period: 'month', from: 'yesterday' }, today))).toEqual({ period: 'month', from: '2025-06-01', to: '2025-06-30' })
	})

	it('normalizes the period to its type', () => {
		expect(days(periodFromQuery({ period: 'quarter', from: '2025-05-20' }, today))).toEqual({ period: 'quarter', from: '2025-04-01', to: '2025-06-30' })
	})

	it('ignores invalid custom end dates', () => {
		expect(days(periodFromQuery({ period: 'custom', from: '2025-05-20', to: '2025-05-01' }, today))).toEqual({ period: 'custom', from: '2025-05-20', to: '2025-05-20' })
	})

	it('rolls over days outside of the month', () => {
		expect(days(periodFromQuery({ period: 'custom', from: '2025-02-30', to: '2025-03-31' }, today))).toEqual({ period: 'custom', from: '2025-03-02', to: '2025-03-31' })
	})

	it('round trips with periodToQuery', () => {
		const query = { period: 'custom', from: '2025-02-01', to: '2025-03-15' }
		expect(periodToQuery(periodFromQuery(query, today))).toEqual(query)
	})
})

describe('parseDay', () => {
	it('parses days as local midnight', () => {
		expect(parseDay('2025-02-14')).toEqual(new Date(2025, 1, 14))
	})

	it.each([undefined, null, '', '2025-2-14', ['2025-02-14']])('rejects %s', (value) => {
		expect(parseDay(value)).toBeUndefined()
	})
})
