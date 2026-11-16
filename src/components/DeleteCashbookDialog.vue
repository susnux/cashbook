<!--
 - SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'

import { t } from '@nextcloud/l10n'
import { computed, ref } from 'vue'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { deleteCashbook } from '../api/cashbooks.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const props = defineProps<{
	cashbook: ICashbook
}>()

const emit = defineEmits<{
	(e: 'close', deleted: boolean): void
}>()

const deleted = ref(false)
const error = ref('')
const loading = ref(false)

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Cancel'),
	},
	{
		variant: 'error',
		label: t('cashbook', 'Delete cashbook'),
		disabled: loading.value,
		callback: onDelete,
	},
])

/**
 * Delete the cashbook, keep the dialog open on failure.
 */
async function onDelete() {
	error.value = ''
	loading.value = true
	try {
		await deleteCashbook(props.cashbook)
		deleted.value = true
	} catch (e) {
		logger.error('Failed to delete cashbook', { error: e })
		error.value = getOcsErrorMessage(e) ?? t('cashbook', 'Could not delete the cashbook.')
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
		emit('close', deleted.value)
	}
}
</script>

<template>
	<NcDialog
		:buttons="buttons"
		:name="t('cashbook', 'Delete cashbook')"
		@update:open="onUpdateOpen">
		<p>
			{{ t('cashbook', 'Do you really want to delete the cashbook "{name}" including all of its entries? This cannot be undone.', { name: cashbook.name }) }}
		</p>
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>
