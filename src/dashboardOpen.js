/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

document.addEventListener('DOMContentLoaded', () => {
	OCA.Dashboard.register('forgejo_gitea_open_issues', (el) => {
		el.textContent = 'Forgejo/Gitea Open Issues — connect your account in Personal Settings.'
	})
})
