<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { Period } from '../utils/period.ts'

import { mdiAlertCircleOutline, mdiCashRemove, mdiRefresh } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import CashbookTable from '../components/CashbookTable.vue'
import PeriodSelector from '../components/PeriodSelector.vue'
import { useCashbookEntries } from '../composables/useCashbookEntries.ts'
import { useCashbookStore } from '../store/cashbookStore.ts'
import { periodFromQuery, periodToQuery } from '../utils/period.ts'

const props = defineProps<{
	cashbookId: number
}>()

const route = useRoute()
const router = useRouter()
const store = useCashbookStore()

const cashbook = computed(() => store.cashbooks.find((cb) => cb.id === props.cashbookId))
const period = computed<Period>({
	get: () => periodFromQuery(route.query),
	set: (value) => router.replace({ query: { ...route.query, ...periodToQuery(value) } }),
})

const { entries, loading, failed, reload } = useCashbookEntries(cashbook, period)

watchEffect(() => {
	if (store.loaded && !cashbook.value) {
		router.replace({ name: 'not-found' })
	}
})
</script>

<template>
	<NcEmptyContent
		v-if="store.failed"
		:class="$style.emptyContent"
		:name="t('cashbook', 'Could not load the cashbooks')">
		<template #icon>
			<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
		</template>
		<template #action>
			<NcButton @click="store.load()">
				<template #icon>
					<NcIconSvgWrapper :path="mdiRefresh" />
				</template>
				{{ t('cashbook', 'Retry') }}
			</NcButton>
		</template>
	</NcEmptyContent>

	<NcEmptyContent
		v-else-if="!cashbook"
		:class="$style.emptyContent"
		:name="t('cashbook', 'Loading cashbook')">
		<template #icon>
			<NcLoadingIcon />
		</template>
	</NcEmptyContent>

	<div v-else>
		<div :class="$style.header">
			<h2 :class="$style.heading">
				{{ cashbook.name }}
			</h2>
			<PeriodSelector v-model="period" />
		</div>

		<NcEmptyContent
			v-if="loading"
			:class="$style.emptyContent"
			:name="t('cashbook', 'Loading entries')">
			<template #icon>
				<NcLoadingIcon />
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="failed"
			:class="$style.emptyContent"
			:name="t('cashbook', 'Could not load the entries')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiAlertCircleOutline" />
			</template>
			<template #action>
				<NcButton @click="reload">
					<template #icon>
						<NcIconSvgWrapper :path="mdiRefresh" />
					</template>
					{{ t('cashbook', 'Retry') }}
				</NcButton>
			</template>
		</NcEmptyContent>

		<NcEmptyContent
			v-else-if="entries.length === 0"
			:class="$style.emptyContent"
			:name="t('cashbook', 'No entries')"
			:description="t('cashbook', 'There are no entries in this period.')">
			<template #icon>
				<NcIconSvgWrapper :path="mdiCashRemove" />
			</template>
		</NcEmptyContent>

		<CashbookTable v-else :cashbook :entries />
	</div>
</template>

<style module lang="scss">
.emptyContent {
	height: 100%;
	margin-block: calc(2 * var(--app-navigation-padding) + var(--default-clickable-area));
}

.header {
	display: flex;
	flex-wrap: wrap;
	align-items: end;
	justify-content: space-between;
	gap: calc(2 * var(--default-grid-baseline));
	padding-inline: calc(2 * var(--app-navigation-padding) + var(--default-clickable-area)) var(--app-navigation-padding);
	margin-block: var(--app-navigation-padding);
}

.heading {
	margin: 0;
	line-height: 1.5;
	font-size: calc(var(--default-clickable-area) / 1.5);
}

@media print {
	@page{
		size: a4;
		margin-inline: 19mm 6mm;
		margin-block: 10mm;
	}

	:global(header), :global(.app-navigation) {
		display: none !important;
	}

	:global(#content.app-cashbook) {
		margin: 0 !important;
		min-height: 100%;
		height: fit-content;
		width: 100%;
	}

	:global(#content-vue.app-cashbook) {
		border: none;
		border-radius: 0;
		width: 100%;
		height: fit-content;
	}

	:global(.app-cashbook #app-content-vue) {
		min-height: 100vh;
	}
}
</style>
