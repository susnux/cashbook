/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { Period } from '../utils/period.ts'

import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import PeriodSelector from './PeriodSelector.vue'
import { getPeriod, periodToQuery } from '../utils/period.ts'

/**
 * Mount the selector with the given period
 *
 * @param modelValue - The initial period
 */
function mountSelector(modelValue: Period) {
	return mount(PeriodSelector, { props: { modelValue } })
}

/**
 * Get the last emitted period as query for easier comparison
 *
 * @param wrapper - The mounted selector
 */
function lastEmitted(wrapper: ReturnType<typeof mountSelector>) {
	const events = wrapper.emitted('update:modelValue') as [Period][]
	return periodToQuery(events.at(-1)![0])
}

describe('PeriodSelector', () => {
	it('shows the period and navigates to the previous and next period', async () => {
		const wrapper = mountSelector(getPeriod('month', '2025-01-15'))
		expect(wrapper.text()).toContain('January 2025')

		await wrapper.get('button[aria-label="Previous period"]').trigger('click')
		expect(lastEmitted(wrapper)).toEqual({ period: 'month', from: '2024-12-01', to: '2024-12-31' })

		await wrapper.get('button[aria-label="Next period"]').trigger('click')
		expect(lastEmitted(wrapper)).toEqual({ period: 'month', from: '2025-01-01', to: '2025-01-31' })
	})

	it('changes the period type', async () => {
		const wrapper = mountSelector(getPeriod('month', '2025-05-15'))
		await wrapper.getComponent(NcSelect).vm.$emit('update:modelValue', { value: 'quarter', label: 'Quarter' })
		expect(lastEmitted(wrapper)).toEqual({ period: 'quarter', from: '2025-04-01', to: '2025-06-30' })
	})

	it('allows picking a custom range', async () => {
		const wrapper = mountSelector({ ...getPeriod('month', '2025-05-15'), type: 'custom' })
		const [from, to] = wrapper.findAllComponents(NcDateTimePickerNative)

		await to!.vm.$emit('update:modelValue', new Date(2025, 4, 10))
		expect(lastEmitted(wrapper)).toEqual({ period: 'custom', from: '2025-05-01', to: '2025-05-10' })

		await from!.vm.$emit('update:modelValue', new Date(2025, 5, 3))
		expect(lastEmitted(wrapper)).toEqual({ period: 'custom', from: '2025-06-03', to: '2025-06-03' })
	})
})
