<script setup lang="ts">
/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { ICashbook } from '../models/Cashbook'

import { t } from '@nextcloud/l10n'
import { ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createCashbook } from '../api/cashbooks'
import { useCashbookStore } from '../store/cashbookStore'

const props = defineProps<{
	cashbook?: Omit<ICashbook, 'id'>
}>()

const emit = defineEmits<{
	(e: 'close', payload?: Omit<ICashbook, 'id'>): void
}>()

const newCashbook = ref<Omit<ICashbook, 'id'>>({
	currency: 'EUR',
	name: '',
	account: '',
})

watch(() => props.cashbook, () => {
	if (props.cashbook) {
		newCashbook.value = { ...props.cashbook }
	}
}, { immediate: true })

const allCurrencies = [
	{
		label: 'Euro',
		value: 'EUR',
	},
	{
		label: 'US Dollar',
		value: 'USD',
	},
]

const buttons: InstanceType<typeof NcDialog>['buttons'] = [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Cancel'),
		callback: async () => {
			emit('close')
		},
	},
	{
		type: 'submit',
		variant: 'primary',
		label: t('cashbook', 'Create cashbook'),
		callback: async () => {
			await onCreateCashbook()
			emit('close')
		},
	},
]

const store = useCashbookStore()

/**
 *
 */
async function onCreateCashbook() {
	const cb = await createCashbook(newCashbook.value)
	store.cashbooks.push(cb)
}
const open = ref(true)
</script>

<template>
	<NcDialog
		isForm
		:v-model:open="open"
		:buttons="buttons"
		:name="t('cashbook', 'New cashbook')">
		<NcTextField v-model="newCashbook.name" required :label="t('cashbook', 'Cashbook name')" />
		<NcTextField v-model="newCashbook.account" :label="t('cashbook', 'Account')" />
		<NcSelect
			v-model="newCashbook.currency"
			:class="$style.currencySelect"
			:clearable="false"
			:inputLabel="t('cashbook', 'Currency')"
			:options="allCurrencies"
			required />
	</NcDialog>
</template>

<style lang="scss" module>
.currencySelect {
	width: 100%;
}
</style>
