<template>
	<span class="fgw-avatar" :title="login">
		<img v-if="url && !failed" :src="url" :alt="login" class="fgw-avatar__img" @error="failed = true" />
		<span v-else class="fgw-avatar__initial">{{ initial }}</span>
	</span>
</template>

<script>
export default {
	name: 'ItemAvatar',
	props: {
		url: { type: String, default: '' },
		login: { type: String, default: '' },
	},
	data() {
		return { failed: false }
	},
	computed: {
		initial() {
			return (this.login || '?').substring(0, 1).toUpperCase()
		},
	},
	watch: {
		url() { this.failed = false },
	},
}
</script>

<style scoped lang="scss">
.fgw-avatar {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border-radius: 50%;
	overflow: hidden;
	background: var(--color-primary-element, #0071BC);
	color: var(--color-primary-element-text, #ffffff);
	font-size: 11px;
	font-weight: 600;
	flex-shrink: 0;
}
.fgw-avatar__img {
	width: 100%;
	height: 100%;
	object-fit: cover;
	display: block;
}
.fgw-avatar__initial {
	line-height: 1;
}
</style>
