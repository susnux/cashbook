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
