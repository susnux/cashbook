/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MaybeRefOrGetter } from 'vue'
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'
import type { Period } from '../utils/period.ts'

import { isCancel } from '@nextcloud/axios'
import { ref, toValue, watch } from 'vue'
import { fetchEntriesByDate } from '../api/cashbooks.ts'
import logger from '../utils/logger.ts'

/**
 * Load the entries of a cashbook within a period.
 *
 * Changing the cashbook or period aborts pending requests, so only entries of the current selection are shown.
 *
 * @param cashbook - The cashbook to load the entries of
 * @param period - The period to load
 */
export function useCashbookEntries(cashbook: MaybeRefOrGetter<ICashbook | undefined>, period: MaybeRefOrGetter<Period>) {
	const entries = ref<ICashbookEntry[]>([])
	const loading = ref(false)
	const failed = ref(false)
	const reloadCounter = ref(0)

	watch(
		() => [toValue(cashbook)?.id, toValue(period).begin.getTime(), toValue(period).end.getTime(), reloadCounter.value],
		async (_, __, onCleanup) => {
			entries.value = []
			failed.value = false
			const currentCashbook = toValue(cashbook)
			if (!currentCashbook) {
				loading.value = false
				return
			}

			const controller = new AbortController()
			onCleanup(() => controller.abort())
			loading.value = true
			try {
				const { begin, end } = toValue(period)
				const result = await fetchEntriesByDate(currentCashbook, { begin, end, signal: controller.signal })
				if (!controller.signal.aborted) {
					entries.value = result
					loading.value = false
				}
			} catch (error) {
				if (isCancel(error) || controller.signal.aborted) {
					return
				}
				logger.error('Failed to fetch cashbook entries', { error })
				failed.value = true
				loading.value = false
			}
		},
		{ immediate: true },
	)

	/**
	 * Load the entries again
	 */
	function reload() {
		reloadCounter.value++
	}

	return {
		entries,
		loading,
		failed,
		reload,
	}
}
