/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { formatCurrency } from './formatter.ts'

vi.mock('@nextcloud/l10n', () => ({ getCanonicalLocale: () => 'en-US' }))

describe('formatCurrency', () => {
	it('formats positive amounts', () => {
		expect(formatCurrency(1234.5, 'EUR')).toBe('€1,234.50')
	})

	it('formats negative amounts in accounting style', () => {
		expect(formatCurrency(-12, 'USD')).toBe('($12.00)')
	})
	it('formats decimal strings without losing precision', () => {
		expect(formatCurrency('9007199254740993.10', 'EUR')).toBe('€9,007,199,254,740,993.10')
	})
})
