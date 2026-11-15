import { getCanonicalLocale } from '@nextcloud/l10n'

/**
 *
 * @param number
 * @param currency
 */
export function formatCurrency(number: number, currency: string): string {
	return number.toLocaleString(getCanonicalLocale(), {
		style: 'currency',
		currencyDisplay: 'narrowSymbol',
		currencySign: 'accounting',
		currency,
	})
}
