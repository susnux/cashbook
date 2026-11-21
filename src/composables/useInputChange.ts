/*!
 * SPDX-FileCopyrightText: 2026 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import type { WatchSource } from 'vue'

import { watch } from 'vue'

/**
 * Watch the input of a form, e.g. to clear its error as soon as the user changes the input.
 *
 * @param input - The input of the form, objects are watched deeply
 * @param onChange - Called whenever the input changes
 * @return Function to remember the current input when submitting the form.
 *   It returns a function that checks if the input is still the remembered one,
 *   so the result of a request is only shown if it belongs to the current input.
 */
export function useInputChange(input: WatchSource | WatchSource[], onChange: () => void): () => () => boolean {
	let revision = 0
	watch(input, () => {
		revision++
		onChange()
	}, { deep: true })

	return function rememberInput() {
		const remembered = revision
		return () => remembered === revision
	}
}
