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

/**
 * Returns `url` only when it parses as an http(s) URL; otherwise returns null.
 * Guards `:href` bindings on values coming from the upstream Forgejo/Gitea
 * REST API (issue `html_url`, notification links, avatar URLs, etc.) —
 * Vue 3 does NOT sanitize the scheme on href attributes, so a compromised
 * or malicious upstream that returns `html_url: "javascript:…"` would
 * otherwise navigate the browser into arbitrary in-app script execution
 * on click.
 *
 * @param {unknown} url Candidate URL — usually a string from an API response.
 * @return {string|null} Safe absolute URL, or null when the input is not a string / not http(s).
 */
export function safeHref(url) {
	if (typeof url !== 'string' || url === '') { return null }
	try {
		const parsed = new URL(url, window.location.origin)
		if (parsed.protocol === 'http:' || parsed.protocol === 'https:') {
			return parsed.href
		}
		return null
	} catch {
		return null
	}
}
