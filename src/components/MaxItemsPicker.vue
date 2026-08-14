<template>
	<NcSelect
		:modelValue="selected"
		:options="options"
		:clearable="false"
		:searchable="false"
		label="label"
		class="fgw-max-items-picker"
		@update:modelValue="onChange" />
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'

export default {
	name: 'MaxItemsPicker',
	components: { NcSelect },
	props: {
		modelValue: { type: Number, default: 20 },
	},

	emits: ['update:modelValue'],
	computed: {
		options() {
			return [5, 10, 15, 20, 25, 50].map((n) => ({
				value: n,
				label: n === 1
					? t('integration_forgejo_gitea', '1 record')
					: t('integration_forgejo_gitea', '{n} records', { n }),
			}))
		},

		selected() {
			return this.options.find((o) => o.value === this.modelValue)
				|| this.options.find((o) => o.value === 20)
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
.fgw-max-items-picker {
	width: 100%;
}
</style>
