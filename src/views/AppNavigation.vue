<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import type { ICashbook } from '../models/Cashbook.ts'

import { mdiImport, mdiPencil, mdiTrashCan } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { spawnDialog } from '@nextcloud/vue/functions/dialog'
import { useRoute, useRouter } from 'vue-router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationCaption from '@nextcloud/vue/components/NcAppNavigationCaption'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationList from '@nextcloud/vue/components/NcAppNavigationList'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import DeleteCashbookDialog from '../components/DeleteCashbookDialog.vue'
import ImportCashbookDialog from '../components/ImportCashbookDialog.vue'
import NewCashbookDialog from '../components/NewCashbookDialog.vue'
import { useCashbookStore } from '../store/cashbookStore.ts'

const store = useCashbookStore()
const route = useRoute()
const router = useRouter()

/**
 * Add a new cashbook to the store and open it
 *
 * @param cashbook - The new cashbook
 */
function openNewCashbook(cashbook: ICashbook) {
	store.cashbooks.push(cashbook)
	router.push({ name: 'cashbook', params: { cashbook: cashbook.id } })
}

/**
 * Show the import dialog and open the imported cashbook
 */
async function importCashbook() {
	const cashbook = await spawnDialog(ImportCashbookDialog)
	if (cashbook) {
		openNewCashbook(cashbook)
	}
}

/**
 * Show the dialog to create a new cashbook and open the created cashbook
 */
async function createNew() {
	const cashbook = await spawnDialog(NewCashbookDialog)
	if (cashbook) {
		openNewCashbook(cashbook)
	}
}

/**
 * Show the dialog to edit the given cashbook and update it in the store
 *
 * @param cashbook - The cashbook to edit
 */
async function onEditCashbook(cashbook: ICashbook) {
	const updated = await spawnDialog(NewCashbookDialog, { cashbook })
	if (updated) {
		store.updateCashbook(updated)
	}
}

/**
 * Ask for confirmation, then delete the given cashbook and remove it from the store
 *
 * @param cashbook - The cashbook to delete
 */
async function onDeleteCashbook(cashbook: ICashbook) {
	const deleted = await spawnDialog(DeleteCashbookDialog, { cashbook })
	if (!deleted) {
		return
	}

	// leave the cashbook view first, otherwise it redirects to "not found" once the cashbook is gone
	if (route.params.cashbook === cashbook.id) {
		await router.push({ name: 'root' })
	}
	store.cashbooks = store.cashbooks.filter(({ id }) => id !== cashbook.id)
}
</script>

<template>
	<NcAppNavigation>
		<NcAppNavigationNew :text="t('cashbook', 'New cashbook')" @click="createNew" />
		<NcAppNavigationCaption
			headingId="cashbook-app-navigation-your-cashbooks"
			isHeading
			:name="t('cashbook', 'Your cashbooks')" />
		<NcAppNavigationList aria-labelledby="cashbook-app-navigation-your-cashbooks">
			<NcAppNavigationItem
				v-for="cashbook in store.cashbooks"
				:key="cashbook.id"
				:name="cashbook.name"
				:to="{ name: 'cashbook', params: { cashbook: cashbook.id } }">
				<template #actions>
					<NcActionButton closeAfterClick @click="onEditCashbook(cashbook)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiPencil" :size="20" />
						</template>
						{{ t('cashbook', 'Edit') }}
					</NcActionButton>
					<NcActionButton closeAfterClick @click="onDeleteCashbook(cashbook)">
						<template #icon>
							<NcIconSvgWrapper :path="mdiTrashCan" :size="20" />
						</template>
						{{ t('cashbook', 'Delete') }}
					</NcActionButton>
				</template>
			</NcAppNavigationItem>
		</NcAppNavigationList>
		<template #footer>
			<NcAppNavigationList>
				<NcAppNavigationItem :name="t('cashbook', 'Import cashbook')" @click="importCashbook">
					<template #icon>
						<NcIconSvgWrapper :path="mdiImport" :size="20" />
					</template>
				</NcAppNavigationItem>
			</NcAppNavigationList>
		</template>
	</NcAppNavigation>
</template>
