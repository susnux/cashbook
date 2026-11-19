<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'
import type { ICashbookEntry } from '../models/CashbookEntry.ts'

import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcDateTime from '@nextcloud/vue/components/NcDateTime'
import CashbookEntry from './CashbookEntry.vue'
import { EntryReason } from '../models/CashbookEntry.ts'
import { formatCurrency, formatTaxRate } from '../utils/formatter.ts'
import { getDay } from '../utils/timezone.ts'
import { calculateTotals } from '../utils/totals.ts'

const props = defineProps<{
	cashbook: ICashbook
	entries: ICashbookEntry[]
}>()

/**
 * A day of the cashbook: either the entries between a day opening and its closing,
 * or the entries of a calendar day that are not within an opened day
 */
interface Section {
	/** The day opening entry if the section is an opened day */
	start?: ICashbookEntry
	/** The day closing entry if the day is closed */
	end?: ICashbookEntry
	entries: ICashbookEntry[]
}

const sections = computed(() => {
	const list: Section[] = []
	for (const entry of props.entries) {
		const current = list.at(-1)
		if (entry.reason === EntryReason.DayOpening) {
			list.push({ start: entry, entries: [] })
		} else if (entry.reason === EntryReason.DayClosing && current?.start && !current.end) {
			current.end = entry
		} else if (current?.start && !current.end) {
			current.entries.push(entry)
		} else if (current && !current.start && getDay(current.entries[0]!.date, props.cashbook.timezone) === getDay(entry.date, props.cashbook.timezone)) {
			current.entries.push(entry)
		} else {
			list.push({ entries: [entry] })
		}
	}
	return list.map((section) => ({
		...section,
		totals: calculateTotals(section.entries),
	}))
})

const dayFormat = computed<Intl.DateTimeFormatOptions>(() => ({ dateStyle: 'medium', timeZone: props.cashbook.timezone }))
const dateTimeFormat = computed<Intl.DateTimeFormatOptions>(() => ({ dateStyle: 'medium', timeStyle: 'short', timeZone: props.cashbook.timezone }))
</script>

<template>
	<table :class="$style.cashbookTable">
		<thead>
			<tr>
				<th>#</th>
				<th>
					<span>{{ t('cashbook', 'Date') }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Reason') /* TRANSLATORS: The reason for this entry e.g. "sales" or "purchase of goods" */ }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Text') }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Receipt') /* TRANSLATORS: The number and date of the receipt of an entry */ }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Tax') /* TRANSLATORS: A short form for sales tax */ }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Debit') /* TRANSLATORS: The income side of the cashbook */ }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Credit') /* TRANSLATORS: The expenses side of the cashbook */ }}</span>
				</th>
				<th>
					<span>{{ t('cashbook', 'Balance') /* TRANSLATORS: The current balance of the cashbook */ }}</span>
				</th>
			</tr>
		</thead>
		<tbody v-for="(section, index) of sections" :key="section.start ? section.start.id : `section-${index}`">
			<tr v-if="!section.start">
				<th :class="$style.sectionHeader" colspan="9">
					<div :class="$style.dayHeader">
						<div>
							<span :class="$style.textBold">
								{{ t('cashbook', 'Date:') }}
							</span>
							<NcDateTime :relativeTime="false" :timestamp="section.entries[0]!.date" :format="dayFormat" />
						</div>
					</div>
				</th>
			</tr>
			<tr v-else>
				<th :class="$style.sectionHeader" colspan="9">
					<div :class="$style.dayHeader">
						<div>
							<span :class="$style.textBold">
								{{ t('cashbook', 'Opened:') }}
							</span>
							<NcDateTime :relativeTime="false" :timestamp="section.start.date" :format="dateTimeFormat" />
						</div>
						<div v-if="section.end">
							<span :class="$style.textBold">
								{{ t('cashbook', 'Closed:') }}
							</span>
							<NcDateTime :relativeTime="false" :timestamp="section.end.date" :format="dateTimeFormat" />
							<template v-if="section.end.reference">
								<span :class="$style.textBold">
									{{ t('cashbook', 'Z-number:') }}
								</span>
								<span>
									{{ section.end.reference }}
								</span>
							</template>
						</div>
						<div>
							<span :class="$style.textBold">
								{{ t('cashbook', 'Initial cash:') }}
							</span>
							<span>
								{{ formatCurrency(section.start.balance, cashbook.currency) }}
							</span>
						</div>
						<div v-if="section.end">
							<span :class="$style.textBold">
								{{ t('cashbook', 'Closing cash:') }}
							</span>
							{{ formatCurrency(section.end.balance, cashbook.currency) }}
						</div>
					</div>
				</th>
			</tr>
			<CashbookEntry
				v-for="(entry, entryIndex) in section.entries"
				:key="entry.id"
				:class="{ [$style.stripedRow]: entryIndex % 2 === 1 }"
				:cashbook="cashbook"
				:modelValue="entry" />
			<tr v-if="section.entries.length > 0" :class="$style.totalRow">
				<td />
				<th colspan="5" scope="row">
					{{ t('cashbook', 'Total of the day') }}
				</th>
				<td :class="$style.amount">
					{{ formatCurrency(section.totals.income, cashbook.currency) }}
				</td>
				<td :class="[$style.amount, $style.credit]">
					{{ formatCurrency(section.totals.expenses, cashbook.currency) }}
				</td>
				<td />
			</tr>
			<template v-for="tax of section.totals.taxes" :key="tax.rate ?? 'none'">
				<tr v-if="tax.rate !== null" :class="$style.taxRow">
					<td />
					<th colspan="4" scope="row">
						{{ t('cashbook', 'Included tax') }}
					</th>
					<td :class="$style.amount">
						{{ formatTaxRate(tax.rate) }}
					</td>
					<td :class="$style.amount">
						{{ formatCurrency(tax.income.tax!, cashbook.currency) }}
					</td>
					<td :class="[$style.amount, $style.credit]">
						{{ formatCurrency(tax.expenses.tax!, cashbook.currency) }}
					</td>
					<td />
				</tr>
			</template>
		</tbody>
	</table>
</template>

<style module lang="scss">
.textBold {
	font-weight: bold;
}

.dayHeader {
	display: flex;
	flex-direction: row;
	gap: 2ex;
	justify-content: end;

	> div {
		display: flex;
		flex-direction: row;
		gap: .75ex;
	}
}

.cashbookTable {
	margin-inline-start: var(--app-navigation-padding);
	width: min(calc(100% - var(--app-navigation-padding)), 1920px);

	thead th {
		font-weight: bold;
		border-bottom: 2px solid var(--color-border-maxcontrast);
	}

	.stripedRow {
		background-color: var(--color-background-dark);
	}

	tr {
		height: var(--default-clickable-area);
	}

	th {
		text-align: center;
	}

	td,
	th {
		padding-inline: calc(2 * var(--default-grid-baseline));

		/* Serial, tax, debit, credit and balance are numeric */
		&:nth-child(1),
		&:nth-child(6),
		&:nth-child(7),
		&:nth-child(8),
		&:nth-child(9) {
			text-align: end;
		}
	}

	/* Credit should be negative */
	td:nth-child(8) {
		color: var(--color-error-text);
	}

	.totalRow > * {
		font-weight: bold;
		border-top: 1px solid var(--color-border-maxcontrast);
	}

	.totalRow th,
	.taxRow th {
		text-align: end;
	}

	.taxRow {
		height: auto;
	}

	.amount {
		text-align: end;
	}

	.credit {
		color: var(--color-error-text);
	}
}

@media print {
	@page {
		size: a4;
	}

	.cashbookTable {
		--default-font-size: 11pt;
		font-size: var(--default-font-size);
		table-layout: fixed;

		thead th {
			font-size: 12pt;

			/* The text gets the remaining width */
			&:nth-of-type(2) {
				width: 22mm;
			}
			&:nth-of-type(3) {
				width: 25mm;
			}
			&:nth-of-type(5) {
				width: 22mm;
			}
			&:nth-of-type(6) {
				width: 14mm;
			}
			&:nth-of-type(7) {
				width: 22mm;
			}
			&:nth-of-type(8) {
				width: 22mm;
			}
			&:nth-of-type(9) {
				width: 24mm;
			}
		}

		.sectionHeader {
			font-size: 8pt;
		}

		tbody:not(:first-of-type) {
			.sectionHeader {
				border-top: 1px solid var(--color-border-maxcontrast);
				padding-inline: 6mm 2mm;
			}
		}

		th:not([colspan]):first-child, td:first-child {
			display: none;
		}
	}
}
</style>
