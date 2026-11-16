<!--
 - SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<script setup lang="ts">
import { getCurrentUser } from '@nextcloud/auth'
import { t } from '@nextcloud/l10n'
import NcAppContent from '@nextcloud/vue/components/NcAppContent'
import NcContent from '@nextcloud/vue/components/NcContent'
import AppNavigation from './views/AppNavigation.vue'

const showNavigation = getCurrentUser() !== null
</script>

<template>
	<NcContent appName="cashbook">
		<AppNavigation v-if="showNavigation" />
		<NcAppContent :pageHeading="t('cashbook', 'Cashbook')">
			<RouterView />
		</NcAppContent>
	</NcContent>
</template>
