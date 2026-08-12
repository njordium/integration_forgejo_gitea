/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 *
 * Simple polling helper. Calls fetchFn on an interval, pauses while the tab
 * is hidden, and fetches once as soon as the tab becomes visible again.
 * Cleans up its own interval and listener on unmount.
 */
import { onBeforeUnmount, onMounted } from 'vue'

const DEFAULT_INTERVAL_MS = 5 * 60 * 1000

export function useAutoRefresh(fetchFn, intervalMs = DEFAULT_INTERVAL_MS) {
	let timer = null

	const start = () => {
		stop()
		timer = window.setInterval(() => {
			if (document.visibilityState === 'visible') {
				fetchFn()
			}
		}, intervalMs)
	}

	const stop = () => {
		if (timer !== null) {
			window.clearInterval(timer)
			timer = null
		}
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

	return { start, stop }
}
