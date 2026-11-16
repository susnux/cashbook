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

const cashbookA: ICashbook = { id: 1, name: 'A', account: '', currency: 'EUR', owner: 'alice' }
const cashbookB: ICashbook = { id: 2, name: 'B', account: '', currency: 'EUR', owner: 'alice' }

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
		const period = getPeriod('month', new Date(2025, 0, 1))

		const scope = effectScope()
		const { entries, loading } = scope.run(() => useCashbookEntries(cashbookA, period))!
		expect(loading.value).toBe(true)
		await flushPromises()

		expect(fetchEntriesByDate).toHaveBeenCalledWith(cashbookA, expect.objectContaining({ begin: period.begin, end: period.end }))
		expect(entries.value).toEqual([{ id: 1 }])
		expect(loading.value).toBe(false)
		scope.stop()
	})

	it('ignores responses of previous selections', async () => {
		const first = deferred<unknown[]>()
		const second = deferred<unknown[]>()
		fetchEntriesByDate.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)
		const cashbook = ref(cashbookA)

		const scope = effectScope()
		const { entries } = scope.run(() => useCashbookEntries(cashbook, getPeriod('month', new Date())))!
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
		const { failed, loading, reload } = scope.run(() => useCashbookEntries(cashbookA, getPeriod('month', new Date())))!
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
		const { errorMessage, reload } = scope.run(() => useCashbookEntries(cashbookA, getPeriod('month', new Date())))!
		await flushPromises()
		expect(errorMessage.value).toBe('Too many entries')

		reload()
		await flushPromises()
		expect(errorMessage.value).toBeUndefined()
		scope.stop()
	})
})
