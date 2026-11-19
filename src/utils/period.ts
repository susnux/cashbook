/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LocationQuery } from 'vue-router'

import { getCanonicalLocale, t } from '@nextcloud/l10n'

export type PeriodType = 'month' | 'quarter' | 'year' | 'custom'

/**
 * A range of calendar days, independent of any timezone.
 * The server resolves the days in the timezone of the cashbook.
 */
export interface Period {
	type: PeriodType
	/** First day of the period (`YYYY-MM-DD`) */
	begin: string
	/** First day after the period (`YYYY-MM-DD`, exclusive) */
	end: string
}

const PERIOD_TYPES: readonly PeriodType[] = ['month', 'quarter', 'year', 'custom']

/**
 * Get the day of a date in UTC, the calendar arithmetic of days is done in UTC to be free of daylight saving time.
 *
 * @param year - The full year
 * @param month - The month (0-based), overflowing values roll over into the next or previous year
 * @param day - The day of the month, overflowing values roll over into the next or previous month
 */
function utcDay(year: number, month: number, day: number): string {
	const date = new Date(0)
	date.setUTCFullYear(year, month, day)
	return date.toISOString().slice(0, 10)
}

/**
 * Get a day as UTC midnight
 *
 * @param day - The day (`YYYY-MM-DD`)
 */
function toUtcDate(day: string): Date {
	const [year, month, date] = day.split('-').map(Number) as [number, number, number]
	const result = new Date(0)
	result.setUTCFullYear(year, month - 1, date)
	return result
}

/**
 * Add a number of days to a day
 *
 * @param day - The day (`YYYY-MM-DD`)
 * @param days - The number of days to add, negative to subtract
 */
export function addDays(day: string, days: number): string {
	const date = toUtcDate(day)
	return utcDay(date.getUTCFullYear(), date.getUTCMonth(), date.getUTCDate() + days)
}

/**
 * Get the period of the given type that contains the given day
 *
 * @param type - The type of the period, a custom period is a single day
 * @param day - A day within the period (`YYYY-MM-DD`)
 */
export function getPeriod(type: PeriodType, day: string): Period {
	const date = toUtcDate(day)
	const year = date.getUTCFullYear()
	const month = date.getUTCMonth()
	switch (type) {
		case 'year':
			return { type, begin: utcDay(year, 0, 1), end: utcDay(year + 1, 0, 1) }
		case 'quarter': {
			const first = month - month % 3
			return { type, begin: utcDay(year, first, 1), end: utcDay(year, first + 3, 1) }
		}
		case 'month':
			return { type, begin: utcDay(year, month, 1), end: utcDay(year, month + 1, 1) }
		case 'custom':
			return { type, begin: day, end: addDays(day, 1) }
	}
}

/**
 * Get the previous or next period of the same type
 *
 * Custom periods are moved by their length.
 *
 * @param period - The current period
 * @param direction - `-1` for the previous and `1` for the next period
 */
export function shiftPeriod(period: Period, direction: -1 | 1): Period {
	if (period.type === 'custom') {
		const days = Math.round((toUtcDate(period.end).getTime() - toUtcDate(period.begin).getTime()) / 86400000)
		const begin = addDays(period.begin, direction * days)
		return { type: 'custom', begin, end: addDays(begin, days) }
	}

	return getPeriod(period.type, direction === 1 ? period.end : addDays(period.begin, -1))
}

/**
 * Get a human readable label of the period
 *
 * @param period - The period
 * @param locale - The locale to format dates in
 */
export function formatPeriod(period: Period, locale: string = getCanonicalLocale()): string {
	const begin = toUtcDate(period.begin)
	switch (period.type) {
		case 'year':
			return String(begin.getUTCFullYear())
		case 'quarter':
			return t('cashbook', 'Q{quarter} {year}', {
				quarter: Math.floor(begin.getUTCMonth() / 3) + 1,
				year: begin.getUTCFullYear(),
			})
		case 'month':
			return begin.toLocaleDateString(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' })
		case 'custom':
			return new Intl.DateTimeFormat(locale, { dateStyle: 'medium', timeZone: 'UTC' })
				.formatRange(begin, toUtcDate(addDays(period.end, -1)))
	}
}

/**
 * Format a date as `YYYY-MM-DD` in local time
 *
 * @param date - The date to format
 */
export function formatDay(date: Date): string {
	return [
		date.getFullYear(),
		String(date.getMonth() + 1).padStart(2, '0'),
		String(date.getDate()).padStart(2, '0'),
	].join('-')
}

/**
 * Parse a `YYYY-MM-DD` date as local midnight
 *
 * @param value - The value to parse
 */
export function parseDay(value: unknown): Date | undefined {
	const match = typeof value === 'string' ? value.match(/^(\d{4})-(\d{2})-(\d{2})$/) : null
	if (!match) {
		return undefined
	}
	const date = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
	return Number.isNaN(date.getTime()) ? undefined : date
}

/**
 * Validate a `YYYY-MM-DD` day, days outside of the month roll over (e.g. `2025-02-30` is `2025-03-02`)
 *
 * @param value - The value to validate
 */
function normalizeDay(value: unknown): string | undefined {
	return typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value) ? addDays(value, 0) : undefined
}

/**
 * Serialize a period as route query (`to` is the last day of the period)
 *
 * @param period - The period to serialize
 */
export function periodToQuery(period: Period): Record<string, string> {
	return { period: period.type, from: period.begin, to: addDays(period.end, -1) }
}

/**
 * Get the period from the route query, falling back to the current month
 *
 * @param query - The route query
 * @param today - The current day (`YYYY-MM-DD`)
 */
export function periodFromQuery(query: LocationQuery, today: string): Period {
	const type = PERIOD_TYPES.find((value) => value === query.period)
	const from = normalizeDay(query.from)
	if (!type || !from) {
		return getPeriod('month', today)
	}
	if (type !== 'custom') {
		return getPeriod(type, from)
	}

	const to = normalizeDay(query.to)
	if (!to || to < from) {
		return getPeriod('custom', from)
	}
	return { type, begin: from, end: addDays(to, 1) }
}
