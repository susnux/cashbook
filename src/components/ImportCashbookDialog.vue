<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'

import { mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, nextTick, ref } from 'vue'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcFilePicker from '@nextcloud/vue/components/NcFilePicker'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTimezonePicker from '@nextcloud/vue/components/NcTimezonePicker'
import { importSumupCashbook } from '../api/cashbooks.ts'
import { getOcsErrorMessage } from '../utils/errors.ts'
import logger from '../utils/logger.ts'

const emit = defineEmits<{
	(e: 'close', cashbook?: ICashbook): void
}>()

const CSV_TYPES = ['.csv', 'text/csv']

type ImportFiles = Parameters<typeof importSumupCashbook>[0]

interface FileField {
	name: keyof ImportFiles
	legend: string
	hint: string
	pickLabel: string
	/** Label of the button to remove the selected file, only set for optional files */
	removeLabel?: string
}

const fileFields: FileField[] = [
	{
		name: 'cashbook',
		legend: t('cashbook', 'Cashbook export'),
		hint: t('cashbook', 'The export of the cash register book (Kassenbuch_….csv).'),
		pickLabel: t('cashbook', 'Select cashbook export'),
	},
	{
		name: 'report',
		legend: t('cashbook', 'Daily closing report (optional)'),
		hint: t('cashbook', 'The export of the daily closings to import the day openings and closings.'),
		pickLabel: t('cashbook', 'Select daily closing report'),
		removeLabel: t('cashbook', 'Remove daily closing report'),
	},
]

const files = ref<Partial<ImportFiles>>({})
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone)
const timezonePickerId = 'cashbook-import-timezone'
const error = ref('')
const imported = ref<ICashbook>()

const buttons = computed<InstanceType<typeof NcDialog>['buttons']>(() => [
	{
		variant: 'tertiary',
		label: t('cashbook', 'Cancel'),
	},
	{
		variant: 'primary',
		label: t('cashbook', 'Import cashbook'),
		disabled: !files.value.cashbook,
		callback: onImport,
	},
])

/**
 * Upload the selected files, keep the dialog open on failure.
 */
async function onImport() {
	const { cashbook, report } = files.value
	if (!cashbook) {
		return false
	}

	error.value = ''
	try {
		imported.value = await importSumupCashbook({ cashbook, report }, timezone.value)
	} catch (e) {
		logger.error('Failed to import cashbook', { error: e })
		error.value = getOcsErrorMessage(e)
			?? t('cashbook', 'Could not import the cashbook. Please make sure you selected the cashbook export (Kassenbuch_….csv) and optionally the matching daily closing report.')
		return false
	}
}

/**
 * Remove a selected file and move the focus to its file picker, as the remove button disappears.
 *
 * @param name - The name of the file
 * @param event - The click event of the remove button
 */
async function removeFile(name: FileField['name'], event: MouseEvent) {
	const fieldset = (event.currentTarget as HTMLElement | null)?.closest('fieldset')
	delete files.value[name]
	await nextTick()
	fieldset?.querySelector('button')?.focus()
}

/**
 * @param open - The new open state of the dialog
 */
function onUpdateOpen(open: boolean) {
	if (!open) {
		emit('close', imported.value)
	}
}
</script>

<template>
	<NcDialog
		:buttons="buttons"
		:name="t('cashbook', 'Import SumUp cashbook')"
		@update:open="onUpdateOpen">
		<p>
			{{ t('cashbook', 'Select the CSV exports of the SumUp POS Pro.') }}
		</p>
		<!-- Fixed IDs as the dialog is mounted as a separate Vue app, so `useId()` would collide with IDs of the main app -->
		<fieldset
			v-for="field in fileFields"
			:key="field.name"
			:class="$style.fileField"
			:aria-describedby="`cashbook-import-${field.name}-hint cashbook-import-${field.name}-file-name`">
			<legend :class="$style.fileLegend">
				{{ field.legend }}
			</legend>
			<p :id="`cashbook-import-${field.name}-hint`">
				{{ field.hint }}
			</p>
			<div :class="$style.fileSelection">
				<NcFilePicker
					:accept="CSV_TYPES"
					:label="field.pickLabel"
					variant="secondary"
					@pick="files[field.name] = $event[0]" />
				<span :id="`cashbook-import-${field.name}-file-name`" :class="$style.fileName">
					{{ files[field.name]?.name ?? t('cashbook', 'No file selected') }}
				</span>
				<NcButton
					v-if="field.removeLabel && files[field.name]"
					:aria-label="field.removeLabel"
					variant="tertiary"
					@click="removeFile(field.name, $event)">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</div>
		</fieldset>
		<!-- The picker sets an `ariaLabelCombobox` that would replace the input label as accessible name -->
		<NcTimezonePicker
			v-model="timezone"
			:ariaLabelCombobox="null"
			:inputLabel="t('cashbook', 'Timezone of the export and the cashbook')"
			:uid="timezonePickerId" />
		<p :class="$style.timezoneHint">
			{{ t('cashbook', 'The dates of the export are read in this timezone. The days of the cashbook follow it, it can not be changed later.') }}
		</p>
		<NcNoteCard v-if="error" type="error" :text="error" />
	</NcDialog>
</template>

<style lang="scss" module>
.fileField {
	margin-block-start: calc(2 * var(--default-grid-baseline));
}

.fileLegend {
	font-weight: bold;
}

.fileSelection {
	display: flex;
	align-items: center;
	gap: calc(2 * var(--default-grid-baseline));
	margin-block-start: var(--default-grid-baseline);
}

.fileName {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.timezoneHint {
	color: var(--color-text-maxcontrast);
}
</style>
