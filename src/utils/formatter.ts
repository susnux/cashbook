/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * Format an amount of money using the user's locale
 *
 * @param amount - The amount to format, decimal strings are formatted without losing precision
 * @param currency - The ISO 4217 currency code (e.g. `EUR`)
 */
export function formatCurrency(amount: number | string, currency: string): string {
	return new Intl.NumberFormat(getCanonicalLocale(), {
		style: 'currency',
		currencyDisplay: 'narrowSymbol',
		currencySign: 'accounting',
		currency,
	}).format(amount as number | Intl.StringNumericLiteral)
}

/**
 * Format a tax rate as percentage using the user's locale
 *
 * @param rate - The tax rate (e.g. `0.19` for 19%)
 */
export function formatTaxRate(rate: number): string {
	return rate.toLocaleString(getCanonicalLocale(), { style: 'percent', maximumFractionDigits: 2 })
}
