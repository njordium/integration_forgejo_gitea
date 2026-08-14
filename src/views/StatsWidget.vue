<template>
	<div class="fgw-stats">
		<div class="fgw-toolbar">
			<NcActions :forceMenu="true">
				<NcActionButton @click="openSettings">
					<template #icon>
						<CogIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="fetch">
					<template #icon>
						<RefreshIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Refresh') }}
				</NcActionButton>
			</NcActions>
		</div>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ t('integration_forgejo_gitea', 'Overview — settings') }}</h3>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
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
		<div v-if="loading" class="fgw-status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="notConnected" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'Connect your account in Personal Settings first.') }}
		</div>
		<div v-else-if="error" class="fgw-status fgw-error">
			{{ error }}
		</div>
		<div v-else class="fgw-stats__grid" :data-brand="instanceType">
			<a
				v-for="tile in tiles"
				:key="tile.key"
				:href="tile.url"
				target="_blank"
				rel="noopener"
				class="fgw-tile">
				<div class="fgw-tile__value">
					{{ formatValue(tile.value) }}
				</div>
				<div class="fgw-tile__label">
					{{ t('integration_forgejo_gitea', tile.label) }}
				</div>
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { generateUrl } from '@nextcloud/router'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

export default {
	name: 'StatsWidget',
	components: { NcActions, NcActionButton, NcButton, NcLoadingIcon, NcModal, RefreshIcon, CogIcon, ContentSaveIcon, RefreshIntervalPicker },
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
			rawTiles: [],
			userName: '',
			instanceUrl: '',
			instanceType: 'forgejo',
			showSettings: false,
			draftRefreshSeconds: 300,
			refreshIntervalSeconds: 300,
			saving: false,
		}
	},

	computed: {
		tiles() {
			return this.rawTiles.map((t) => ({
				...t,
				url: this.linkForTile(t.key),
			}))
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
				const response = await axios.get(generateUrl('/apps/integration_forgejo_gitea/stats'))
				this.rawTiles = response.data.tiles || []
				this.userName = response.data.user_name || ''
				this.instanceUrl = response.data.instance_url || ''
				this.instanceType = response.data.instance_type || 'forgejo'
				const newInterval = Number(response.data.refresh_interval_seconds ?? 300)
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh.setIntervalMs(newInterval * 1000)
				}
			} catch (e) {
				if (e?.response?.status === 401) {
					this.notConnected = true
				} else {
					this.error = t('integration_forgejo_gitea', 'Failed to load stats.')
				}
			} finally {
				this.loading = false
			}
		},

		linkForTile(key) {
			if (!this.instanceUrl) { return null }
			switch (key) {
				case 'open_assigned_issues':
					return `${this.instanceUrl}/issues?state=open&type=assigned`
				case 'open_created_issues':
					return `${this.instanceUrl}/issues?state=open&type=created_by`
				case 'open_assigned_prs':
					return `${this.instanceUrl}/pulls?state=open&type=assigned`
				case 'open_created_prs':
					return `${this.instanceUrl}/pulls?state=open&type=created_by`
				case 'mentioned_open':
					return `${this.instanceUrl}/issues?state=open&type=mentioned`
				case 'contributions_7d':
					return `${this.instanceUrl}/${encodeURIComponent(this.userName)}?tab=activity`
				case 'total_open_issues':
					return `${this.instanceUrl}/issues?state=open&type=your_repositories`
				case 'total_closed_issues':
					return `${this.instanceUrl}/issues?state=closed&type=your_repositories`
				default:
					return this.instanceUrl
			}
		},

		formatValue(v) {
			return String(v ?? 0)
		},

		openSettings() {
			this.draftRefreshSeconds = this.refreshIntervalSeconds
			this.showSettings = true
		},

		closeSettings() {
			this.showSettings = false
		},

		async saveSettings() {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: { stats_refresh_seconds: String(this.draftRefreshSeconds) },
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
	},
}
</script>

<style scoped lang="scss">
.fgw-stats {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 4px 0;
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
}

.fgw-error { color: var(--color-error); }

.fgw-stats__grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 8px;
}

.fgw-tile {
	display: flex;
	flex-direction: column;
	justify-content: center;
	align-items: center;
	padding: 12px 8px;
	min-height: 68px;
	text-decoration: none;
	color: inherit;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
	transition: background 100ms;

	&:hover {
		background: var(--color-primary-element-light);
	}
}

.fgw-tile__value {
	font-size: 22px;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	color: var(--color-main-text);
	line-height: 1;
}

.fgw-stats__grid[data-brand="gitea"] .fgw-tile__value {
	color: #609926;
}

.fgw-stats__grid[data-brand="forgejo"] .fgw-tile__value,
.fgw-stats__grid:not([data-brand="gitea"]) .fgw-tile__value {
	color: #F87A50;
}

body.theme--dark .fgw-stats__grid[data-brand="gitea"] .fgw-tile__value {
	color: #a5e19f;
}

body.theme--dark .fgw-stats__grid[data-brand="forgejo"] .fgw-tile__value,
body.theme--dark .fgw-stats__grid:not([data-brand="gitea"]) .fgw-tile__value {
	color: #ffb37a;
}

.fgw-modal {
	padding: 20px 24px;
	display: flex;
	flex-direction: column;
	gap: 18px;
	width: min(480px, 90vw);

	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}

.fgw-tile__label {
	margin-top: 6px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	line-height: 1.2;
}
</style>
