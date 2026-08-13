<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:clearable="false"
		:searchable="false"
		label="label"
		class="fgw-interval-picker"
		@update:modelValue="onChange" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'RefreshIntervalPicker',
	components: { NcSelect },
	props: {
		modelValue: { type: Number, default: 300 },
	},

	emits: ['update:modelValue'],
	computed: {
		options() {
			return [
				{ value: 0, label: t('integration_forgejo_gitea', 'Never (manual only)') },
				{ value: 30, label: t('integration_forgejo_gitea', 'Every 30 seconds') },
				{ value: 60, label: t('integration_forgejo_gitea', 'Every minute') },
				{ value: 300, label: t('integration_forgejo_gitea', 'Every 5 minutes') },
				{ value: 900, label: t('integration_forgejo_gitea', 'Every 15 minutes') },
				{ value: 1800, label: t('integration_forgejo_gitea', 'Every 30 minutes') },
				{ value: 3600, label: t('integration_forgejo_gitea', 'Every hour') },
			]
		},

		selected() {
			return this.options.find((o) => o.value === this.modelValue)
				|| this.options.find((o) => o.value === 300)
		},
	},

	methods: {
		onChange(v) {
			if (v && typeof v === 'object' && 'value' in v) {
				this.$emit('update:modelValue', v.value)
			}
		},
	},
}
</script>

<style scoped>
.fgw-interval-picker {
	width: 100%;
}
</style>
