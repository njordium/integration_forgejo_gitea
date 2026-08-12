/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('forgejo_gitea_closed_issues', (el) => {
		el.textContent = 'Forgejo/Gitea Closed Issues — connect your account in Personal Settings.'
	})
})
