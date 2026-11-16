<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { useFormatTime } from '@nextcloud/vue/composables/useFormatDateTime'
import { computed } from 'vue'
import { EntryReasonReadable } from '../models/CashbookEntry.ts'
import { formatCurrency, formatTaxRate } from '../utils/formatter.ts'

const props = defineProps<{
	cashbook: ICashbook
	modelValue: ICashbookEntry
}>()

const formattedTime = useFormatTime(() => props.modelValue.date, { format: { dateStyle: 'medium', timeStyle: 'short' } })
const formattedTax = computed(() => props.modelValue.tax === null ? '' : formatTaxRate(props.modelValue.tax))
const formattedReason = computed(() => props.modelValue.reason ? EntryReasonReadable[props.modelValue.reason] : '')
</script>

<template>
	<tr>
		<td>{{ modelValue.id }}</td>
		<td>
			<div :class="$style.wrappedText" v-text="formattedTime" />
		</td>
		<td>
			<div :class="$style.wrappedText" v-text="formattedReason" />
		</td>
		<td>
			<div :class="$style.wrappedText" v-text="modelValue.text" />
		</td>
		<td>{{ formattedTax }}</td>
		<td>{{ 'debit' in modelValue ? formatCurrency(modelValue.debit, cashbook.currency) : '' }}</td>
		<td>{{ 'credit' in modelValue ? formatCurrency(modelValue.credit, cashbook.currency) : '' }}</td>
		<td :class="$style.balanceColumn">
			{{ formatCurrency(modelValue.balance, cashbook.currency) }}
		</td>
	</tr>
</template>

<style module>
.wrappedText {
	text-wrap: wrap;
}

.balanceColumn {
	font-weight: bold;
}
</style>
