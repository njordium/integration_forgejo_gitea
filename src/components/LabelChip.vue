<template>
	<span class="fgw-label-chip" :style="chipStyle" :title="name">{{ name }}</span>
</template>

<script>
function hexToRgb(hex) {
	if (!hex) return null
	const cleaned = hex.replace('#', '')
	if (cleaned.length !== 6) return null
	const r = parseInt(cleaned.slice(0, 2), 16)
	const g = parseInt(cleaned.slice(2, 4), 16)
	const b = parseInt(cleaned.slice(4, 6), 16)
	if (Number.isNaN(r) || Number.isNaN(g) || Number.isNaN(b)) return null
	return { r, g, b }
}

function luminance({ r, g, b }) {
	const to = c => {
		const v = c / 255
		return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
	}
	return 0.2126 * to(r) + 0.7152 * to(g) + 0.0722 * to(b)
}

export default {
	name: 'LabelChip',
	props: {
		name: { type: String, required: true },
		color: { type: String, default: '' },
	},
	computed: {
		chipStyle() {
			const rgb = hexToRgb(this.color)
			if (!rgb) return {}
			const bg = `#${this.color.replace('#', '')}`
			const fg = luminance(rgb) > 0.55 ? '#111' : '#fff'
			return { backgroundColor: bg, color: fg }
		},
	},
}
</script>

<style scoped lang="scss">
.fgw-label-chip {
	display: inline-block;
	padding: 1px 6px;
	border-radius: 10px;
	background: var(--color-background-hover);
	color: var(--color-main-text);
	font-size: 10px;
	line-height: 1.4;
	white-space: nowrap;
	max-width: 120px;
	overflow: hidden;
	text-overflow: ellipsis;
	vertical-align: middle;
}
</style>
