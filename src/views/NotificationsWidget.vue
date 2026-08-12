<template>
	<div class="fgw-notifications">
		<div class="fgw-toolbar">
			<NcActions :force-menu="true">
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
			{{ t('integration_forgejo_gitea', 'You are up to date — no unread notifications.') }}
		</div>
		<template v-else>
			<ul class="fgw-list">
				<li v-for="item in visibleItems" :key="item.id" class="fgw-item">
					<a :href="item.html_url" target="_blank" rel="noopener" class="fgw-item__link">
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
				{{ n('integration_forgejo_gitea',
					'Show 1 more unread',
					'Show {n} more unread',
					hiddenCount,
					{ n: hiddenCount }) }}
				<OpenInNewIcon :size="14" />
			</a>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError } from '@nextcloud/dialogs'
import moment from '@nextcloud/moment'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import AlertCircleIcon from 'vue-material-design-icons/AlertCircle.vue'
import SourcePullIcon from 'vue-material-design-icons/SourcePull.vue'
import SourceCommitIcon from 'vue-material-design-icons/SourceCommit.vue'
import FolderIcon from 'vue-material-design-icons/Folder.vue'
import BellIcon from 'vue-material-design-icons/Bell.vue'
import OpenInNewIcon from 'vue-material-design-icons/OpenInNew.vue'

const MAX_VISIBLE_ITEMS = 7

export default {
	name: 'NotificationsWidget',
	components: {
		NcActions,
		NcActionButton,
		NcButton,
		NcLoadingIcon,
		RefreshIcon,
		CheckIcon,
		AlertCircleIcon,
		SourcePullIcon,
		SourceCommitIcon,
		FolderIcon,
		BellIcon,
		OpenInNewIcon,
	},
	data() {
		return {
			loading: true,
			error: '',
			notConnected: false,
			items: [],
			instanceUrl: '',
		}
	},
	computed: {
		visibleItems() {
			return this.items.slice(0, MAX_VISIBLE_ITEMS)
		},
		hiddenCount() {
			return Math.max(0, this.items.length - MAX_VISIBLE_ITEMS)
		},
		notificationsUrl() {
			return this.instanceUrl ? `${this.instanceUrl}/notifications` : null
		},
	},
	mounted() {
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
		async markRead(item) {
			try {
				await axios.patch(generateUrl('/apps/integration_forgejo_gitea/notifications/' + encodeURIComponent(item.id)))
				this.items = this.items.filter(i => i.id !== item.id)
			} catch (e) {
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
			if (!iso) return ''
			return moment(iso).fromNow()
		},
	},
}
</script>

<style scoped lang="scss">
.fgw-notifications {
	position: relative;
	padding: 4px 0;
	font-size: 13px;
	max-height: 480px;
	overflow: hidden;
}

.fgw-toolbar {
	position: absolute;
	top: -4px;
	right: 0;
}

.fgw-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 4px;
	color: var(--color-text-maxcontrast);
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
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 6px 8px;
	margin-top: 4px;
	color: var(--color-primary-element);
	text-decoration: none;
	font-size: 12px;

	&:hover {
		text-decoration: underline;
	}
}
</style>
