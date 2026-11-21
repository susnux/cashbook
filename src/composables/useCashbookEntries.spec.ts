/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbook } from '../models/Cashbook.ts'

import { flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { getPeriod } from '../utils/period.ts'
import { useCashbookEntries } from './useCashbookEntries.ts'

const fetchEntriesByDate = vi.hoisted(() => vi.fn())
vi.mock('../api/cashbooks.ts', () => ({ fetchEntriesByDate }))

const cashbookA: ICashbook = { id: '1', name: 'A', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 0, balance: '0.00' }
const cashbookB: ICashbook = { id: '2', name: 'B', account: '', currency: 'EUR', owner: 'alice', timezone: 'Europe/Berlin', lastSequence: 0, balance: '0.00' }

/**
 * Create a promise that can be resolved from the outside
 */
function deferred<T>() {
	let resolve!: (value: T) => void
	const promise = new Promise<T>((res) => {
		resolve = res
	})
	return { promise, resolve }
}

describe('useCashbookEntries', () => {
	beforeEach(() => {
		fetchEntriesByDate.mockReset()
	})

	it('loads the entries of the period', async () => {
		fetchEntriesByDate.mockResolvedValue([{ id: 1 }])
		const period = getPeriod('month', '2025-01-01')

		const scope = effectScope()
		const { entries, loading } = scope.run(() => useCashbookEntries(cashbookA, period))!
		expect(loading.value).toBe(true)
		await flushPromises()

		expect(fetchEntriesByDate).toHaveBeenCalledWith(cashbookA, expect.objectContaining({ begin: period.begin, end: period.end }))
		expect(entries.value).toEqual([{ id: 1 }])
		expect(loading.value).toBe(false)
		scope.stop()
	})

	it('provides the entries of the days within the period in the timezone of the cashbook', async () => {
		// a day opened on January 31st and closed on February 1st in Berlin
		const opening = { id: '1', date: new Date('2025-01-31T19:00:00Z'), balance: '100.00' }
		const january = { id: '2', date: new Date('2025-01-31T22:30:00Z'), balance: '120.00' }
		const february = { id: '3', date: new Date('2025-01-31T23:30:00Z'), balance: '115.00' }
		const closing = { id: '4', date: new Date('2025-02-01T01:00:00Z'), balance: '115.00' }
		fetchEntriesByDate.mockResolvedValue([opening, january, february, closing])
		const period = ref(getPeriod('month', '2025-02-01'))

		const scope = effectScope()
		const { entries, periodEntries, balanceBeforePeriod } = scope.run(() => useCashbookEntries(cashbookA, period))!
		await flushPromises()
		expect(entries.value).toEqual([opening, january, february, closing])
		expect(periodEntries.value).toEqual([february, closing])
		expect(balanceBeforePeriod.value).toBe('120.00')

		period.value = getPeriod('month', '2025-01-01')
		await flushPromises()
		expect(periodEntries.value).toEqual([opening, january])
		expect(balanceBeforePeriod.value).toBeUndefined()
		scope.stop()
	})

	it('loads the entries again when the cashbook gets new entries', async () => {
		fetchEntriesByDate.mockResolvedValueOnce([]).mockResolvedValueOnce([{ id: '1' }])
		const cashbook = ref(cashbookA)

		const scope = effectScope()
		const { entries } = scope.run(() => useCashbookEntries(cashbook, getPeriod('month', '2025-06-15')))!
		await flushPromises()
		cashbook.value = { ...cashbookA, name: 'Renamed' }
		await flushPromises()
		expect(fetchEntriesByDate).toHaveBeenCalledTimes(1)

		cashbook.value = { ...cashbookA, lastSequence: 1, balance: '5.00' }
		await flushPromises()
		expect(fetchEntriesByDate).toHaveBeenCalledTimes(2)
		expect(entries.value).toEqual([{ id: '1' }])
		scope.stop()
	})

	it('ignores responses of previous selections', async () => {
		const first = deferred<unknown[]>()
		const second = deferred<unknown[]>()
		fetchEntriesByDate.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
		const cashbook = ref(cashbookA)

		const scope = effectScope()
		const { entries } = scope.run(() => useCashbookEntries(cashbook, getPeriod('month', '2025-06-15')))!
		cashbook.value = cashbookB
		await nextTick()

		expect(fetchEntriesByDate.mock.calls[0]![1].signal.aborted).toBe(true)
		second.resolve([{ id: 2 }])
		await flushPromises()
		first.resolve([{ id: 1 }])
		await flushPromises()

		expect(entries.value).toEqual([{ id: 2 }])
		scope.stop()
	})

	it('reports failures and can reload', async () => {
		fetchEntriesByDate.mockRejectedValueOnce(new Error('Network error')).mockResolvedValueOnce([])

		const scope = effectScope()
		const { failed, loading, reload } = scope.run(() => useCashbookEntries(cashbookA, getPeriod('month', '2025-06-15')))!
		await flushPromises()
		expect(failed.value).toBe(true)
		expect(loading.value).toBe(false)

		reload()
		await flushPromises()
		expect(failed.value).toBe(false)
		expect(fetchEntriesByDate).toHaveBeenCalledTimes(2)
		scope.stop()
	})

	it('provides the error message of the server', async () => {
		const error = Object.assign(new Error('Request failed'), {
			isAxiosError: true,
			response: { status: 400, data: { ocs: { meta: { status: 'failure', statuscode: 400, message: 'Too many entries' }, data: [] } } },
		})
		fetchEntriesByDate.mockRejectedValueOnce(error).mockResolvedValueOnce([])

		const scope = effectScope()
		const { errorMessage, reload } = scope.run(() => useCashbookEntries(cashbookA, getPeriod('month', '2025-06-15')))!
		await flushPromises()
		expect(errorMessage.value).toBe('Too many entries')

		reload()
		await flushPromises()
		expect(errorMessage.value).toBeUndefined()
		scope.stop()
	})
})
