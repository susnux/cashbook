/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest'
import { effectScope, nextTick, ref } from 'vue'
import { useInputChange } from './useInputChange.ts'

/**
 * Run the composable within an effect scope
 *
 * @param args - The arguments of the composable
 */
function setup(...args: Parameters<typeof useInputChange>) {
	const scope = effectScope()
	const rememberInput = scope.run(() => useInputChange(...args))!
	return { scope, rememberInput }
}

describe('useInputChange', () => {
	it('calls the callback when one of the inputs changes', async () => {
		const name = ref('')
		const amount = ref('')
		const onChange = vi.fn()
		setup([name, amount], onChange)

		name.value = 'Shop'
		await nextTick()
		amount.value = '5'
		await nextTick()

		expect(onChange).toHaveBeenCalledTimes(2)
	})

	it('watches objects deeply', async () => {
		const files = ref<Record<string, File>>({})
		const onChange = vi.fn()
		setup(files, onChange)

		files.value.cashbook = new File([], 'Kassenbuch.csv')
		await nextTick()
		delete files.value.cashbook
		await nextTick()

		expect(onChange).toHaveBeenCalledTimes(2)
	})

	it('checks if the input is still the remembered one', async () => {
		const name = ref('Shop')
		const { rememberInput } = setup(name, () => {})

		const isRememberedInput = rememberInput()
		expect(isRememberedInput()).toBe(true)

		name.value = 'Market'
		await nextTick()
		expect(isRememberedInput()).toBe(false)
		expect(rememberInput()()).toBe(true)
	})

	it('stops watching with its scope', async () => {
		const name = ref('')
		const onChange = vi.fn()
		const { scope } = setup(name, onChange)

		scope.stop()
		name.value = 'Shop'
		await nextTick()

		expect(onChange).not.toHaveBeenCalled()
	})
})
