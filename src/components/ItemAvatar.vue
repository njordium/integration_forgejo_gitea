<template>
	<span class="fgw-avatar" :title="login">
		<img v-if="url" :src="url" :alt="login" class="fgw-avatar__img" />
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
	computed: {
		initial() {
			return (this.login || '?').substring(0, 1).toUpperCase()
		},
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
	background: var(--color-primary-element-light, var(--color-background-hover));
	color: var(--color-primary-element-text, var(--color-main-text));
	font-size: 11px;
	font-weight: 600;
	flex-shrink: 0;
}
.fgw-avatar__img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
.fgw-avatar__initial {
	line-height: 1;
}
</style>
