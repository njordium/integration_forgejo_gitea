<template>
	<div class="fgw-heatmap">
		<div class="fgw-toolbar">
			<NcActions :force-menu="true">
				<NcActionButton @click="openSettings">
					<template #icon><CogIcon :size="20" /></template>
					{{ t('integration_forgejo_gitea', 'Widget settings') }}
				</NcActionButton>
				<NcActionButton @click="fetch">
					<template #icon><RefreshIcon :size="20" /></template>
					{{ t('integration_forgejo_gitea', 'Refresh') }}
				</NcActionButton>
			</NcActions>
		</div>

		<NcModal v-if="showSettings" size="normal" @close="closeSettings">
			<div class="fgw-modal">
				<h3>{{ t('integration_forgejo_gitea', 'Activity heatmap — settings') }}</h3>
				<section class="fgw-modal__section">
					<h4>{{ t('integration_forgejo_gitea', 'Refresh frequency') }}</h4>
					<RefreshIntervalPicker v-model="draftRefreshSeconds" />
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
		<div v-if="loading" class="fgw-status">
			<NcLoadingIcon :size="24" />
		</div>
		<div v-else-if="notConnected" class="fgw-status">
			{{ t('integration_forgejo_gitea', 'Connect your account in Personal Settings first.') }}
		</div>
		<div v-else-if="error" class="fgw-status fgw-error">
			{{ error }}
		</div>
		<div v-else class="fgw-heatmap__body" :data-brand="instanceType">
			<svg
				:viewBox="`0 0 ${SVG_WIDTH} ${SVG_HEIGHT}`"
				preserveAspectRatio="xMidYMid meet"
				class="fgw-heatmap__svg">
				<g v-for="label in monthLabels" :key="'m' + label.key">
					<text
						:x="label.x"
						y="10"
						class="fgw-heatmap__month-label">
						{{ label.text }}
					</text>
				</g>
				<g v-for="label in weekdayLabels" :key="'d' + label.day">
					<text
						x="0"
						:y="label.y"
						class="fgw-heatmap__day-label">
						{{ label.text }}
					</text>
				</g>
				<g v-for="cell in cells" :key="cell.key">
					<rect
						:x="cell.x"
						:y="cell.y"
						:width="CELL_SIZE"
						:height="CELL_SIZE"
						rx="2"
						ry="2"
						:class="'fgw-cell fgw-cell--level-' + cell.level">
						<title>{{ cell.tooltip }}</title>
					</rect>
				</g>
			</svg>

			<div class="fgw-stats-grid">
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ total }}</div>
					<div class="fgw-stat__label">{{ t('integration_forgejo_gitea', 'Total 12 months') }}</div>
				</div>
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ contribsThisWeek }}</div>
					<div class="fgw-stat__label">{{ t('integration_forgejo_gitea', 'This week') }}</div>
				</div>
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ contribsThisMonth }}</div>
					<div class="fgw-stat__label">{{ t('integration_forgejo_gitea', 'This month') }}</div>
				</div>
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ currentStreak }}</div>
					<div class="fgw-stat__label">{{ t('integration_forgejo_gitea', 'Current streak (days)') }}</div>
				</div>
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ longestStreak }}</div>
					<div class="fgw-stat__label">{{ t('integration_forgejo_gitea', 'Longest streak') }}</div>
				</div>
				<div class="fgw-stat">
					<div class="fgw-stat__value">{{ bestDay.count }}</div>
					<div class="fgw-stat__label">
						{{ bestDay.count > 0
							? t('integration_forgejo_gitea', 'Best day ({date})', { date: bestDay.date })
							: t('integration_forgejo_gitea', 'Best day') }}
					</div>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import moment from '@nextcloud/moment'

import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import RefreshIcon from 'vue-material-design-icons/Refresh.vue'
import CogIcon from 'vue-material-design-icons/Cog.vue'
import ContentSaveIcon from 'vue-material-design-icons/ContentSave.vue'
import RefreshIntervalPicker from '../components/RefreshIntervalPicker.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { useAutoRefresh } from '../composables/useAutoRefresh.js'

const CELL_SIZE = 26
const CELL_GAP = 4
const GRID_LEFT = 32
const GRID_TOP = 18
const WEEKS = 13  // ~3 months so cells stay large and readable in the dashboard card

export default {
	name: 'HeatmapWidget',
	components: { NcActions, NcActionButton, NcButton, NcLoadingIcon, NcModal, RefreshIcon, CogIcon, ContentSaveIcon, RefreshIntervalPicker },
	setup() {
		const bridge = { fetchLater: () => null }
		useAutoRefresh(() => bridge.fetchLater())
		return { autoRefresh: bridge }
	},
	data() {
		return {
			CELL_SIZE,
			loading: true,
			error: '',
			notConnected: false,
			points: [],
			total: 0,
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
		SVG_WIDTH() {
			return GRID_LEFT + WEEKS * (CELL_SIZE + CELL_GAP) + 4
		},
		SVG_HEIGHT() {
			return GRID_TOP + 7 * (CELL_SIZE + CELL_GAP) + 4
		},
		pointsByDay() {
			const map = new Map()
			for (const p of this.points) {
				const day = moment.unix(p.ts).startOf('day').unix()
				map.set(day, (map.get(day) || 0) + p.count)
			}
			return map
		},
		cells() {
			const cells = []
			const end = moment().startOf('day')
			const totalDays = WEEKS * 7
			for (let i = totalDays - 1; i >= 0; i--) {
				const day = end.clone().subtract(i, 'days')
				const dayIndex = totalDays - 1 - i
				const col = Math.floor(dayIndex / 7)
				const row = day.day()
				const count = this.pointsByDay.get(day.unix()) || 0
				cells.push({
					key: day.unix(),
					x: GRID_LEFT + col * (CELL_SIZE + CELL_GAP),
					y: GRID_TOP + row * (CELL_SIZE + CELL_GAP),
					level: this.levelFor(count),
					tooltip: `${day.format('LL')}: ${count} contribution${count === 1 ? '' : 's'}`,
					count,
					day: day.clone(),
				})
			}
			return cells
		},
		monthLabels() {
			const seen = new Set()
			const labels = []
			for (const c of this.cells.filter(c => c.y === GRID_TOP)) {
				const date = moment.unix(c.key)
				const monthKey = date.format('YYYY-MM')
				if (!seen.has(monthKey)) {
					seen.add(monthKey)
					labels.push({ key: monthKey, x: c.x, text: date.format('MMM') })
				}
			}
			return labels
		},
		weekdayLabels() {
			return [
				{ day: 1, y: GRID_TOP + 1 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 2, text: t('integration_forgejo_gitea', 'Mon') },
				{ day: 3, y: GRID_TOP + 3 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 2, text: t('integration_forgejo_gitea', 'Wed') },
				{ day: 5, y: GRID_TOP + 5 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 2, text: t('integration_forgejo_gitea', 'Fri') },
			]
		},
		contribsThisWeek() {
			const weekStart = moment().startOf('isoWeek').unix()
			let sum = 0
			for (const [ts, count] of this.pointsByDay) {
				if (ts >= weekStart) sum += count
			}
			return sum
		},
		contribsThisMonth() {
			const monthStart = moment().startOf('month').unix()
			let sum = 0
			for (const [ts, count] of this.pointsByDay) {
				if (ts >= monthStart) sum += count
			}
			return sum
		},
		currentStreak() {
			let streak = 0
			let day = moment().startOf('day')
			while ((this.pointsByDay.get(day.unix()) || 0) > 0) {
				streak++
				day = day.subtract(1, 'day')
			}
			return streak
		},
		longestStreak() {
			const sortedDays = Array.from(this.pointsByDay.keys()).sort((a, b) => a - b)
			let longest = 0
			let current = 0
			let prev = null
			for (const d of sortedDays) {
				if ((this.pointsByDay.get(d) || 0) <= 0) continue
				if (prev === null || d - prev === 86400) {
					current++
				} else {
					current = 1
				}
				if (current > longest) longest = current
				prev = d
			}
			return longest
		},
		bestDay() {
			let best = { count: 0, ts: 0 }
			for (const [ts, count] of this.pointsByDay) {
				if (count > best.count) best = { count, ts }
			}
			return {
				count: best.count,
				date: best.ts ? moment.unix(best.ts).format('MMM D') : '—',
			}
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
				const response = await axios.get(generateUrl('/apps/integration_forgejo_gitea/heatmap'))
				this.points = response.data.points || []
				this.total = response.data.total || 0
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
					this.error = t('integration_forgejo_gitea', 'Failed to load heatmap.')
				}
			} finally {
				this.loading = false
			}
		},
		levelFor(count) {
			if (count <= 0) return 0
			if (count <= 2) return 1
			if (count <= 5) return 2
			if (count <= 10) return 3
			return 4
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
					values: { heatmap_refresh_seconds: String(this.draftRefreshSeconds) },
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
	},
}
</script>

<style scoped lang="scss">
.fgw-heatmap {
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
}

.fgw-error { color: var(--color-error); }

.fgw-heatmap__svg {
	width: 100%;
	height: auto;
	display: block;
	margin-bottom: 12px;
}

.fgw-heatmap__month-label,
.fgw-heatmap__day-label {
	fill: var(--color-text-maxcontrast);
	font-size: 11px;
}

.fgw-cell {
	fill: var(--color-background-hover);

	&--level-0 { fill: var(--color-background-hover); }
	&--level-1 { fill: #ffe0b2; }
	&--level-2 { fill: #ffb74d; }
	&--level-3 { fill: #f57c00; }
	&--level-4 { fill: #bf360c; }
}

body.theme--dark .fgw-cell {
	&--level-0 { fill: rgba(255, 255, 255, 0.08); }
	&--level-1 { fill: #7a3d20; }
	&--level-2 { fill: #b0562a; }
	&--level-3 { fill: #e0722e; }
	&--level-4 { fill: #ffb37a; }
}

.fgw-heatmap__body[data-brand="gitea"] .fgw-cell {
	&--level-1 { fill: #c6e9c1; }
	&--level-2 { fill: #7fc47a; }
	&--level-3 { fill: #4a9942; }
	&--level-4 { fill: #2c6a25; }
}

body.theme--dark .fgw-heatmap__body[data-brand="gitea"] .fgw-cell {
	&--level-1 { fill: #234721; }
	&--level-2 { fill: #3e7a3a; }
	&--level-3 { fill: #6ab365; }
	&--level-4 { fill: #a5e19f; }
}

.fgw-stats-grid {
	display: grid;
	grid-template-columns: repeat(2, 1fr);
	gap: 8px;
	margin-bottom: 12px;
}

.fgw-stat {
	display: flex;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	padding: 8px 4px;
	background: var(--color-background-hover);
	border-radius: var(--border-radius);
}

.fgw-stat__value {
	font-size: 18px;
	font-weight: 600;
	font-variant-numeric: tabular-nums;
	line-height: 1;
	color: #F87A50;
}

body.theme--dark .fgw-stat__value {
	color: #ffb37a;
}

.fgw-heatmap__body[data-brand="gitea"] .fgw-stat__value {
	color: #609926;
}

body.theme--dark .fgw-heatmap__body[data-brand="gitea"] .fgw-stat__value {
	color: #a5e19f;
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

.fgw-stat__label {
	margin-top: 4px;
	font-size: 10px;
	text-align: center;
	color: var(--color-text-maxcontrast);
	line-height: 1.15;
}

</style>
