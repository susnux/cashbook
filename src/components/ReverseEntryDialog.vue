<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { t } from '@nextcloud/l10n'
import { useFormatTime } from '@nextcloud/vue/composables/useFormatDateTime'
import { computed, ref, useTemplateRef } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { CashbookConflictError, reverseEntry } from '../api/cashbooks.ts'
import { EntryReasonReadable, isExpenseReason } from '../models/CashbookEntry.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { formatCurrency, formatTaxRate } from '../utils/formatter.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	/** The cashbook of the entry */
	cashbook: ICashbook
	/** The income or expense entry to reverse */
	entry: ICashbookEntry
}>()

const emit = defineEmits<{
	(e: 'close', reversal?: ICashbookEntry): void
	/** The cashbook was changed in the meantime, emitted with its current state */
	(e: 'conflict', cashbook: ICashbook): void
}>()

const text = ref('')
const reference = ref('')

/** The cashbook the reversal is added after, updated if it was changed in the meantime */
const currentCashbook = ref(props.cashbook)

const formattedDate = useFormatTime(() => props.entry.date, () => ({ format: { dateStyle: 'medium', timeStyle: 'short', timeZone: props.cashbook.timezone } }))
/** The amount as income or expense by the reason of the entry, negative amounts are shown as such */
const formattedAmount = computed(() => {
	const { entry } = props
	const signed = 'debit' in entry ? entry.debit : ('credit' in entry ? negate(entry.credit) : '0')
	if (isExpenseReason(entry.reason)) {
		return t('cashbook', 'Expense: {amount}', { amount: formatCurrency(negate(signed), props.cashbook.currency) })
	}
	return t('cashbook', 'Income: {amount}', { amount: formatCurrency(signed, props.cashbook.currency) })
})

const fieldsElement = useTemplateRef<HTMLDivElement>('fields')
const saved = ref<ICashbookEntry>()
const error = ref('')
const conflict = ref(false)
const loading = ref(false)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Keep entry'),
		// the reversal can not be undone, so the dialog has to show its result
		disabled: loading.value,
	},
	{
		type: 'submit',
		variant: 'error',
		label: t('cashbook', 'Reverse entry'),
		disabled: loading.value,
		callback: onReverse,
	},
])

/**
 * Negate a decimal string
 *
 * @param amount - The decimal string
 */
function negate(amount: string): string {
	return amount.startsWith('-') ? amount.slice(1) : `-${amount}`
}

/**
 * Reverse the entry, keep the dialog open on failure.
 */
async function onReverse() {
	// the dialog only validates the form after running this callback
	if (fieldsElement.value?.closest('form')?.reportValidity() === false) {
		return false
	}

	error.value = ''
	conflict.value = false
	loading.value = true
	try {
		saved.value = await reverseEntry(currentCashbook.value, props.entry, {
			text: text.value,
			reference: reference.value,
		})
	} catch (e) {
		if (e instanceof CashbookConflictError) {
			logger.info('Cashbook was changed while reversing an entry', { cashbook: e.cashbook })
			currentCashbook.value = e.cashbook
			conflict.value = true
			emit('conflict', e.cashbook)
			return false
		}
		logger.error('Failed to reverse cashbook entry', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('cashbook', 'Could not reverse the entry.')
		return false
	} finally {
		loading.value = false
	}
}

/**
 * @param open - The new open state of the dialog
 */
function onUpdateOpen(open: boolean) {
	if (!open) {
		emit('close', saved.value)
	}
}
</script>

<template>
	<NcDialog
		isForm
		:noClose="loading"
		:buttons="buttons"
		:name="t('cashbook', 'Reverse entry')"
		@update:open="onUpdateOpen">
		<div ref="fields" :class="$style.fields">
			<dl :class="$style.entry">
				<dt>{{ t('cashbook', 'Entry') }}</dt>
				<dd>#{{ entry.sequence }}</dd>
				<dt>{{ t('cashbook', 'Date') }}</dt>
				<dd>{{ formattedDate }}</dd>
				<dt>{{ t('cashbook', 'Reason') }}</dt>
				<dd>{{ entry.reason ? EntryReasonReadable[entry.reason] : '' }}</dd>
				<dt>{{ t('cashbook', 'Amount') }}</dt>
				<dd>{{ formattedAmount }}</dd>
				<template v-if="entry.tax !== null">
					<dt>{{ t('cashbook', 'Tax rate') }}</dt>
					<dd>{{ formatTaxRate(entry.tax) }}</dd>
				</template>
				<template v-if="entry.text">
					<dt>{{ t('cashbook', 'Text') }}</dt>
					<dd>{{ entry.text }}</dd>
				</template>
			</dl>
			<NcNoteCard type="info" :text="t('cashbook', 'The entry stays in the cashbook. A reversal with the inverted amount is added. This cannot be undone.')" />
			<NcTextField
				v-model="text"
				:class="$style.fullWidth"
				required
				:maxlength="1000"
				:label="t('cashbook', 'Reason for the reversal')" />
			<NcTextField
				v-model="reference"
				:class="$style.fullWidth"
				:maxlength="127"
				:label="t('cashbook', 'Receipt number')" />
			<NcNoteCard
				v-if="conflict"
				type="warning"
				:text="t('cashbook', 'The cashbook was changed in the meantime. Check the entries and reverse the entry again.')" />
			<NcNoteCard v-if="error" type="error" :text="error" />
		</div>
	</NcDialog>
</template>

<style lang="css" module>
.fields {
	display: contents;

	> * {
		margin-block: var(--default-grid-baseline);
	}
}

.entry {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: var(--default-grid-baseline) calc(3 * var(--default-grid-baseline));

	dt {
		font-weight: bold;
	}

	dd {
		overflow-wrap: anywhere;
	}
}

.fullWidth {
	width: 100%;
}
</style>
