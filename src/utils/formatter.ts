import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 * Format a number as a currency amount using the user's locale
 *
 * @param number - The amount to format
 * @param currency - The ISO 4217 currency code (e.g. `EUR`)
 */
export function formatCurrency(number: number, currency: string): string {
	return number.toLocaleString(getCanonicalLocale(), {
		style: 'currency',
		currencyDisplay: 'narrowSymbol',
		currencySign: 'accounting',
		currency,
	})
}
