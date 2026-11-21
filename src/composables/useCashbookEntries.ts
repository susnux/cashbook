/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { MaybeRefOrGetter } from 'vue'
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'
import type { Period } from '../utils/period.ts'

import { isCancel } from '@nextcloud/axios'
import { computed, ref, toValue, watch } from 'vue'
import { fetchEntriesByDate } from '../api/cashbooks.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'
import { isInPeriod } from '../utils/period.ts'
import { getDay } from '../utils/timezone.ts'

/**
 * Load the entries of a cashbook within a period.
 *
 * If the period starts or ends within an open day, `entries` contains the whole day,
 * while `periodEntries` only contains the entries of the days within the period
 * and `balanceBeforePeriod` is the balance after the last loaded entry before the period.
 * The entries are loaded again when the cashbook gets new entries (its `lastSequence` changes).
 * Changing the cashbook or period aborts pending requests, so only entries of the current selection are shown.
 *
 * @param cashbook - The cashbook to load the entries of
 * @param period - The period to load
 */
export function useCashbookEntries(cashbook: MaybeRefOrGetter<ICashbook | undefined>, period: MaybeRefOrGetter<Period>) {
	const entries = ref<ICashbookEntry[]>([])
	const loading = ref(false)
	const failed = ref(false)
	/** The error message of the server if loading failed */
	const errorMessage = ref<string>()
	const reloadCounter = ref(0)

	const periodEntries = computed(() => {
		const timezone = toValue(cashbook)?.timezone
		const currentPeriod = toValue(period)
		return entries.value.filter((entry) => isInPeriod(getDay(entry.date, timezone), currentPeriod))
	})

	const balanceBeforePeriod = computed(() => {
		const timezone = toValue(cashbook)?.timezone
		const { begin } = toValue(period)
		return entries.value.findLast((entry) => getDay(entry.date, timezone) < begin)?.balance
	})

	watch(
		[
			() => toValue(cashbook)?.id,
			() => toValue(cashbook)?.lastSequence,
			() => toValue(period).begin,
			() => toValue(period).end,
			reloadCounter,
		],
		async (_, __, onCleanup) => {
			entries.value = []
			failed.value = false
			errorMessage.value = undefined
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
				errorMessage.value = getOcsErrorMessage(error)
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
		periodEntries,
		balanceBeforePeriod,
		loading,
		failed,
		errorMessage,
		reload,
	}
}
