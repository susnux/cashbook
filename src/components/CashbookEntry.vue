<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { mdiUndoVariant } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { useFormatTime } from '@nextcloud/vue/composables/useFormatDateTime'
import { computed } from 'vue'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import { EntryReasonReadable, isReversible } from '../models/CashbookEntry.ts'
import { formatCurrency, formatTaxRate } from '../utils/formatter.ts'
import { parseDay } from '../utils/period.ts'

const props = defineProps<{
	cashbook: ICashbook
	modelValue: ICashbookEntry
	/** Hide the actions column */
	readonly?: boolean
	/** The sequence numbers of the shown entries, links to other entries are only shown for these */
	shownSequences: ReadonlySet<number>
}>()

const emit = defineEmits<{
	/** The user wants to reverse the entry */
	(e: 'reverse', entry: ICashbookEntry): void
}>()

const formattedTime = useFormatTime(() => props.modelValue.date, () => ({ format: { dateStyle: 'medium', timeStyle: 'short', timeZone: props.cashbook.timezone } }))
const receiptDate = computed(() => parseDay(props.modelValue.referenceDate))
const formattedTax = computed(() => props.modelValue.tax === null ? '' : formatTaxRate(props.modelValue.tax))
const formattedReason = computed(() => props.modelValue.reason ? EntryReasonReadable[props.modelValue.reason] : '')

/** The link to the reversed entry or to the reversal */
const reversalLink = computed(() => {
	const { reverses, reversedBy } = props.modelValue
	if (reverses !== null) {
		return { sequence: reverses, label: t('cashbook', 'Reverses #{sequence}', { sequence: reverses }) }
	}
	if (reversedBy !== null) {
		return { sequence: reversedBy, label: t('cashbook', 'Reversed by #{sequence}', { sequence: reversedBy }) }
	}
	return null
})
</script>

<template>
	<tr :id="`entry-${modelValue.sequence}`">
		<td>{{ modelValue.sequence }}</td>
		<td>
			<div :class="$style.wrappedText" v-text="formattedTime" />
		</td>
		<td>
			<div :class="$style.wrappedText" v-text="formattedReason" />
		</td>
		<td>
			<div :class="$style.wrappedText" v-text="modelValue.text" />
			<div v-if="reversalLink" :class="$style.reversalNote">
				<a v-if="shownSequences.has(reversalLink.sequence)" :href="`#entry-${reversalLink.sequence}`">{{ reversalLink.label }}</a>
				<template v-else>
					{{ reversalLink.label }}
				</template>
			</div>
		</td>
		<td>
			<div :class="$style.wrappedText" v-text="modelValue.reference" />
			<NcDateTime
				v-if="receiptDate"
				:class="$style.receiptDate"
				:relativeTime="false"
				:timestamp="receiptDate"
				:format="{ dateStyle: 'medium' }" />
		</td>
		<td>{{ formattedTax }}</td>
		<td>{{ 'debit' in modelValue ? formatCurrency(modelValue.debit, cashbook.currency) : '' }}</td>
		<td>{{ 'credit' in modelValue ? formatCurrency(modelValue.credit, cashbook.currency) : '' }}</td>
		<td :class="$style.balanceColumn">
			{{ formatCurrency(modelValue.balance, cashbook.currency) }}
		</td>
		<td v-if="!readonly" :class="$style.actionsColumn">
			<NcActions
				v-if="isReversible(modelValue)"
				forceMenu
				:ariaLabel="t('cashbook', 'Actions for entry #{sequence}', { sequence: modelValue.sequence })">
				<NcActionButton closeAfterClick @click="emit('reverse', modelValue)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiUndoVariant" />
					</template>
					{{ t('cashbook', 'Reverse entry') }}
				</NcActionButton>
			</NcActions>
		</td>
	</tr>
</template>

<style module>
.wrappedText {
	text-wrap: wrap;
}

.receiptDate {
	color: var(--color-text-maxcontrast);
}

.balanceColumn {
	font-weight: bold;
}

.reversalNote {
	color: var(--color-text-maxcontrast);

	a {
		text-decoration: underline;
	}
}

tr > td.actionsColumn {
	width: var(--default-clickable-area);
	padding-inline: 0;
}

@media print {
	tr > td.actionsColumn {
		display: none;
	}
}
</style>
