<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Period, PeriodType } from '../utils/period.ts'

import { mdiChevronLeft, mdiChevronRight } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import { addDays, formatDay, formatPeriod, getPeriod, parseDay, shiftPeriod } from '../utils/period.ts'

const period = defineModel<Period>({ required: true })

const typeOptions: { value: PeriodType, label: string }[] = [
	{ value: 'month', label: t('cashbook', 'Month') },
	{ value: 'quarter', label: t('cashbook', 'Quarter') },
	{ value: 'year', label: t('cashbook', 'Year') },
	{ value: 'custom', label: t('cashbook', 'Custom range') },
]

const selectedType = computed({
	get: () => typeOptions.find(({ value }) => value === period.value.type),
	set: (option?: { value: PeriodType } | null) => {
		if (!option || option.value === period.value.type) {
			return
		}
		period.value = option.value === 'custom'
			? { ...period.value, type: 'custom' }
			: getPeriod(option.value, period.value.begin)
	},
})

const label = computed(() => formatPeriod(period.value))

// The date pickers work with local dates, the period with plain days
const customBegin = computed({
	get: () => parseDay(period.value.begin),
	set: (value: Date | null) => {
		if (!value) {
			return
		}
		const begin = formatDay(value)
		const end = begin < period.value.end ? period.value.end : addDays(begin, 1)
		period.value = { type: 'custom', begin, end }
	},
})

const customEnd = computed({
	get: () => parseDay(addDays(period.value.end, -1)),
	set: (value: Date | null) => {
		if (!value) {
			return
		}
		const end = addDays(formatDay(value), 1)
		const begin = period.value.begin < end ? period.value.begin : addDays(end, -1)
		period.value = { type: 'custom', begin, end }
	},
})
</script>

<template>
	<div :class="$style.periodSelector">
		<div :class="$style.periodNavigation">
			<NcButton
				:aria-label="t('cashbook', 'Previous period')"
				:class="$style.noPrint"
				variant="tertiary"
				@click="period = shiftPeriod(period, -1)">
				<template #icon>
					<NcIconSvgWrapper directional :path="mdiChevronLeft" />
				</template>
			</NcButton>
			<span :class="$style.periodLabel">{{ label }}</span>
			<NcButton
				:aria-label="t('cashbook', 'Next period')"
				:class="$style.noPrint"
				variant="tertiary"
				@click="period = shiftPeriod(period, 1)">
				<template #icon>
					<NcIconSvgWrapper directional :path="mdiChevronRight" />
				</template>
			</NcButton>
		</div>
		<NcSelect
			v-model="selectedType"
			:class="[$style.typeSelect, $style.noPrint]"
			:clearable="false"
			:inputLabel="t('cashbook', 'Period')"
			labelOutside
			:options="typeOptions" />
		<template v-if="period.type === 'custom'">
			<NcDateTimePickerNative
				v-model="customBegin"
				:class="$style.noPrint"
				type="date"
				:label="t('cashbook', 'From')" />
			<NcDateTimePickerNative
				v-model="customEnd"
				:class="$style.noPrint"
				type="date"
				:label="t('cashbook', 'To')" />
		</template>
	</div>
</template>

<style module>
.periodSelector {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	gap: calc(2 * var(--default-grid-baseline));
}

.periodNavigation {
	display: flex;
	align-items: center;
	gap: var(--default-grid-baseline);
}

.periodLabel {
	font-weight: bold;
	min-width: 12ch;
	text-align: center;
}

.typeSelect {
	margin-block: 0 !important;
	min-width: 20ch;
}

@media print {
	.noPrint {
		display: none !important;
	}
}
</style>
