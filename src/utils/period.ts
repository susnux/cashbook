/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { LocationQuery } from 'vue-router'

import { getCanonicalLocale, t } from '@nextcloud/l10n'

export type PeriodType = 'month' | 'quarter' | 'year' | 'custom'

export interface Period {
	type: PeriodType
	/** First day of the period (local midnight) */
	begin: Date
	/** First day after the period (local midnight, exclusive) */
	end: Date
}

const PERIOD_TYPES: readonly PeriodType[] = ['month', 'quarter', 'year', 'custom']

/**
 * Get the period of the given type that contains the given date
 *
 * @param type - The type of the period, a custom period is a single day
 * @param date - A date within the period
 */
export function getPeriod(type: PeriodType, date: Date): Period {
	const year = date.getFullYear()
	switch (type) {
		case 'year':
			return { type, begin: new Date(year, 0, 1), end: new Date(year + 1, 0, 1) }
		case 'quarter': {
			const month = date.getMonth() - date.getMonth() % 3
			return { type, begin: new Date(year, month, 1), end: new Date(year, month + 3, 1) }
		}
		case 'month':
			return { type, begin: new Date(year, date.getMonth(), 1), end: new Date(year, date.getMonth() + 1, 1) }
		case 'custom':
			return { type, begin: new Date(year, date.getMonth(), date.getDate()), end: new Date(year, date.getMonth(), date.getDate() + 1) }
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
		const days = Math.round((period.end.getTime() - period.begin.getTime()) / 86400000)
		const begin = new Date(period.begin.getFullYear(), period.begin.getMonth(), period.begin.getDate() + direction * days)
		const end = new Date(begin.getFullYear(), begin.getMonth(), begin.getDate() + days)
		return { type: 'custom', begin, end }
	}

	const reference = direction === 1
		? period.end
		: new Date(period.begin.getFullYear(), period.begin.getMonth(), period.begin.getDate() - 1)
	return getPeriod(period.type, reference)
}

/**
 * Get a human readable label of the period
 *
 * @param period - The period
 * @param locale - The locale to format dates in
 */
export function formatPeriod(period: Period, locale: string = getCanonicalLocale()): string {
	switch (period.type) {
		case 'year':
			return String(period.begin.getFullYear())
		case 'quarter':
			return t('cashbook', 'Q{quarter} {year}', {
				quarter: Math.floor(period.begin.getMonth() / 3) + 1,
				year: period.begin.getFullYear(),
			})
		case 'month':
			return period.begin.toLocaleDateString(locale, { month: 'long', year: 'numeric' })
		case 'custom': {
			const lastDay = new Date(period.end.getFullYear(), period.end.getMonth(), period.end.getDate() - 1)
			return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).formatRange(period.begin, lastDay)
		}
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
 * Serialize a period as route query (`to` is the last day of the period)
 *
 * @param period - The period to serialize
 */
export function periodToQuery(period: Period): Record<string, string> {
	const lastDay = new Date(period.end.getFullYear(), period.end.getMonth(), period.end.getDate() - 1)
	return { period: period.type, from: formatDay(period.begin), to: formatDay(lastDay) }
}

/**
 * Get the period from the route query, falling back to the current month
 *
 * @param query - The route query
 * @param now - The current date
 */
export function periodFromQuery(query: LocationQuery, now: Date = new Date()): Period {
	const type = PERIOD_TYPES.find((value) => value === query.period)
	const from = parseDay(query.from)
	if (!type || !from) {
		return getPeriod('month', now)
	}
	if (type !== 'custom') {
		return getPeriod(type, from)
	}

	const to = parseDay(query.to)
	if (!to || to < from) {
		return getPeriod('custom', from)
	}
	return { type, begin: from, end: new Date(to.getFullYear(), to.getMonth(), to.getDate() + 1) }
}
