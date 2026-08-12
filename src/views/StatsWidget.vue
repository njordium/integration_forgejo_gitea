<template>
	<div class="fgw-stats">
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
import { generateUrl } from '@nextcloud/router'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

export default {
	name: 'StatsWidget',
	components: { NcLoadingIcon },
	data() {
		return {
			loading: true,
			error: '',
			notConnected: false,
			rawTiles: [],
			userName: '',
			instanceUrl: '',
			instanceType: 'forgejo',
		}
	},
	computed: {
		tiles() {
			return this.rawTiles.map(t => ({
				...t,
				url: this.linkForTile(t.key),
			}))
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
				const response = await axios.get(generateUrl('/apps/integration_forgejo_gitea/stats'))
				this.rawTiles = response.data.tiles || []
				this.userName = response.data.user_name || ''
				this.instanceUrl = response.data.instance_url || ''
				this.instanceType = response.data.instance_type || 'forgejo'
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
			if (!this.instanceUrl) return null
			switch (key) {
			case 'open_assigned_issues':
				return `${this.instanceUrl}/issues?state=open&type=your_repositories&assignee=${encodeURIComponent(this.userName)}`
			case 'open_created_issues':
				return `${this.instanceUrl}/issues?state=open&type=created_by`
			case 'open_assigned_prs':
				return `${this.instanceUrl}/pulls?state=open&type=your_repositories&assignee=${encodeURIComponent(this.userName)}`
			case 'open_created_prs':
				return `${this.instanceUrl}/pulls?state=open&type=created_by`
			case 'mentioned_open':
				return `${this.instanceUrl}/issues?state=open&type=mentioned`
			case 'contributions_7d':
				return `${this.instanceUrl}/${encodeURIComponent(this.userName)}`
			default:
				return this.instanceUrl
			}
		},
		formatValue(v) {
			if (v >= 50) return '50+'
			return String(v)
		},
	},
}
</script>

<style scoped lang="scss">
.fgw-stats {
	padding: 4px 0;
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

.fgw-tile__label {
	margin-top: 6px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	text-align: center;
	line-height: 1.2;
}
</style>
