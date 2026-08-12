<template>
	<div id="forgejo_gitea_prefs" class="section">
		<h2>
			<a :class="iconClass" />
			{{ t('integration_forgejo_gitea', 'Forgejo / Gitea integration') }}
		</h2>

		<NcNoteCard v-if="!state.oauth_configured" type="warning">
			{{ t('integration_forgejo_gitea', 'No Forgejo/Gitea OAuth application is configured yet. Ask your Nextcloud administrator to set it up under Administration → Connected accounts.') }}
		</NcNoteCard>

		<template v-else>
			<p class="settings-hint">
				{{ t('integration_forgejo_gitea', 'Connected instance:') }}
				<code>{{ state.oauth_instance_url }}</code>
			</p>

			<div v-if="!connected" class="actions">
				<NcButton
					variant="primary"
					:disabled="loading"
					@click="onConnect">
					<template #icon>
						<LoginIcon :size="20" />
					</template>
					{{ connectLabel }}
				</NcButton>
			</div>

			<div v-else class="actions">
				<span class="connected">
					<CheckCircleIcon :size="20" class="connected-icon" />
					{{ t('integration_forgejo_gitea', 'Connected as {user}', { user: state.user_name }) }}
				</span>
				<NcButton variant="secondary" :disabled="loading" @click="onDisconnect">
					<template #icon>
						<LogoutIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Disconnect') }}
				</NcButton>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'

import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import LoginIcon from 'vue-material-design-icons/Login.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'

export default {
	name: 'PersonalSettings',
	components: {
		NcButton,
		NcNoteCard,
		LoginIcon,
		LogoutIcon,
		CheckCircleIcon,
	},
	data() {
		return {
			state: loadState('integration_forgejo_gitea', 'user-config', {}),
			loading: false,
		}
	},
	computed: {
		connected() {
			return !!this.state.user_name
		},
		instanceType() {
			return this.state.instance_type_default === 'gitea' ? 'gitea' : 'forgejo'
		},
		iconClass() {
			return 'icon icon-forgejo_gitea-' + this.instanceType
		},
		connectLabel() {
			const label = this.instanceType === 'gitea' ? 'Gitea' : 'Forgejo'
			return t('integration_forgejo_gitea', 'Connect to {label}', { label })
		},
	},
	mounted() {
		const params = new URLSearchParams(window.location.search)
		if (params.get('forgejo_gitea_connected') === '1') {
			showSuccess(t('integration_forgejo_gitea', 'Connected successfully.'))
			this.cleanQuery()
		}
		const err = params.get('forgejo_gitea_error')
		if (err) {
			showError(t('integration_forgejo_gitea', 'Connection failed: {reason}', { reason: err }))
			this.cleanQuery()
		}
	},
	methods: {
		async onConnect() {
			this.loading = true
			try {
				const response = await axios.post(generateUrl('/apps/integration_forgejo_gitea/oauth-start'))
				if (response.data?.authorize_url) {
					window.location.href = response.data.authorize_url
					return
				}
				showError(t('integration_forgejo_gitea', 'Could not start OAuth flow.'))
			} catch (e) {
				console.error(e)
				const msg = e?.response?.data?.error === 'admin_not_configured'
					? t('integration_forgejo_gitea', 'Admin OAuth application not configured.')
					: t('integration_forgejo_gitea', 'Could not start OAuth flow.')
				showError(msg)
			} finally {
				this.loading = false
			}
		},
		async onDisconnect() {
			this.loading = true
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: { user_name: '' },
				})
				this.state.user_name = ''
				showSuccess(t('integration_forgejo_gitea', 'Disconnected.'))
			} catch (e) {
				console.error(e)
				showError(t('integration_forgejo_gitea', 'Failed to disconnect.'))
			} finally {
				this.loading = false
			}
		},
		cleanQuery() {
			const url = new URL(window.location.href)
			url.searchParams.delete('forgejo_gitea_connected')
			url.searchParams.delete('forgejo_gitea_error')
			window.history.replaceState({}, '', url.toString())
		},
	},
}
</script>

<style scoped lang="scss">
#forgejo_gitea_prefs {
	max-width: 720px;

	h2 {
		display: flex;
		align-items: center;
		gap: 8px;

		a {
			display: inline-block;
			width: 24px;
			height: 24px;
			background-size: contain;
			background-repeat: no-repeat;
			background-position: center;
		}
	}

	.settings-hint {
		margin: 12px 0;
		color: var(--color-text-maxcontrast);

		code {
			padding: 2px 6px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);
			font-size: 13px;
		}
	}

	.actions {
		display: flex;
		align-items: center;
		gap: 12px;
		margin-top: 16px;
		flex-wrap: wrap;

		.connected {
			display: inline-flex;
			align-items: center;
			gap: 6px;
			font-weight: 500;

			.connected-icon {
				color: var(--color-success);
			}
		}
	}
}
</style>
