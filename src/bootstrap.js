import { translate, translatePlural } from '@nextcloud/l10n'

/**
 * Wires Nextcloud's translation helpers and the global OC/OCA bridge onto a Vue
 * application instance. Call once per `createApp(...)` before mounting.
 *
 * @param {import('vue').App} app The Vue application instance to enrich.
 */
export function applyGlobals(app) {
	app.config.globalProperties.t = translate
	app.config.globalProperties.n = translatePlural
	app.config.globalProperties.OC = window.OC
	app.config.globalProperties.OCA = window.OCA
}
