/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 *
 * Single shared dashboard bundle. Registers every Forgejo/Gitea widget id.
 * All PHP widget classes point their load() at the same bundle name so this
 * file is loaded once regardless of how many widgets are on the dashboard.
 */
import { createApp } from 'vue'
import IssuesWidget from './views/IssuesWidget.vue'
import HeatmapWidget from './views/HeatmapWidget.vue'
import StatsWidget from './views/StatsWidget.vue'
import NotificationsWidget from './views/NotificationsWidget.vue'
import RecentCommitsWidget from './views/RecentCommitsWidget.vue'
import PendingReviewsWidget from './views/PendingReviewsWidget.vue'
import MilestonesWidget from './views/MilestonesWidget.vue'
import RepoStatsWidget from './views/RepoStatsWidget.vue'
import { applyGlobals } from './bootstrap.js'

const WIDGETS = [
	{ id: 'forgejo_gitea_open_issues', component: IssuesWidget, props: { state: 'open', itemType: 'issues' } },
	{ id: 'forgejo_gitea_closed_issues', component: IssuesWidget, props: { state: 'closed', itemType: 'issues' } },
	{ id: 'forgejo_gitea_open_prs', component: IssuesWidget, props: { state: 'open', itemType: 'pulls' } },
	{ id: 'forgejo_gitea_closed_prs', component: IssuesWidget, props: { state: 'closed', itemType: 'pulls' } },
	{ id: 'forgejo_gitea_heatmap', component: HeatmapWidget, props: {} },
	{ id: 'forgejo_gitea_stats', component: StatsWidget, props: {} },
	{ id: 'forgejo_gitea_notifications', component: NotificationsWidget, props: {} },
	{ id: 'forgejo_gitea_commits', component: RecentCommitsWidget, props: {} },
	{ id: 'forgejo_gitea_pending_reviews', component: PendingReviewsWidget, props: {} },
	{ id: 'forgejo_gitea_milestones', component: MilestonesWidget, props: {} },
	{ id: 'forgejo_gitea_repo_stats', component: RepoStatsWidget, props: {} },
]

document.addEventListener('DOMContentLoaded', () => {
	for (const w of WIDGETS) {
		OCA.Dashboard.register(w.id, (el) => {
			const app = createApp(w.component, w.props)
			applyGlobals(app)
			app.mount(el)
		})
	}
})
