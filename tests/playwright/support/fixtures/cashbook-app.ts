/*!
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { User } from '@nextcloud/e2e-test-server'

import { runOcc } from '@nextcloud/e2e-test-server/docker'
import { createRandomUser, login } from '@nextcloud/e2e-test-server/playwright'
import { test as baseTest } from '@playwright/test'
import { CashbookAppPage } from '../sections/CashbookAppPage.ts'
import { retryWithBackoff } from '../utils/retry.ts'

/**
 * Test with a fresh random `user`, a `page` logged in as that user
 * and the `cashbookApp` page object.
 * The user and all of its cashbooks are deleted in teardown.
 */
export const test = baseTest.extend<{ user: User, cashbookApp: CashbookAppPage }>({
	// eslint-disable-next-line no-empty-pattern
	user: async ({}, use) => {
		const user = await retryWithBackoff('Creating a random user', () => createRandomUser())
		await use(user)
		await runOcc(['user:delete', user.userId], { failOnError: false })
	},

	page: async ({ page, user }, use) => {
		await retryWithBackoff('Logging in as random user', () => login(page.request, user))
		await use(page)
	},

	cashbookApp: async ({ page }, use) => {
		await use(new CashbookAppPage(page))
	},
})

export { expect } from '@playwright/test'
