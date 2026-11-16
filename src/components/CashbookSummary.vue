<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { t } from '@nextcloud/l10n'
import { computed, useId } from 'vue'
import { formatCurrency, formatTaxRate } from '../utils/formatter.ts'
import { calculateTotals } from '../utils/totals.ts'

const props = defineProps<{
	cashbook: ICashbook
	entries: ICashbookEntry[]
}>()

const headingId = useId()
const totals = computed(() => calculateTotals(props.entries))

/**
 * Format an optional amount in the currency of the cashbook
 *
 * @param amount - The amount as decimal string
 */
function format(amount: string | null): string {
	return amount === null ? '' : formatCurrency(amount, props.cashbook.currency)
}
</script>

<template>
	<section :class="$style.summary" :aria-labelledby="headingId">
		<h3 :id="headingId" :class="$style.heading">
			{{ t('cashbook', 'Totals of the period') }}
		</h3>

		<dl :class="$style.balances">
			<div>
				<dt>{{ t('cashbook', 'Opening balance') }}</dt>
				<dd>{{ format(totals.openingBalance) }}</dd>
			</div>
			<div>
				<dt>{{ t('cashbook', 'Income') }}</dt>
				<dd>{{ format(totals.income) }}</dd>
			</div>
			<div>
				<dt>{{ t('cashbook', 'Expenses') }}</dt>
				<dd :class="$style.credit">
					{{ format(totals.expenses) }}
				</dd>
			</div>
			<div>
				<dt>{{ t('cashbook', 'Closing balance') }}</dt>
				<dd>{{ format(totals.closingBalance) }}</dd>
			</div>
		</dl>

		<table :class="$style.taxTable">
			<caption>{{ t('cashbook', 'Taxes per rate') }}</caption>
			<thead>
				<tr>
					<th rowspan="2" scope="col">
						{{ t('cashbook', 'Tax rate') }}
					</th>
					<th colspan="3" scope="colgroup">
						{{ t('cashbook', 'Income') }}
					</th>
					<th colspan="3" scope="colgroup">
						{{ t('cashbook', 'Expenses') }}
					</th>
				</tr>
				<tr>
					<template v-for="side of ['income', 'expenses']" :key="side">
						<th scope="col">
							{{ t('cashbook', 'Gross') /* TRANSLATORS: Amount including tax */ }}
						</th>
						<th scope="col">
							{{ t('cashbook', 'Net') /* TRANSLATORS: Amount excluding tax */ }}
						</th>
						<th scope="col">
							{{ t('cashbook', 'Tax') /* TRANSLATORS: A short form for sales tax */ }}
						</th>
					</template>
				</tr>
			</thead>
			<tbody>
				<tr v-for="tax of totals.taxes" :key="tax.rate ?? 'none'">
					<th scope="row">
						{{ tax.rate === null ? t('cashbook', 'No tax rate') : formatTaxRate(tax.rate) }}
					</th>
					<td>{{ format(tax.income.gross) }}</td>
					<td>{{ format(tax.income.net) }}</td>
					<td>{{ format(tax.income.tax) }}</td>
					<td>{{ format(tax.expenses.gross) }}</td>
					<td>{{ format(tax.expenses.net) }}</td>
					<td>{{ format(tax.expenses.tax) }}</td>
				</tr>
			</tbody>
			<tfoot>
				<tr>
					<th scope="row">
						{{ t('cashbook', 'Total') }}
					</th>
					<td>{{ format(totals.income) }}</td>
					<td />
					<td>{{ format(totals.incomeTax) }}</td>
					<td>{{ format(totals.expenses) }}</td>
					<td />
					<td>{{ format(totals.expensesTax) }}</td>
				</tr>
			</tfoot>
		</table>
	</section>
</template>

<style module lang="scss">
.summary {
	margin-inline-start: var(--app-navigation-padding);
	margin-block: calc(4 * var(--default-grid-baseline));
	padding-inline: calc(2 * var(--default-grid-baseline));
	break-inside: avoid;
}

.heading {
	font-size: 1.2em;
	margin-block: calc(2 * var(--default-grid-baseline));
}

.balances {
	display: flex;
	flex-wrap: wrap;
	gap: calc(2 * var(--default-grid-baseline)) calc(6 * var(--default-grid-baseline));
	margin-block-end: calc(4 * var(--default-grid-baseline));

	dt {
		color: var(--color-text-maxcontrast);
	}

	dd {
		font-weight: bold;
	}
}

.credit {
	color: var(--color-error-text);
}

.taxTable {
	caption {
		font-weight: bold;
		text-align: start;
		margin-block-end: var(--default-grid-baseline);
	}

	th,
	td {
		padding-inline: calc(2 * var(--default-grid-baseline));
		text-align: end;
	}

	thead th {
		text-align: center;
		border-bottom: 1px solid var(--color-border-maxcontrast);
	}

	tbody th,
	tfoot th {
		text-align: start;
	}

	tfoot > tr > * {
		font-weight: bold;
		border-top: 1px solid var(--color-border-maxcontrast);
	}

	/* Expenses are negative */
	td:nth-child(n+5) {
		color: var(--color-error-text);
	}
}
</style>
