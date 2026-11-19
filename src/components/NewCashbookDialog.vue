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
import NcTimezonePicker from '@nextcloud/vue/components/NcTimezonePicker'
import { createCashbook, updateCashbook } from '../api/cashbooks.ts'
import { getCurrencyOptions } from '../utils/currencies.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'
import { getBrowserTimezone, toBrowserTimezoneId, UTC_TIMEZONE } from '../utils/timezone.ts'

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
	timezone: getBrowserTimezone(),
})

watch(() => props.cashbook, () => {
	if (props.cashbook) {
		const { name, account, currency, timezone } = props.cashbook
		newCashbook.value = { name, account, currency, timezone: toBrowserTimezoneId(timezone) }
	}
}, { immediate: true })

// Fixed ID as the dialog is mounted as a separate Vue app, so `useId()` would collide with IDs of the main app
const timezonePickerId = 'cashbook-timezone'

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
		:contentClasses="$style.newCashbookDialog"
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
			:class="$style.newCashbookDialog__select"
			:clearable="false"
			:disabled="isEditing"
			:inputLabel="t('cashbook', 'Currency')"
			:options="currencyOptions"
			required />
		<!-- The picker sets an `ariaLabelCombobox` that would replace the input label as accessible name -->
		<NcTimezonePicker
			v-model="newCashbook.timezone"
			:class="$style.newCashbookDialog__select"
			:additionalTimezones="[UTC_TIMEZONE]"
			:ariaLabelCombobox="null"
			:disabled="isEditing"
			:inputLabel="t('cashbook', 'Timezone')"
			:uid="timezonePickerId" />
		<p :class="$style.newCashbookDialog__timezoneHint">
			{{ t('cashbook', 'The days of the cashbook follow this timezone. It can not be changed later.') }}
		</p>
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>

<style lang="scss" module>
.newCashbookDialog {
	> *:not(.newCashbookDialog__timezoneHint) {
		margin-block: calc(3 * var(--default-grid-baseline));
	}
}

.newCashbookDialog__select {
	width: 100%;
}

.newCashbookDialog__timezoneHint {
	color: var(--color-text-maxcontrast);
}
</style>
