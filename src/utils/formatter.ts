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
