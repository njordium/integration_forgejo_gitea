/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */
import { createApp } from 'vue'
import IssuesWidget from './views/IssuesWidget.vue'
import { applyGlobals } from './bootstrap.js'

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('forgejo_gitea_closed_issues', (el) => {
		const app = createApp(IssuesWidget, { state: 'closed' })
		applyGlobals(app)
		app.mount(el)
	})
})
