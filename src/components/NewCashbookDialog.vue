<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook, ICashbookData } from '../models/Cashbook.ts'
import type { CurrencyOption } from '../utils/currencies.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref, watch } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createCashbook, updateCashbook } from '../api/cashbooks.ts'
import { getCurrencyOptions } from '../utils/currencies.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	/** The cashbook to edit, if not set a new cashbook is created */
	cashbook?: ICashbook
}>()

const emit = defineEmits<{
	(e: 'close', cashbook?: ICashbook): void
}>()

const newCashbook = ref<ICashbookData>({
	currency: 'EUR',
	name: '',
	account: '',
})

watch(() => props.cashbook, () => {
	if (props.cashbook) {
		const { name, account, currency } = props.cashbook
		newCashbook.value = { name, account, currency }
	}
}, { immediate: true })

const currencyOptions = getCurrencyOptions()
const selectedCurrency = computed({
	get: () => currencyOptions.find(({ value }) => value === newCashbook.value.currency),
	set: (option: CurrencyOption | null | undefined) => {
		if (option) {
			newCashbook.value.currency = option.value
		}
	},
})

const saved = ref<ICashbook>()
const error = ref('')
const loading = ref(false)

const isEditing = computed(() => props.cashbook !== undefined)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Cancel'),
	},
	{
		type: 'submit',
		variant: 'primary',
		label: isEditing.value ? t('cashbook', 'Save') : t('cashbook', 'Create cashbook'),
		disabled: loading.value,
		callback: onSaveCashbook,
	},
])

/**
 * Create or update the cashbook, keep the dialog open on failure.
 */
async function onSaveCashbook() {
	error.value = ''
	loading.value = true
	try {
		saved.value = props.cashbook
			? await updateCashbook(props.cashbook.id, newCashbook.value)
			: await createCashbook(newCashbook.value)
	} catch (e) {
		logger.error('Failed to save cashbook', { error: e })
		error.value = getOcsErrorMessage(e) ?? (isEditing.value
			? t('cashbook', 'Could not save the cashbook.')
			: t('cashbook', 'Could not create the cashbook.'))
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
		:buttons="buttons"
		:name="isEditing ? t('cashbook', 'Edit cashbook') : t('cashbook', 'New cashbook')"
		@update:open="onUpdateOpen">
		<NcTextField
			v-model="newCashbook.name"
			required
			:maxlength="255"
			:label="t('cashbook', 'Cashbook name')" />
		<NcTextField
			v-model="newCashbook.account"
			:maxlength="255"
			:label="t('cashbook', 'Account')" />
		<NcSelect
			v-model="selectedCurrency"
			:class="$style.currencySelect"
			:clearable="false"
			:disabled="isEditing"
			:inputLabel="t('cashbook', 'Currency')"
			:options="currencyOptions"
			required />
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>

<style lang="scss" module>
.currencySelect {
	width: 100%;
}
</style>
