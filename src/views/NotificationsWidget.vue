<template>
	<div class="fgw-notifications">
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

		<div v-if="loading" class="fgw-status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="notConnected" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'Connect your account in Personal Settings first.') }}
		</div>
		<div v-else-if="error" class="fgw-status fgw-error">
			{{ error }}
		</div>
		<div v-else-if="!items.length" class="fgw-status">
			<CheckCircleOutlineIcon :size="40" class="fgw-status__icon" />
			<span>{{ t('integration_forgejo_gitea', 'You are up to date — no unread notifications.') }}</span>
		</div>
		<template v-else>
			<ul class="fgw-list">
				<li v-for="item in visibleItems" :key="item.id" class="fgw-item">
					<a
						:href="safeHref(item.html_url)"
						target="_blank"
						rel="noopener"
						class="fgw-item__link">
						<div class="fgw-item__row">
							<component :is="iconFor(item.type)" :size="16" class="fgw-item__type-icon" />
							<span class="fgw-item__title">{{ item.title || '(untitled)' }}</span>
						</div>
						<div class="fgw-item__meta">
							<span class="fgw-item__repo">{{ item.repo_full_name }}</span>
							<span class="fgw-item__updated">{{ formatUpdated(item.updated_at) }}</span>
						</div>
					</a>
					<NcButton
						variant="tertiary-no-background"
						:aria-label="t('integration_forgejo_gitea', 'Mark as read')"
						:title="t('integration_forgejo_gitea', 'Mark as read')"
						@click="markRead(item)">
						<template #icon>
							<CheckIcon :size="16" />
						</template>
					</NcButton>
				</li>
			</ul>
			<a
				v-if="hiddenCount > 0 && notificationsUrl"
				:href="notificationsUrl"
				target="_blank"
				rel="noopener"
				class="fgw-more">
				{{ t('integration_forgejo_gitea', 'Show all') }}
				<OpenInNewIcon :size="14" />
			</a>

			<NcModal v-if="showSettings" size="normal" @close="closeSettings">
				<div class="fgw-modal">
					<h3>{{ t('integration_forgejo_gitea', 'Notifications — settings') }}</h3>
					<section class="fgw-modal__section">
						<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
						<RefreshIntervalPicker v-model="draftRefreshSeconds" />
					</section>

					<section class="fgw-modal__section">
						<h4>{{ t('integration_forgejo_gitea', 'Records to show') }}</h4>
						<MaxItemsPicker v-model="draftMaxItems" />
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
		</template>
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
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import CheckCircleOutlineIcon from 'vue-material-design-icons/CheckCircleOutline.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import SourceCommitIcon from 'vue-material-design-icons/SourceCommit.vue'
import SourcePullIcon from 'vue-material-design-icons/SourcePull.vue'
import MaxItemsPicker from '../components/MaxItemsPicker.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'
import { safeHref } from '../utils.js'

export default {
	name: 'NotificationsWidget',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		NcModal,
		RefreshIcon,
		CheckCircleOutlineIcon,
		CogIcon,
		ContentSaveIcon,
		CheckIcon,
		MaxItemsPicker,
		RefreshIntervalPicker,
		AlertCircleIcon,
		SourcePullIcon,
		SourceCommitIcon,
		FolderIcon,
		BellIcon,
		OpenInNewIcon,
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
			instanceUrl: '',
			showSettings: false,
			draftRefreshSeconds: 300,
			refreshIntervalSeconds: 300,
			draftMaxItems: 20,
			maxItems: 20,
			saving: false,
		}
	},

	computed: {
		visibleItems() {
			return this.items.slice(0, this.maxItems)
		},

		hiddenCount() {
			return Math.max(0, this.items.length - this.maxItems)
		},

		notificationsUrl() {
			return this.instanceUrl ? `${this.instanceUrl}/notifications` : null
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
				const response = await axios.get(generateUrl('/apps/integration_forgejo_gitea/notifications'))
				this.items = response.data.items || []
				this.instanceUrl = response.data.instance_url || ''
				this.maxItems = Number(response.data.max_items ?? 20)
				const newInterval = Number(response.data.refresh_interval_seconds ?? 300)
				if (newInterval !== this.refreshIntervalSeconds) {
					this.refreshIntervalSeconds = newInterval
					this.autoRefresh.setIntervalMs(newInterval * 1000)
				}
			} catch (e) {
				if (e?.response?.status === 401) {
					this.notConnected = true
				} else {
					this.error = t('integration_forgejo_gitea', 'Failed to load notifications.')
				}
			} finally {
				this.loading = false
			}
		},

		openSettings() {
			this.draftRefreshSeconds = this.refreshIntervalSeconds
			this.draftMaxItems = this.maxItems
			this.showSettings = true
		},

		closeSettings() {
			this.showSettings = false
		},

		async saveSettings() {
			this.saving = true
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: {
						notifications_refresh_seconds: String(this.draftRefreshSeconds),
						notifications_max_items: String(this.draftMaxItems),
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

		async markRead(item) {
			try {
				await axios.patch(generateUrl('/apps/integration_forgejo_gitea/notifications/' + encodeURIComponent(item.id)))
				this.items = this.items.filter((i) => i.id !== item.id)
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to mark as read.'))
			}
		},

		iconFor(type) {
			switch (type) {
				case 'Issue': return 'AlertCircleIcon'
				case 'Pull': return 'SourcePullIcon'
				case 'Commit': return 'SourceCommitIcon'
				case 'Repository': return 'FolderIcon'
				default: return 'BellIcon'
			}
		},

		formatUpdated(iso) {
			if (!iso) { return '' }
			return moment(iso).fromNow()
		},
	},
}
</script>

<style scoped lang="scss">
.fgw-notifications {
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
	flex-direction: column;
	align-items: center;
	justify-content: center;
	gap: 12px;
	padding: 24px 4px;
	color: var(--color-text-maxcontrast);
	text-align: center;
}

.fgw-status__icon { opacity: 0.5; }

.fgw-error { color: var(--color-error); }

.fgw-list {
	list-style: none;
	padding: 0;
	margin: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
	max-height: 320px;
	overflow-y: auto;
}

.fgw-item {
	display: flex;
	align-items: flex-start;
	gap: 4px;
	border-radius: var(--border-radius);

	&:hover {
		background: var(--color-background-hover);
	}
}

.fgw-item__link {
	flex: 1;
	display: block;
	padding: 6px 8px;
	color: inherit;
	text-decoration: none;
	overflow: hidden;
}

.fgw-item__row {
	display: flex;
	align-items: center;
	gap: 6px;
}

.fgw-item__type-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
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
}

.fgw-item__repo {
	font-family: var(--font-face-monospace, monospace);
}

.fgw-more {
	display: flex;
	align-self: center;
	align-items: center;
	justify-content: center;
	gap: 4px;
	padding: 6px 12px;
	margin: 4px auto 0;
	width: fit-content;
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
	width: min(480px, 90vw);

	h3 { margin: 0; }
	h4 { margin: 0 0 8px; font-size: 14px; }
	&__section { display: flex; flex-direction: column; }
	&__actions { display: flex; justify-content: flex-end; gap: 8px; }
}
</style>
