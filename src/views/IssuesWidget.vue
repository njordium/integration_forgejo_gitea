<template>
	<div class="fgw-widget">
		<div class="fgw-toolbar">
			<NcActions :force-menu="true">
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
				<template #icon><CogIcon :size="16" /></template>
				{{ t('integration_forgejo_gitea', 'Choose repositories') }}
			</NcButton>
		</div>

		<div v-else-if="!items.length" class="fgw-status">
			{{ emptyLabel }}
		</div>

		<template v-else>
			<ul class="fgw-list">
				<li v-for="item in visibleItems" :key="item.id" class="fgw-item">
					<a :href="item.html_url" target="_blank" rel="noopener" class="fgw-item__link">
						<div class="fgw-item__row">
							<Avatar :url="item.user.avatar_url" :login="item.user.login" />
							<span class="fgw-item__number">#{{ item.number }}</span>
							<span class="fgw-item__title">{{ item.title }}</span>
						</div>
						<div class="fgw-item__meta">
							<span class="fgw-item__repo">{{ item.repo_full_name }}</span>
							<LabelChip v-for="l in item.labels.slice(0, 3)" :key="l.name" :name="l.name" :color="l.color" />
							<span v-if="item.comments" class="fgw-item__comments">💬 {{ item.comments }}</span>
							<span class="fgw-item__updated">{{ formatUpdated(item.updated_at) }}</span>
						</div>
					</a>
				</li>
			</ul>
			<a v-if="showMoreLink"
				:href="showMoreLink"
				target="_blank"
				rel="noopener"
				class="fgw-more">
				{{ showMoreLabel }}
				<OpenInNewIcon :size="14" />
			</a>
		</template>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ settingsTitle }}</h3>

				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Show issues that are') }}</h4>
					<div class="fgw-radio-row">
						<NcCheckboxRadioSwitch
							v-for="opt in filterOptions"
							:key="opt.value"
							v-model="draftFilter"
							:value="opt.value"
							name="fgw-filter"
							type="radio">
							{{ opt.label }}
						</NcCheckboxRadioSwitch>
					</div>
				</section>

				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
				</section>

				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Repositories') }}</h4>
					<div v-if="reposLoading" class="fgw-status">
						<NcLoadingIcon :size="20" />
						<span>{{ t('integration_forgejo_gitea', 'Loading repositories…') }}</span>
					</div>
					<template v-else-if="allRepos.length">
						<NcSelect
							v-model="draftRepos"
							:options="repoOptions"
							:multiple="true"
							:close-on-select="false"
							:searchable="true"
							:clear-search-on-select="false"
							:placeholder="t('integration_forgejo_gitea', 'Type to search repositories…')"
							label="label"
							:reduce="opt => opt.value"
							class="fgw-repo-select" />
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
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

import Avatar from '../components/ItemAvatar.vue'
import LabelChip from '../components/LabelChip.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const MAX_VISIBLE_ITEMS = 4

const FILTERS = [
	{ value: 'assigned', labelKey: 'Assigned to me' },
	{ value: 'created', labelKey: 'Created by me' },
	{ value: 'mentioned', labelKey: 'Mentioning me' },
	{ value: 'all', labelKey: 'All issues in the selected repos' },
]

export default {
	name: 'IssuesWidget',
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
		OpenInNewIcon,
		Avatar,
		LabelChip,
		RefreshIntervalPicker,
	},
	setup() {
		const bridge = { fetchLater: () => null }
		const refresh = useAutoRefresh(() => bridge.fetchLater())
		Object.assign(bridge, refresh)
		return { autoRefresh: bridge }
	},
	props: {
		state: {
			type: String,
			required: true,
			validator: v => v === 'open' || v === 'closed',
		},
		itemType: {
			type: String,
			default: 'issues',
			validator: v => v === 'issues' || v === 'pulls',
		},
	},
	data() {
		return {
			loading: true,
			error: '',
			notConnected: false,
			items: [],
			config: { repos: [], filter: 'assigned' },
			instanceUrl: '',
			showSettings: false,
			draftRepos: [],
			draftFilter: 'assigned',
			allRepos: [],
			reposLoading: false,
			saving: false,
		}
	},
	computed: {
		filterOptions() {
			return FILTERS.map(f => ({
				value: f.value,
				label: t('integration_forgejo_gitea', f.labelKey),
			}))
		},
		repoOptions() {
			return this.allRepos.map(r => ({
				label: r.full_name,
				value: r.full_name,
				description: r.description || '',
			}))
		},
		itemLabel() {
			return this.itemType === 'pulls'
				? t('integration_forgejo_gitea', 'pull requests')
				: t('integration_forgejo_gitea', 'issues')
		},
		emptyLabel() {
			const opt = FILTERS.find(f => f.value === this.config.filter) || FILTERS[0]
			const template = this.state === 'open'
				? 'No open {kind} — {filter}.'
				: 'No closed {kind} — {filter}.'
			return t('integration_forgejo_gitea', template, {
				kind: this.itemLabel,
				filter: t('integration_forgejo_gitea', opt.labelKey).toLowerCase(),
			})
		},
		settingsTitle() {
			const kind = this.itemType === 'pulls'
				? (this.state === 'open' ? 'Open Pull Requests' : 'Closed Pull Requests')
				: (this.state === 'open' ? 'Open Issues' : 'Closed Issues')
			return t('integration_forgejo_gitea', '{kind} — settings', { kind: t('integration_forgejo_gitea', kind) })
		},
		visibleItems() {
			return this.items.slice(0, MAX_VISIBLE_ITEMS)
		},
		showMoreLink() {
			if (this.items.length <= MAX_VISIBLE_ITEMS || !this.instanceUrl) {
				return null
			}
			const kind = this.itemType === 'pulls' ? 'pulls' : 'issues'
			const type = this.itemType === 'pulls' ? 'pull' : 'issue'
			if (this.config.repos.length === 1) {
				return `${this.instanceUrl}/${this.config.repos[0]}/${kind}?state=${this.state}&type=${type}`
			}
			return `${this.instanceUrl}/issues?state=${this.state}&type=your_repositories`
		},
		showMoreLabel() {
			return t('integration_forgejo_gitea', 'Show all')
		},
	},
	mounted() {
		this.autoRefresh.fetchLater = () => this.fetchIssues()
		this.fetchIssues()
	},
	methods: {
		async fetchIssues() {
			this.loading = true
			this.error = ''
			this.notConnected = false
			try {
				const url = generateUrl('/apps/integration_forgejo_gitea/items?state=' + this.state + '&type=' + this.itemType)
				const response = await axios.get(url)
				this.items = response.data.items || []
				this.config = response.data.config || { repos: [], filter: 'assigned' }
				this.instanceUrl = response.data.instance_url || ''
				const newInterval = Number(this.config.refresh_interval_seconds ?? 300)
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh.setIntervalMs(newInterval * 1000)
				}
			} catch (e) {
				if (e?.response?.status === 401) {
					this.notConnected = true
				} else {
					this.error = e?.response?.data?.error || t('integration_forgejo_gitea', 'Failed to load items.')
				}
			} finally {
				this.loading = false
			}
		},
		refresh() {
			this.fetchIssues()
		},
		async openSettings() {
			this.draftRepos = [...this.config.repos]
			this.draftFilter = this.config.filter
			this.draftRefreshSeconds = this.refreshIntervalSeconds
			this.showSettings = true
			await this.fetchRepos()
		},
		closeSettings() {
			this.showSettings = false
		},
		async fetchRepos() {
			this.reposLoading = true
			try {
				const url = generateUrl('/apps/integration_forgejo_gitea/repos')
				const response = await axios.get(url)
				this.allRepos = response.data.repos || []
			} catch (e) {
				showError(t('integration_forgejo_gitea', 'Failed to load repositories.'))
			} finally {
				this.reposLoading = false
			}
		},
		async saveSettings() {
			this.saving = true
			try {
				const keyPrefix = this.itemType === 'pulls'
					? this.state + '_pulls_widget'
					: this.state + '_widget'
				// widget-key for refresh matches the read-side prefix in the controller
				const refreshKey = this.itemType === 'pulls'
					? this.state + '_pulls_widget_refresh_seconds'
					: this.state + '_widget_refresh_seconds'
				const values = {
					[keyPrefix + '_repos']: this.draftRepos,
					[keyPrefix + '_filter']: this.draftFilter,
					[refreshKey]: String(this.draftRefreshSeconds),
				}
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), { values })
				this.showSettings = false
				showSuccess(t('integration_forgejo_gitea', 'Widget settings saved.'))
				await this.fetchIssues()
			} catch (e) {
				showError(t('integration_forgejo_gitea', 'Failed to save widget settings.'))
			} finally {
				this.saving = false
			}
		},
		formatUpdated(iso) {
			if (!iso) return ''
			return moment(iso).fromNow()
		},
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

.fgw-error {
	color: var(--color-error);
}

.fgw-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.fgw-item {
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}
}

.fgw-item__link {
	display: block;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
}

.fgw-item__row {
	display: flex;
	align-items: baseline;
	gap: 6px;
}

.fgw-item__number {
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
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
	gap: 6px;
	margin-top: 2px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
	align-items: center;
	padding-left: 28px;
}

.fgw-item__repo {
	font-family: var(--font-face-monospace, monospace);
}

.fgw-more {
	display: inline-flex;
	align-self: center;
	align-items: center;
	gap: 4px;
	padding: 6px 12px;
	margin: 4px 0 0;
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 12px;
	border-radius: var(--border-radius);

	&:hover {
		text-decoration: underline;
		background: var(--color-background-hover);
	}
}

.fgw-modal {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
	width: min(560px, 90vw);
	max-height: 80vh;
	overflow-y: auto;

	h3 {
		margin: 0;
	}

	h4 {
		margin: 0 0 8px;
		font-size: 14px;
	}

	&__section {
		display: flex;
		flex-direction: column;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
		margin: 8px 0 0;
		font-size: 12px;
	}

	&__actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
	}
}

.fgw-radio-row {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.fgw-repo-select {
	width: 100%;
}
</style>
