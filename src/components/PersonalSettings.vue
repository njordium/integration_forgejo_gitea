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

			<div v-if="connected" class="override">
				<label for="fgw-override-user">
					{{ t('integration_forgejo_gitea', 'Query as a different username') }}
				</label>
				<NcTextField
					id="fgw-override-user"
					v-model="overrideUserName"
					:placeholder="state.user_name"
					@input="onOverrideChange" />
				<p class="settings-hint">
					{{ t('integration_forgejo_gitea', 'Widgets filter Forgejo/Gitea data by this login (assigned to me, created by me, my heatmap, etc.). Leave empty to use the OAuth-connected login shown above. Set this when your OAuth account and your queryable account are different — e.g. bot / shared accounts, or an SSO login that differs from your Forgejo username.') }}
				</p>
			</div>
		</template>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import CheckCircleIcon from 'vue-material-design-icons/CheckCircle.vue'
import LoginIcon from 'vue-material-design-icons/Login.vue'
import LogoutIcon from 'vue-material-design-icons/Logout.vue'
import { delay } from '../utils.js'

export default {
	name: 'PersonalSettings',
	components: {
		NcButton,
		NcNoteCard,
		NcTextField,
		LoginIcon,
		LogoutIcon,
		CheckCircleIcon,
	},

	data() {
		const s = loadState('integration_forgejo_gitea', 'user-config', {})
		return {
			state: s,
			overrideUserName: s.override_user_name || '',
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
			} catch {
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

		onOverrideChange() {
			delay(this.saveOverride, 800)()
		},

		async saveOverride() {
			try {
				await axios.put(generateUrl('/apps/integration_forgejo_gitea/config'), {
					values: { override_user_name: this.overrideUserName.trim() },
				})
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to save username override.'))
			}
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

	.override {
		margin-top: 24px;
		padding-top: 16px;
		border-top: 1px solid var(--color-border);
		max-width: 480px;

		label {
			display: block;
			margin-bottom: 6px;
			font-weight: 500;
		}

		.settings-hint {
			margin-top: 8px;
			font-size: 12px;
			line-height: 1.4;
		}
	}
}
</style>
