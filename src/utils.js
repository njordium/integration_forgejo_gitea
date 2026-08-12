let mytimer = 0

/**
 * Returns a function that schedules `callback` to run after `ms` ms, cancelling
 * any previously scheduled invocation. Used to debounce typing in settings forms.
 *
 * @param {(...args: unknown[]) => unknown} callback The function to invoke after the delay.
 * @param {number} ms Delay in milliseconds.
 * @return {(...args: unknown[]) => void} A debounced wrapper that, when called, schedules `callback`.
 */
export function delay(callback, ms) {
	return function() {
		const context = this
		const args = arguments
		clearTimeout(mytimer)
		mytimer = setTimeout(function() {
			callback.apply(context, args)
		}, ms || 0)
	}
}
