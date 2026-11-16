/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { getCurrencyOptions, getCurrencyStep } from './currencies.ts'

vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-US' }))

describe('getCurrencyOptions', () => {
	it('contains all supported currencies with localized names', () => {
		const options = getCurrencyOptions()
		expect(options).toHaveLength(Intl.supportedValuesOf('currency').length)
		expect(options).toContainEqual({ value: 'EUR', label: 'Euro (EUR)' })
		expect(options).toContainEqual({ value: 'JPY', label: 'Japanese Yen (JPY)' })
	})

	it('uses the given locale', () => {
		expect(getCurrencyOptions('de-DE')).toContainEqual({ value: 'USD', label: 'US-Dollar (USD)' })
	})

	it('is sorted by label', () => {
		const labels = getCurrencyOptions().map(({ label }) => label)
		expect(labels).toEqual([...labels].sort((a, b) => a.localeCompare(b, 'en-US')))
	})
})

describe('getCurrencyStep', () => {
	it.each([
		['EUR', '0.01'],
		['JPY', '1'],
		['BHD', '0.001'],
	])('uses the minor unit of %s', (currency, step) => {
		expect(getCurrencyStep(currency)).toBe(step)
	})
})
