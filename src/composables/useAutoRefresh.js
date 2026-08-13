/**
 * @copyright Copyright (c) 2026 Njordium
 * @license AGPL-3.0-or-later
 *
 * Polling helper. Calls fetchFn on an interval and re-fetches on every
 * "the user is back looking at this tab" signal. Handles laptop sleep
 * (visibilitychange doesn't fire on wake when the tab was already the
 * frontmost tab before sleep), tab-focus, and back-forward cache restore.
 * setIntervalMs() changes the cadence (widget settings modal); an
 * interval of 0 disables the periodic poll — wake-signal refetches still
 * run so the widget is fresh whenever the user actually looks at it.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue'

const DEFAULT_INTERVAL_MS = 5 * 60 * 1000
// After a wake signal, if the last successful fetch is older than this,
// force an immediate refetch instead of waiting for the next timer tick.
// Set well under the default interval so a mid-interval wake still
// refreshes rather than showing stale data for up to 5 more minutes.
const STALE_AFTER_MS = 60 * 1000

/**
 * @param fetchFn function called on each timer tick / wake signal
 * @param initialIntervalMs starting cadence in milliseconds
 */
export function useAutoRefresh(fetchFn, initialIntervalMs = DEFAULT_INTERVAL_MS) {
	let timer = null
	let lastFetchAt = 0
	const currentMs = ref(initialIntervalMs)

	const stop = () => {
		if (timer !== null) {
			window.clearInterval(timer)
			timer = null
		}
	}

	const runFetch = () => {
		lastFetchAt = Date.now()
		fetchFn()
	}

	const start = () => {
		stop()
		// The widget's own mounted() fires an initial fetch outside this
		// composable; anchor lastFetchAt to "now" so a wake signal in the
		// first minute doesn't retrigger it.
		lastFetchAt = Date.now()
		const ms = currentMs.value
		if (ms > 0) {
			timer = window.setInterval(runFetch, ms)
		}
	}

	const setIntervalMs = (ms) => {
		const next = Number(ms) || 0
		if (currentMs.value === next) { return }
		currentMs.value = next
		start()
	}

	// Any signal that "the user is now looking at this tab" — visibility
	// flip, window focus, or bfcache restore — is a good moment to check
	// staleness and refetch if we've drifted past the threshold.
	const onWake = () => {
		if (document.visibilityState !== 'visible') { return }
		if (Date.now() - lastFetchAt >= STALE_AFTER_MS) {
			runFetch()
		}
	}

	const onVisibility = () => onWake()
	const onFocus = () => onWake()
	const onPageShow = () => onWake()

	onMounted(() => {
		start()
		document.addEventListener('visibilitychange', onVisibility)
		window.addEventListener('focus', onFocus)
		window.addEventListener('pageshow', onPageShow)
	})

	onBeforeUnmount(() => {
		stop()
		document.removeEventListener('visibilitychange', onVisibility)
		window.removeEventListener('focus', onFocus)
		window.removeEventListener('pageshow', onPageShow)
	})

	return { start, stop, setIntervalMs }
}
