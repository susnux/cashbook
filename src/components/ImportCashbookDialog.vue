<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'

import { mdiClose } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { computed, ref, useId } from 'vue'
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

const cashbookFile = ref<File>()
const reportFile = ref<File>()
const timezone = ref(Intl.DateTimeFormat().resolvedOptions().timeZone)
const timezonePickerId = 'cashbook-import-timezone'
const cashbookHintId = useId()
const cashbookFileNameId = useId()
const reportHintId = useId()
const reportFileNameId = useId()
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
		disabled: !cashbookFile.value,
		callback: onImport,
	},
])

/**
 * Upload the selected files, keep the dialog open on failure.
 */
async function onImport() {
	if (!cashbookFile.value) {
		return false
	}

	error.value = ''
	try {
		imported.value = await importSumupCashbook({ cashbook: cashbookFile.value, report: reportFile.value }, timezone.value)
	} catch (e) {
		logger.error('Failed to import cashbook', { error: e })
		error.value = getOcsErrorMessage(e)
			?? t('cashbook', 'Could not import the cashbook. Please make sure you selected the cashbook export (Kassenbuch_….csv) and optionally the matching daily closing report.')
		return false
	}
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
		<fieldset :class="$style.fileField" :aria-describedby="`${cashbookHintId} ${cashbookFileNameId}`">
			<legend :class="$style.fileLegend">
				{{ t('cashbook', 'Cashbook export') }}
			</legend>
			<p :id="cashbookHintId">
				{{ t('cashbook', 'The export of the cash register book (Kassenbuch_….csv).') }}
			</p>
			<div :class="$style.fileSelection">
				<NcFilePicker
					:accept="CSV_TYPES"
					:label="t('cashbook', 'Select cashbook export')"
					variant="secondary"
					@pick="cashbookFile = $event[0]" />
				<span :id="cashbookFileNameId" :class="$style.fileName">
					{{ cashbookFile?.name ?? t('cashbook', 'No file selected') }}
				</span>
			</div>
		</fieldset>
		<fieldset :class="$style.fileField" :aria-describedby="`${reportHintId} ${reportFileNameId}`">
			<legend :class="$style.fileLegend">
				{{ t('cashbook', 'Daily closing report (optional)') }}
			</legend>
			<p :id="reportHintId">
				{{ t('cashbook', 'The export of the daily closings to import the day openings and closings.') }}
			</p>
			<div :class="$style.fileSelection">
				<NcFilePicker
					:accept="CSV_TYPES"
					:label="t('cashbook', 'Select daily closing report')"
					variant="secondary"
					@pick="reportFile = $event[0]" />
				<span :id="reportFileNameId" :class="$style.fileName">
					{{ reportFile?.name ?? t('cashbook', 'No file selected') }}
				</span>
				<NcButton
					v-if="reportFile"
					:aria-label="t('cashbook', 'Remove daily closing report')"
					variant="tertiary"
					@click="reportFile = undefined">
					<template #icon>
						<NcIconSvgWrapper :path="mdiClose" />
					</template>
				</NcButton>
			</div>
		</fieldset>
		<label :class="$style.timezoneLabel" :for="`vs-${timezonePickerId}__combobox`">
			{{ t('cashbook', 'Timezone of the export') }}
		</label>
		<NcTimezonePicker v-model="timezone" :uid="timezonePickerId" />
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

.timezoneLabel {
	display: block;
	margin-block-start: calc(2 * var(--default-grid-baseline));
}
</style>
