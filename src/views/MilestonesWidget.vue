<template>
	<div class="fgw-widget">
		<div class="fgw-toolbar">
			<NcActions :force-menu="true">
				<NcActionButton @click="openSettings">
					<template #icon><CogIcon :size="20" /></template>
					{{ t('integration_forgejo_gitea', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="refresh">
					<template #icon><RefreshIcon :size="20" /></template>
					{{ t('integration_forgejo_gitea', 'Refresh') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="loading" class="fgw-status"><NcLoadingIcon :size="24" /></div>
		<div v-else-if="notConnected" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'Connect your account in Personal Settings first.') }}
		</div>
		<div v-else-if="error" class="fgw-status fgw-error">{{ error }}</div>
		<div v-else-if="!config.repos.length" class="fgw-status">
			<span>{{ t('integration_forgejo_gitea', 'No repositories selected.') }}</span>
			<NcButton variant="primary" @click="openSettings">
				<template #icon><CogIcon :size="16" /></template>
				{{ t('integration_forgejo_gitea', 'Choose repositories') }}
			</NcButton>
		</div>
		<div v-else-if="!items.length" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'No open milestones.') }}
		</div>
		<ul v-else class="fgw-list">
			<li v-for="m in visibleItems" :key="m.id" class="fgw-milestone">
				<a :href="m.html_url" target="_blank" rel="noopener" class="fgw-milestone__link">
					<div class="fgw-milestone__row">
						<span class="fgw-milestone__title">{{ m.title }}</span>
						<span class="fgw-milestone__percent">{{ m.percent }}%</span>
					</div>
					<div class="fgw-milestone__bar-track">
						<div class="fgw-milestone__bar-fill" :style="{ width: m.percent + '%' }"></div>
					</div>
					<div class="fgw-milestone__meta">
						<span class="fgw-milestone__repo">{{ m.repo_full_name }}</span>
						<span>{{ m.closed_issues }} / {{ m.total_issues }}</span>
						<span v-if="m.due_on">{{ formatDue(m.due_on) }}</span>
					</div>
				</a>
			</li>
		</ul>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ t('integration_forgejo_gitea', 'Milestones — settings') }}</h3>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Repositories') }}</h4>
					<div v-if="reposLoading" class="fgw-status"><NcLoadingIcon :size="20" /></div>
					<template v-else-if="allRepos.length">
						<NcSelect
							v-model="draftRepos"
							:options="repoOptions"
							:multiple="true"
							:close-on-select="false"
							:searchable="true"
							:placeholder="t('integration_forgejo_gitea', 'Type to search repositories…')"
							label="label"
							:reduce="opt => opt.value" />
						<p class="fgw-modal__hint">
							{{ n('integration_forgejo_gitea',
								'{count} repository selected of {total}',
								'{count} repositories selected of {total}',
								draftRepos.length,
								{ count: draftRepos.length, total: allRepos.length }) }}
						</p>
					</template>
					<p v-else class="fgw-modal__hint">
						{{ t('integration_forgejo_gitea', 'No repositories accessible with the current token.') }}
					</p>
				</section>
				<div class="fgw-modal__actions">
					<NcButton @click="closeSettings">{{ t('integration_forgejo_gitea', 'Cancel') }}</NcButton>
					<NcButton variant="primary" :disabled="saving" @click="saveSettings">
						<template #icon>
							<NcLoadingIcon v-if="saving" :size="16" />
							<ContentSaveIcon v-else :size="16" />
						</template>
						{{ t('integration_forgejo_gitea', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'

import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const MAX_VISIBLE = 5

export default {
	name: 'MilestonesWidget',
	components: {
		NcActions, NcActionButton, NcButton, NcLoadingIcon, NcModal, NcSelect,
		CogIcon, RefreshIcon, ContentSaveIcon,
	},
	setup() {
		const bridge = { fetchLater: () => null }
		useAutoRefresh(() => bridge.fetchLater())
		return { autoRefresh: bridge }
	},
	data() {
		return {
			loading: true, error: '', notConnected: false,
			items: [], config: { repos: [] }, instanceUrl: '',
			showSettings: false, draftRepos: [], allRepos: [], reposLoading: false, saving: false,
		}
	},
	computed: {
		visibleItems() { return this.items.slice(0, MAX_VISIBLE) },
		repoOptions() { return this.allRepos.map(r => ({ label: r.full_name, value: r.full_name })) },
	},
	mounted() {
		this.autoRefresh.fetchLater = () => this.fetch()
		this.fetch()
	},
	methods: {
		async fetch() {
			this.loading = true; this.error = ''; this.notConnected = false
			try {
				const r = await axios.get(generateUrl('/apps/integration_forgejo_gitea/milestones'))
				this.items = r.data.items || []
				this.config = r.data.config || { repos: [] }
				this.instanceUrl = r.data.instance_url || ''
			} catch (e) {
				if (e?.response?.status === 401) this.notConnected = true
				else this.error = t('integration_forgejo_gitea', 'Failed to load milestones.')
			} finally {
				this.loading = false
			}
		},
		refresh() { this.fetch() },
		async openSettings() {
			this.draftRepos = [...this.config.repos]
			this.showSettings = true
			await this.fetchRepos()
		},
		closeSettings() { this.showSettings = false },
		async fetchRepos() {
			this.reposLoading = true
			try {
				const r = await axios.get(generateUrl('/apps/integration_forgejo_gitea/repos'))
				this.allRepos = r.data.repos || []
			} catch (e) {
				showError(t('integration_forgejo_gitea', 'Failed to load repositories.'))
			} finally {
				this.reposLoading = false
			}
		},
		async saveSettings() {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: { milestones_widget_repos: this.draftRepos },
				})
				this.showSettings = false
				showSuccess(t('integration_forgejo_gitea', 'Widget settings saved.'))
				await this.fetch()
			} catch (e) {
				showError(t('integration_forgejo_gitea', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},
		formatDue(iso) {
			const m = moment(iso)
			const days = m.diff(moment(), 'days')
			if (days < 0) return t('integration_forgejo_gitea', 'overdue {days}d', { days: -days })
			if (days === 0) return t('integration_forgejo_gitea', 'due today')
			if (days <= 30) return t('integration_forgejo_gitea', 'in {days}d', { days })
			return m.format('MMM D')
		},
	},
}
</script>

<style scoped lang="scss">
.fgw-widget {
	position: relative; display: flex; flex-direction: column; gap: 8px;
	padding: 12px 0 4px; font-size: 13px; max-height: 480px; overflow: hidden;
}
.fgw-toolbar { position: absolute; top: -32px; right: 4px; z-index: 10; }
.fgw-status { display: flex; align-items: center; gap: 8px; padding: 12px 4px; color: var(--color-text-maxcontrast); flex-wrap: wrap; }
.fgw-error { color: var(--color-error); }
.fgw-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 10px; }
.fgw-milestone {
	border-radius: var(--border-radius);
	&:hover { background: var(--color-background-hover); }
}
.fgw-milestone__link { display: block; padding: 6px 8px; color: inherit; text-decoration: none; }
.fgw-milestone__row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; }
.fgw-milestone__title { font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; flex: 1; }
.fgw-milestone__percent { font-variant-numeric: tabular-nums; font-size: 12px; font-weight: 600; color: #F87A50; flex-shrink: 0; }
body.theme--dark .fgw-milestone__percent { color: #ffb37a; }
.fgw-milestone__bar-track { height: 6px; background: var(--color-background-hover); border-radius: 3px; margin-top: 4px; overflow: hidden; }
.fgw-milestone__bar-fill { height: 100%; background: linear-gradient(90deg, #F87A50, #f57c00); border-radius: 3px; transition: width 300ms ease; }
body.theme--dark .fgw-milestone__bar-fill { background: linear-gradient(90deg, #e0722e, #ffb37a); }
.fgw-milestone__meta { display: flex; gap: 8px; margin-top: 4px; font-size: 11px; color: var(--color-text-maxcontrast); flex-wrap: wrap; }
.fgw-milestone__repo { font-family: var(--font-face-monospace, monospace); }
.fgw-modal { padding: 20px 24px; display: flex; flex-direction: column; gap: 18px; width: min(560px, 90vw); max-height: 80vh; overflow-y: auto;
	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; }
	&__hint { color: var(--color-text-maxcontrast); margin: 8px 0 0; font-size: 12px; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}
</style>
