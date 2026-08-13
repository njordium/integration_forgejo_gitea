/**
 * @copyright Copyright (c) 2026 Njordium
 * @license AGPL-3.0-or-later
 *
 * Polling helper. Calls fetchFn on an interval, pauses while the tab is
 * hidden, refetches once when the tab becomes visible again. The interval
 * is dynamic — call setIntervalMs() to change it (widget settings modal
 * saves a new interval, the timer restarts). An interval of 0 disables
 * the periodic poll; visibility-change refetch still runs.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const DEFAULT_INTERVAL_MS = 5 * 60 * 1000

/**
 *
 * @param fetchFn
 * @param initialIntervalMs
 */
export function useAutoRefresh(fetchFn, initialIntervalMs = DEFAULT_INTERVAL_MS) {
	let timer = null
	const currentMs = ref(initialIntervalMs)

	const stop = () => {
		if (timer !== null) {
			window.clearInterval(timer)
			timer = null
		}
	}

	const start = () => {
		stop()
		const ms = currentMs.value
		if (ms > 0) {
			timer = window.setInterval(() => {
				if (document.visibilityState === 'visible') {
					fetchFn()
				}
			}, ms)
		}
	}

	const setIntervalMs = (ms) => {
		const next = Number(ms) || 0
		if (currentMs.value === next) { return }
		currentMs.value = next
		start()
	}

	const onVisibility = () => {
		if (document.visibilityState === 'visible') {
			fetchFn()
		}
	}

	onMounted(() => {
		start()
		document.addEventListener('visibilitychange', onVisibility)
	})

	onBeforeUnmount(() => {
		stop()
		document.removeEventListener('visibilitychange', onVisibility)
	})

	return { start, stop, setIntervalMs }
}
