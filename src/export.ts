/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import CashbookPublicApp from './CashbookPublicApp.vue'
import { router } from './router.ts'
import { pinia } from './store/index.ts'

const app = createApp(CashbookPublicApp)
app.use(router)
app.use(pinia)
app.mount('#content')
