<template>
	<div class="fgw-widget">
		<div class="fgw-toolbar">
			<NcActions :forceMenu="true">
				<NcActionButton @click="openSettings">
					<template #icon>
						<CogIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="refresh">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Refresh') }}
				</NcActionButton>
			</NcActions>
		</div>

		<div v-if="loading" class="fgw-status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="notConnected" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'Connect your account in Personal Settings first.') }}
		</div>
		<div v-else-if="error" class="fgw-status fgw-error">
			{{ error }}
		</div>
		<div v-else-if="!config.repos.length" class="fgw-status">
			<span>{{ t('integration_forgejo_gitea', 'No repositories selected.') }}</span>
			<NcButton variant="primary" @click="openSettings">
				<template #icon>
					<CogIcon :size="16" />
				</template>
				{{ t('integration_forgejo_gitea', 'Choose repositories') }}
			</NcButton>
		</div>
		<ul v-else class="fgw-list">
			<li v-for="r in visibleItems" :key="r.full_name" class="fgw-repo">
				<a
					:href="safeHref(r.html_url)"
					target="_blank"
					rel="noopener"
					class="fgw-repo__link">
					<div class="fgw-repo__title">{{ r.full_name }}</div>
					<div v-if="r.description" class="fgw-repo__desc">{{ r.description }}</div>
					<div class="fgw-repo__stats">
						<span title="Stars">★ {{ r.stars }}</span>
						<span title="Forks">⑂ {{ r.forks }}</span>
						<span title="Open issues">◍ {{ r.open_issues }}</span>
						<span title="Open pull requests">⇄ {{ r.open_pulls }}</span>
					</div>
					<div class="fgw-repo__meta">
						<span v-if="r.latest_release">{{ t('integration_forgejo_gitea', 'v {tag}', { tag: r.latest_release.tag_name }) }}</span>
						<span v-if="r.updated_at">{{ t('integration_forgejo_gitea', 'updated {when}', { when: formatUpdated(r.updated_at) }) }}</span>
					</div>
				</a>
			</li>
		</ul>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ t('integration_forgejo_gitea', 'Repository stats — settings') }}</h3>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
				</section>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Records to show') }}</h4>
					<MaxItemsPicker v-model="draftMaxItems" />
				</section>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Repositories') }}</h4>
					<div v-if="reposLoading" class="fgw-status">
						<NcLoadingIcon :size="20" />
					</div>
					<template v-else-if="allRepos.length">
						<NcSelect
							v-model="draftRepos"
							:options="repoOptions"
							:multiple="true"
							:closeOnSelect="false"
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
					<NcButton @click="closeSettings">
						{{ t('integration_forgejo_gitea', 'Cancel') }}
					</NcButton>
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
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import MaxItemsPicker from '../components/MaxItemsPicker.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'
import { safeHref } from '../utils.js'

export default {
	name: 'RepoStatsWidget',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		CogIcon,
		RefreshIcon,
		ContentSaveIcon,
		MaxItemsPicker,
		RefreshIntervalPicker,
	},

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge, safeHref }
	},

	data() {
		return {
			loading: true,
			error: '',
			notConnected: false,
			items: [],
			config: { repos: [] },
			instanceUrl: '',
			showSettings: false,
			draftRepos: [],
			draftRefreshSeconds: 300,
			refreshIntervalSeconds: 300,
			draftMaxItems: 20,
			maxItems: 20,
			allRepos: [],
			reposLoading: false,
			saving: false,
		}
	},

	computed: {
		visibleItems() { return this.items.slice(0, this.maxItems) },
		repoOptions() { return this.allRepos.map((r) => ({ label: r.full_name, value: r.full_name })) },
	},

	mounted() {
		this.autoRefresh.fetchLater = () => this.fetch()
		this.fetch()
	},

	methods: {
		async fetch() {
			this.loading = true
			this.error = ''
			this.notConnected = false
			try {
				const r = await axios.get(generateUrl('/apps/integration_forgejo_gitea/repo-stats'))
				this.items = r.data.items || []
				this.config = r.data.config || { repos: [] }
				this.instanceUrl = r.data.instance_url || ''
				this.maxItems = Number(this.config.max_items ?? 20)
				const newInterval = Number(this.config.refresh_interval_seconds ?? 300)
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh.setIntervalMs(newInterval * 1000)
				}
			} catch (e) {
				if (e?.response?.status === 401) {
					this.notConnected = true
				} else {
					this.error = t('integration_forgejo_gitea', 'Failed to load repository stats.')
				}
			} finally {
				this.loading = false
			}
		},

		refresh() { this.fetch() },
		async openSettings() {
			this.draftRepos = [...this.config.repos]
			this.draftRefreshSeconds = this.refreshIntervalSeconds
			this.draftMaxItems = this.maxItems
			this.showSettings = true
			await this.fetchRepos()
		},

		closeSettings() { this.showSettings = false },
		async fetchRepos() {
			this.reposLoading = true
			try {
				const r = await axios.get(generateUrl('/apps/integration_forgejo_gitea/repos'))
				this.allRepos = r.data.repos || []
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to load repositories.'))
			} finally {
				this.reposLoading = false
			}
		},

		async saveSettings() {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: {
						repo_stats_widget_repos: this.draftRepos,
						repo_stats_refresh_seconds: String(this.draftRefreshSeconds),
						repo_stats_max_items: String(this.draftMaxItems),
					},
				})
				this.showSettings = false
				showSuccess(t('integration_forgejo_gitea', 'Widget settings saved.'))
				await this.fetch()
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},

		formatUpdated(iso) { return iso ? moment(iso).fromNow() : '' },
	},
}
</script>

<style scoped lang="scss">
.fgw-widget {
	display: flex; flex-direction: column; gap: 6px;
	padding: 4px 0; font-size: 13px; max-height: 480px; overflow: hidden;
}

.fgw-toolbar {
	display: flex; justify-content: flex-end; align-items: center;
	min-height: 32px; margin-top: -8px; margin-bottom: -4px;
}

.fgw-status { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 12px; padding: 24px 4px; color: var(--color-text-maxcontrast); text-align: center; }

.fgw-status__icon { opacity: 0.5; }

.fgw-error { color: var(--color-error); }

.fgw-list { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; max-height: 320px; overflow-y: auto; }

.fgw-repo {
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	&:hover { background: var(--color-primary-element-light, var(--color-background-hover)); }
}

.fgw-repo__link { display: block; padding: 8px 10px; color: inherit; text-decoration: none; }

.fgw-repo__title { font-weight: 600; font-family: var(--font-face-monospace, monospace); font-size: 12px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.fgw-repo__desc { font-size: 11px; color: var(--color-text-maxcontrast); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.fgw-repo__stats { display: flex; gap: 12px; margin-top: 4px; font-size: 11px; font-variant-numeric: tabular-nums; }

.fgw-repo__meta { display: flex; gap: 8px; margin-top: 2px; font-size: 10px; color: var(--color-text-maxcontrast); }

.fgw-modal { padding: 20px 24px; display: flex; flex-direction: column; gap: 18px; width: min(560px, 90vw); max-height: 80vh; overflow-y: auto;
	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; }
	&__hint { color: var(--color-text-maxcontrast); margin: 8px 0 0; font-size: 12px; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}
</style>
