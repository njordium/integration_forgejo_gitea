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
			<span>{{ t('integration_forgejo_gitea', 'Loading…') }}</span>
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
		<div v-else-if="!items.length" class="fgw-status">
			{{ config.only_mine
				? t('integration_forgejo_gitea', 'No commits by you in the selected repos.')
				: t('integration_forgejo_gitea', 'No recent commits in the selected repos.') }}
		</div>
		<ul v-else class="fgw-list">
			<li v-for="c in visibleItems" :key="c.sha_full" class="fgw-item">
				<a
					:href="c.html_url"
					target="_blank"
					rel="noopener"
					class="fgw-item__link">
					<div class="fgw-item__row">
						<Avatar :url="c.author.avatar_url" :login="c.author.login" />
						<code class="fgw-item__sha">{{ c.sha }}</code>
						<span class="fgw-item__title">{{ c.title }}</span>
					</div>
					<div class="fgw-item__meta">
						<span class="fgw-item__repo">{{ c.repo_full_name }}</span>
						<span class="fgw-item__updated">{{ formatUpdated(c.created_at) }}</span>
					</div>
				</a>
			</li>
		</ul>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ t('integration_forgejo_gitea', 'Recent commits — settings') }}</h3>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
				</section>

				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Show') }}</h4>
					<NcCheckboxRadioSwitch v-model="draftOnlyMine" type="switch">
						{{ t('integration_forgejo_gitea', 'Only commits authored by me') }}
					</NcCheckboxRadioSwitch>
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import Avatar from '../components/ItemAvatar.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const MAX_VISIBLE = 7

export default {
	name: 'RecentCommitsWidget',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcModal,
		NcSelect,
		CogIcon,
		RefreshIcon,
		ContentSaveIcon,
		Avatar,
		RefreshIntervalPicker,
	},

	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},

	data() {
		return {
			loading: true,
			error: '',
			notConnected: false,
			items: [],
			config: { repos: [], only_mine: true },
			instanceUrl: '',
			showSettings: false,
			draftRepos: [],
			draftOnlyMine: true,
			draftRefreshSeconds: 300,
			refreshIntervalSeconds: 300,
			allRepos: [],
			reposLoading: false,
			saving: false,
		}
	},

	computed: {
		visibleItems() { return this.items.slice(0, MAX_VISIBLE) },
		repoOptions() {
			return this.allRepos.map((r) => ({ label: r.full_name, value: r.full_name }))
		},
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
				const url = generateUrl('/apps/integration_forgejo_gitea/commits')
				const r = await axios.get(url)
				this.items = r.data.items || []
				this.config = r.data.config || { repos: [], only_mine: true }
				this.instanceUrl = r.data.instance_url || ''
				const newInterval = Number(this.config.refresh_interval_seconds ?? 300)
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh.setIntervalMs(newInterval * 1000)
				}
			} catch (e) {
				if (e?.response?.status === 401) {
					this.notConnected = true
				} else {
					this.error = t('integration_forgejo_gitea', 'Failed to load commits.')
				}
			} finally {
				this.loading = false
			}
		},

		refresh() { this.fetch() },
		async openSettings() {
			this.draftRepos = [...this.config.repos]
			this.draftOnlyMine = !!this.config.only_mine
			this.draftRefreshSeconds = this.refreshIntervalSeconds
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
						commits_widget_repos: this.draftRepos,
						commits_widget_only_mine: this.draftOnlyMine ? '1' : '0',
						commits_refresh_seconds: String(this.draftRefreshSeconds),
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
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
	font-size: 13px;
	max-height: 480px;
	overflow: hidden;
}

.fgw-toolbar {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	min-height: 32px;
	margin-top: -8px;
	margin-bottom: -4px;
}

.fgw-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 4px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
}

.fgw-error { color: var(--color-error); }

.fgw-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.fgw-item {
	border-radius: var(--border-radius);
	&:hover { background: var(--color-background-hover); }
}

.fgw-item__link {
	display: block;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
}

.fgw-item__row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.fgw-item__sha {
	font-family: var(--font-face-monospace, monospace);
	font-size: 11px;
	background: var(--color-background-hover);
	padding: 2px 6px;
	border-radius: 3px;
	color: var(--color-text-maxcontrast);
	flex-shrink: 0;
}

.fgw-item__title {
	font-weight: 500;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	flex: 1;
}

.fgw-item__meta {
	display: flex;
	gap: 8px;
	margin-top: 2px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
	padding-inline-start: 28px;
}

.fgw-item__repo {
	font-family: var(--font-face-monospace, monospace);
}

.fgw-modal {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
	width: min(560px, 90vw);
	max-height: 80vh;
	overflow-y: auto;

	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; }
	&__hint { color: var(--color-text-maxcontrast); margin: 8px 0 0; font-size: 12px; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}
</style>
