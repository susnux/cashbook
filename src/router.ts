/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { RouteLocationNormalized, RouteParams } from 'vue-router'

import { generateUrl } from '@nextcloud/router'
import { createRouter, createWebHistory } from 'vue-router'
import EmptyView from './views/EmptyView.vue'

const CashbookView = () => import('./views/CashbookView.vue')

const baseUrl = generateUrl('/apps/cashbook')

/**
 * Get a route parameter as a single string (repeatable parameters return their first value)
 *
 * @param paramName - The name of the route parameter
 * @param routeParams - The parameters of the current route
 */
function normalizeParam(paramName: string, routeParams: RouteParams) {
	return typeof routeParams[paramName] === 'string' ? routeParams[paramName] : routeParams[paramName][0]
}

export const routes = [
	{ path: '/', name: 'root', component: EmptyView },
	{ path: '/not-found', name: 'not-found', component: EmptyView },
	{
		path: '/:cashbook(\\d+)',
		name: 'cashbook',
		component: CashbookView,
		props: (route: RouteLocationNormalized) => ({ cashbookId: Number.parseInt(normalizeParam('cashbook', route.params)) }),
	},
	{
		path: '/:cashbook(\\d+)/export',
		name: 'cashbook-export',
		component: CashbookView,
		props: (route: RouteLocationNormalized) => ({
			cashbookId: Number.parseInt(normalizeParam('cashbook', route.params)),
		}),
	},
]

export const router = createRouter({
	history: createWebHistory(baseUrl),

	routes,
})
