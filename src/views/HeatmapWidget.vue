<template>
	<div class="fgw-heatmap">
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
			<svg :viewBox="`0 0 ${width} ${height}`" preserveAspectRatio="xMidYMid meet" class="fgw-heatmap__svg">
				<g v-for="(label, i) in monthLabels" :key="'m' + i">
					<text
						:x="label.x"
						:y="MONTH_LABEL_Y"
						class="fgw-heatmap__month-label">
						{{ label.text }}
					</text>
				</g>
				<g v-for="label in weekdayLabels" :key="'d' + label.day">
					<text
						:x="WEEKDAY_LABEL_X"
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
			<div class="fgw-heatmap__footer">
				<span>{{ n('integration_forgejo_gitea',
					'{n} contribution in the last 12 months',
					'{n} contributions in the last 12 months',
					total, { n: total }) }}</span>
				<div class="fgw-heatmap__legend">
					<span class="fgw-heatmap__legend-label">{{ t('integration_forgejo_gitea', 'Less') }}</span>
					<span v-for="lvl in [0, 1, 2, 3, 4]" :key="lvl" :class="['fgw-heatmap__legend-cell', 'fgw-cell', 'fgw-cell--level-' + lvl]"></span>
					<span class="fgw-heatmap__legend-label">{{ t('integration_forgejo_gitea', 'More') }}</span>
				</div>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import moment from '@nextcloud/moment'

import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'

const CELL_SIZE = 10
const CELL_GAP = 2
const WEEKS = 53
const MONTH_LABEL_Y = 10
const WEEKDAY_LABEL_X = 0
const GRID_LEFT = 24
const GRID_TOP = 18

export default {
	name: 'HeatmapWidget',
	components: { NcLoadingIcon },
	data() {
		return {
			CELL_SIZE,
			MONTH_LABEL_Y,
			WEEKDAY_LABEL_X,
			loading: true,
			error: '',
			notConnected: false,
			points: [],
			total: 0,
			userName: '',
			instanceUrl: '',
			instanceType: 'forgejo',
		}
	},
	computed: {
		width() {
			return GRID_LEFT + WEEKS * (CELL_SIZE + CELL_GAP) + 4
		},
		height() {
			return GRID_TOP + 7 * (CELL_SIZE + CELL_GAP)
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
				const row = day.day() // 0 (Sun) .. 6 (Sat)
				const count = this.pointsByDay.get(day.unix()) || 0
				const level = this.levelFor(count)
				cells.push({
					key: day.unix(),
					x: GRID_LEFT + col * (CELL_SIZE + CELL_GAP),
					y: GRID_TOP + row * (CELL_SIZE + CELL_GAP),
					level,
					tooltip: `${day.format('LL')}: ${count} contribution${count === 1 ? '' : 's'}`,
				})
			}
			return cells
		},
		monthLabels() {
			// pick unique months from cells at row 0
			const seen = new Set()
			const labels = []
			for (const c of this.cells.filter(c => c.y === GRID_TOP)) {
				const date = moment.unix(c.key)
				const monthKey = date.format('YYYY-MM')
				if (!seen.has(monthKey)) {
					seen.add(monthKey)
					labels.push({ x: c.x, text: date.format('MMM') })
				}
			}
			return labels
		},
		weekdayLabels() {
			return [
				{ day: 1, y: GRID_TOP + 1 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 1, text: t('integration_forgejo_gitea', 'Mon') },
				{ day: 3, y: GRID_TOP + 3 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 1, text: t('integration_forgejo_gitea', 'Wed') },
				{ day: 5, y: GRID_TOP + 5 * (CELL_SIZE + CELL_GAP) + CELL_SIZE - 1, text: t('integration_forgejo_gitea', 'Fri') },
			]
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
				const response = await axios.get(generateUrl('/apps/integration_forgejo_gitea/heatmap'))
				this.points = response.data.points || []
				this.total = response.data.total || 0
				this.userName = response.data.user_name || ''
				this.instanceUrl = response.data.instance_url || ''
				this.instanceType = response.data.instance_type || 'forgejo'
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
	},
}
</script>

<style scoped lang="scss">
.fgw-heatmap {
	padding: 4px 0;
	font-size: 13px;
}

.fgw-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 12px 4px;
	color: var(--color-text-maxcontrast);
}

.fgw-error {
	color: var(--color-error);
}

.fgw-heatmap__svg {
	width: 100%;
	height: auto;
	display: block;
}

.fgw-heatmap__month-label,
.fgw-heatmap__day-label {
	fill: var(--color-text-maxcontrast);
	font-size: 9px;
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
	&--level-0 { fill: var(--color-background-dark, #22262e); }
}

/* Gitea green scale */
.fgw-heatmap__body[data-brand="gitea"] .fgw-cell {
	&--level-1 { fill: #c6e9c1; }
	&--level-2 { fill: #7fc47a; }
	&--level-3 { fill: #4a9942; }
	&--level-4 { fill: #2c6a25; }
}

.fgw-heatmap__footer {
	display: flex;
	justify-content: space-between;
	align-items: center;
	margin-top: 6px;
	font-size: 11px;
	color: var(--color-text-maxcontrast);
	flex-wrap: wrap;
	gap: 8px;
}

.fgw-heatmap__legend {
	display: inline-flex;
	align-items: center;
	gap: 3px;
}

.fgw-heatmap__legend-cell {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 2px;
}

.fgw-heatmap__legend-label {
	font-size: 10px;
}
</style>
