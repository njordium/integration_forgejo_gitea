<template>
	<div id="forgejo_gitea_prefs" class="section">
		<h2>
			<a class="icon icon-forgejo_gitea" />
			{{ t('integration_forgejo_gitea', 'Forgejo / Gitea integration') }}
		</h2>

		<p>
			{{ t('integration_forgejo_gitea', 'OAuth connect flow lands in the next release.') }}
		</p>
	</div>
</template>

<script>
import { loadState } from '@nextcloud/initial-state'

export default {
	name: 'PersonalSettings',
	data() {
		return {
			state: loadState('integration_forgejo_gitea', 'user-config', {}),
		}
	},
}
</script>

<style scoped lang="scss">
#forgejo_gitea_prefs {
	max-width: 700px;
}
</style>
