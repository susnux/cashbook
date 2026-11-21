<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, useTemplateRef, watch } from 'vue'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcRadioGroup from '@nextcloud/vue/components/NcRadioGroup'
import NcRadioGroupButton from '@nextcloud/vue/components/NcRadioGroupButton'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { addEntry, CashbookConflictError } from '../api/cashbooks.ts'
import { EntryReason, EntryReasonReadable, ExpenseReasons, IncomeReasons } from '../models/CashbookEntry.ts'
import { getCurrencyStep } from '../utils/currencies.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import { formatCurrency } from '../utils/formatter.ts'
import logger from '../utils/logger.ts'
import { formatDay, parseDay } from '../utils/period.ts'
import { getToday } from '../utils/timezone.ts'

interface ReasonOption {
	value: EntryReason
	label: string
}

const props = defineProps<{
	/** The cashbook to add the entry to */
	cashbook: ICashbook
}>()

const emit = defineEmits<{
	(e: 'close', entry?: ICashbookEntry): void
	/** The cashbook was changed in the meantime, emitted with its current state */
	(e: 'conflict', cashbook: ICashbook): void
}>()

const type = ref<'income' | 'expense'>('income')
const reason = ref<EntryReason>(EntryReason.IncomeSales)
const amount = ref('')
const taxPercent = ref('')
const text = ref('')
const reference = ref('')
const receiptDate = ref<Date | null>(null)

const reasonOptions = computed<ReasonOption[]>(() => (type.value === 'income' ? IncomeReasons : ExpenseReasons)
	.map((value) => ({ value, label: EntryReasonReadable[value] })))
const selectedReason = computed({
	get: () => reasonOptions.value.find(({ value }) => value === reason.value),
	set: (option: ReasonOption | null | undefined) => {
		if (option) {
			reason.value = option.value
		}
	},
})
watch(type, () => {
	reason.value = reasonOptions.value[0]!.value
})

/** The cashbook the entry is added after, updated if it was changed in the meantime */
const currentCashbook = ref(props.cashbook)
const formattedBalance = computed(() => formatCurrency(currentCashbook.value.balance, currentCashbook.value.currency))

const amountStep = computed(() => getCurrencyStep(props.cashbook.currency))
/** The current day of the cashbook as local date for the date picker */
const today = parseDay(getToday(props.cashbook.timezone))

const fieldsElement = useTemplateRef<HTMLDivElement>('fields')
const saved = ref<ICashbookEntry>()
const error = ref('')
const conflict = ref(false)
const loading = ref(false)

watch([type, reason, amount, taxPercent, text, reference, receiptDate], () => {
	error.value = ''
})

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Cancel'),
		// the entry can not be deleted, so the dialog has to show its result
		disabled: loading.value,
	},
	{
		type: 'submit',
		variant: 'primary',
		label: t('cashbook', 'Add entry'),
		disabled: loading.value,
		callback: onAddEntry,
	},
])

/**
 * Add the entry, keep the dialog open on failure.
 */
async function onAddEntry() {
	// the dialog only validates the form after running this callback
	if (fieldsElement.value?.closest('form')?.reportValidity() === false) {
		return false
	}

	error.value = ''
	conflict.value = false
	loading.value = true
	try {
		saved.value = await addEntry(currentCashbook.value, {
			reason: reason.value,
			amount: amount.value,
			text: text.value,
			tax: taxPercent.value === '' ? null : Number(taxPercent.value) / 100,
			reference: reference.value,
			referenceDate: receiptDate.value ? formatDay(receiptDate.value) : null,
		})
	} catch (e) {
		if (e instanceof CashbookConflictError) {
			logger.info('Cashbook was changed while adding an entry', { cashbook: e.cashbook })
			currentCashbook.value = e.cashbook
			conflict.value = true
			emit('conflict', e.cashbook)
			return false
		}
		logger.error('Failed to add cashbook entry', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('cashbook', 'Could not add the entry.')
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
		:name="t('cashbook', 'New entry')"
		@update:open="onUpdateOpen">
		<div ref="fields" :class="$style.fields">
			<p :class="$style.balance">
				{{ t('cashbook', 'Cash balance: {balance}', { balance: formattedBalance }) }}
			</p>
			<NcRadioGroup v-model="type" :label="t('cashbook', 'Entry type')" hideLabel>
				<NcRadioGroupButton value="income" :label="t('cashbook', 'Income')" />
				<NcRadioGroupButton value="expense" :label="t('cashbook', 'Expense')" />
			</NcRadioGroup>
			<NcSelect
				v-model="selectedReason"
				:class="$style.fullWidth"
				:clearable="false"
				:inputLabel="t('cashbook', 'Reason')"
				:options="reasonOptions"
				required />
			<div :class="$style.row">
				<NcTextField
					v-model="amount"
					type="number"
					required
					:min="amountStep"
					:step="amountStep"
					:label="t('cashbook', 'Amount ({currency})', { currency: cashbook.currency })" />
				<NcTextField
					v-model="taxPercent"
					type="number"
					min="0"
					max="100"
					step="0.01"
					:label="t('cashbook', 'Tax rate (%)')" />
			</div>
			<NcTextField
				v-model="text"
				:class="$style.fullWidth"
				:maxlength="1000"
				:label="t('cashbook', 'Text')" />
			<div :class="$style.row">
				<NcTextField
					v-model="reference"
					:maxlength="127"
					:label="t('cashbook', 'Receipt number')" />
				<NcDateTimePickerNative
					v-model="receiptDate"
					type="date"
					clearable
					:max="today"
					:label="t('cashbook', 'Receipt date')" />
			</div>
			<NcNoteCard type="info" :text="t('cashbook', 'Entries can not be changed or deleted once they are added.')" />
			<NcNoteCard
				v-if="conflict"
				type="warning"
				:text="t('cashbook', 'The cashbook was changed in the meantime. Check the cash balance and add the entry again.')" />
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

.balance {
	font-weight: bold;
}

.fullWidth {
	margin-block-start: calc(3 * var(--default-grid-baseline)) !important;
	width: 100%;
}

.row {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(2 * var(--default-grid-baseline));

	> * {
		flex: 1 1 12em;
	}
}
</style>
