/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ITimezone } from '@nextcloud/vue/components/NcTimezonePicker'

/** The timezone pickers only offer timezones of locations, UTC is added as the only other timezone the server supports */
export const UTC_TIMEZONE: ITimezone = { timezoneId: 'UTC', label: 'UTC', continent: '' }

/**
 * Get the timezone of the browser if it is offered by the timezone pickers, otherwise UTC.
 * Browsers can report fixed offsets like `Etc/GMT-1`, which have no daylight saving time rules.
 *
 * @return The IANA timezone ID
 */
export function getBrowserTimezone(): string {
	const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone
	return !timezone.startsWith('Etc/') && Intl.supportedValuesOf('timeZone').includes(timezone)
		? timezone
		: UTC_TIMEZONE.timezoneId
}

/**
 * Get the ID the browser uses for a timezone, which is the ID the timezone pickers offer.
 * Browsers use legacy names for some timezones, e.g. `Asia/Calcutta` for the canonical ID `Asia/Kolkata` the server stores.
 *
 * @param timezone - The IANA timezone ID
 */
export function toBrowserTimezoneId(timezone: string): string {
	return new Intl.DateTimeFormat('en-US', { timeZone: timezone }).resolvedOptions().timeZone
}

/**
 * Get the calendar day of a date in a timezone
 *
 * @param date - The date
 * @param timeZone - The IANA timezone ID, the timezone of the browser if not set
 * @return The day as `YYYY-MM-DD`
 */
export function getDay(date: Date, timeZone?: string): string {
	const parts = new Intl.DateTimeFormat('en-US', {
		timeZone,
		calendar: 'gregory',
		numberingSystem: 'latn',
		year: 'numeric',
		month: '2-digit',
		day: '2-digit',
	}).formatToParts(date)
	const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((entry) => entry.type === type)!.value
	return `${part('year').padStart(4, '0')}-${part('month')}-${part('day')}`
}

/**
 * Get the current calendar day in a timezone
 *
 * @param timeZone - The IANA timezone ID, the timezone of the browser if not set
 * @return The day as `YYYY-MM-DD`
 */
export function getToday(timeZone?: string): string {
	return getDay(new Date(), timeZone)
}
