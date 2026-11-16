/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

export interface CurrencyOption {
	/** The ISO 4217 currency code */
	value: string
	/** The localized name of the currency including the code */
	label: string
}

/**
 * Get all currencies supported by the browser as select options sorted by their localized name
 *
 * @param locale - The locale to use for the names
 */
export function getCurrencyOptions(locale: string = getCanonicalLocale()): CurrencyOption[] {
	const names = new Intl.DisplayNames([locale], { type: 'currency' })
	return Intl.supportedValuesOf('currency')
		.map((code) => ({ value: code, label: `${names.of(code) ?? code} (${code})` }))
		.sort((a, b) => a.label.localeCompare(b.label, locale))
}
