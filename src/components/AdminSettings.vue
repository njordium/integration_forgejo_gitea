<template>
	<div id="forgejo_gitea_prefs" class="section">
		<h2>
			<a :class="iconClass" />
			{{ t('integration_forgejo_gitea', 'Forgejo / Gitea integration') }}
		</h2>

		<p class="settings-hint">
			{{ t('integration_forgejo_gitea', 'Register an OAuth application on your Forgejo or Gitea instance (Site Administration → Applications) with the redirect URI shown below, then paste the resulting Client ID and Client Secret here.') }}
		</p>

		<div class="grid-form">
			<label for="instance-type">
				<span class="icon icon-rename" />
				{{ t('integration_forgejo_gitea', 'Instance type') }}
			</label>
			<NcSelect
				id="instance-type"
				v-model="instanceType"
				:options="instanceTypeOptions"
				:reduce="opt => opt.value"
				:clearable="false"
				inputId="instance-type-select"
				@input="onFieldChange" />

			<label for="oauth_instance_url">
				<span class="icon icon-link" />
				{{ t('integration_forgejo_gitea', 'Instance address') }}
			</label>
			<NcTextField
				id="oauth_instance_url"
				v-model="state.oauth_instance_url"
				:placeholder="instanceUrlPlaceholder"
				@input="onFieldChange" />

			<label for="client_id">
				<span class="icon icon-category-auth" />
				{{ t('integration_forgejo_gitea', 'OAuth client ID') }}
			</label>
			<NcTextField
				id="client_id"
				v-model="state.client_id"
				:placeholder="t('integration_forgejo_gitea', 'ID of your OAuth application')"
				@input="onFieldChange" />

			<label for="client_secret">
				<span class="icon icon-category-auth" />
				{{ t('integration_forgejo_gitea', 'OAuth client secret') }}
			</label>
			<NcPasswordField
				id="client_secret"
				v-model="state.client_secret"
				:placeholder="clientSecretPlaceholder"
				@input="onFieldChange" />

			<label>
				<span class="icon icon-external" />
				{{ t('integration_forgejo_gitea', 'Redirect URI') }}
			</label>
			<div class="redirect-uri">
				<code>{{ redirectUri }}</code>
				<NcButton variant="tertiary" @click="copyRedirect">
					<template #icon>
						<ContentCopyIcon :size="20" />
					</template>
					{{ t('integration_forgejo_gitea', 'Copy') }}
				</NcButton>
			</div>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { loadState } from '@nextcloud/initial-state'
import { generateUrl } from '@nextcloud/router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import { delay } from '../utils.js'

export default {
	name: 'AdminSettings',
	components: {
		NcTextField,
		NcPasswordField,
		NcSelect,
		NcButton,
		ContentCopyIcon,
	},

	data() {
		const initial = loadState('integration_forgejo_gitea', 'admin-config', {})
		return {
			state: {
				oauth_instance_url: initial.oauth_instance_url ?? '',
				client_id: initial.client_id ?? '',
				// Never seeded from the server — the initial-state payload
				// only carries a client_secret_set boolean now. An empty
				// string here means "leave stored secret unchanged on save."
				client_secret: '',
			},

			// True when a secret is already stored server-side; drives the
			// password-field placeholder so the admin sees this and does not
			// mistake a blank field for "no secret configured".
			clientSecretSet: !!initial.client_secret_set,

			instanceType: initial.instance_type_default ?? 'forgejo',
			instanceTypeOptions: [
				{ label: 'Forgejo', value: 'forgejo' },
				{ label: 'Gitea', value: 'gitea' },
			],

			redirectUri: initial.redirect_uri ?? '',
		}
	},

	computed: {
		iconClass() {
			return 'icon icon-forgejo_gitea-' + this.instanceType
		},

		instanceUrlPlaceholder() {
			return this.instanceType === 'gitea'
				? 'https://gitea.example.org'
				: 'https://git.example.org'
		},

		clientSecretPlaceholder() {
			return this.clientSecretSet
				? t('integration_forgejo_gitea', 'Leave empty to keep the stored secret')
				: t('integration_forgejo_gitea', 'Secret of your OAuth application')
		},
	},

	methods: {
		onFieldChange() {
			delay(this.saveConfig, 2000)()
		},

		async saveConfig() {
			const payload = {
				oauth_instance_url: (this.state.oauth_instance_url ?? '').replace(/\/+$/, ''),
				client_id: this.state.client_id ?? '',
				instance_type_default: this.instanceType,
			}
			// Only send client_secret when the admin actually typed a new
			// value — empty means "keep the stored one" (matches the field
			// placeholder). Prevents overwriting an existing secret with
			// blank on every unrelated save.
			if ((this.state.client_secret ?? '') !== '') {
				payload.client_secret = this.state.client_secret
			}
			try {
				const { data } = await axios.put(generateUrl('/apps/integration_forgejo_gitea/admin-config'), { values: payload })
				if (payload.client_secret !== undefined) {
					this.clientSecretSet = true
					this.state.client_secret = ''
				}
				if (Array.isArray(data?.warnings) && data.warnings.includes('users_reconnect_required')) {
					showSuccess(t('integration_forgejo_gitea', 'Admin settings saved. All connected users have been signed out and must reconnect.'))
				} else {
					showSuccess(t('integration_forgejo_gitea', 'Forgejo / Gitea admin settings saved'))
				}
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to save admin settings'))
			}
		},

		async copyRedirect() {
			try {
				await navigator.clipboard.writeText(this.redirectUri)
				showSuccess(t('integration_forgejo_gitea', 'Redirect URI copied'))
			} catch {
				showError(t('integration_forgejo_gitea', 'Failed to copy'))
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
		margin-bottom: 8px;

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
		margin: 8px 0 16px;
		color: var(--color-text-maxcontrast);
	}

	.grid-form {
		display: grid;
		grid-template-columns: max-content 1fr;
		column-gap: 12px;
		row-gap: 10px;
		align-items: center;

		label {
			display: flex;
			align-items: center;
			gap: 6px;
			white-space: nowrap;

			.icon {
				display: inline-block;
				width: 20px;
				height: 20px;
			}
		}
	}

	.redirect-uri {
		display: flex;
		align-items: center;
		gap: 8px;

		code {
			padding: 4px 8px;
			background: var(--color-background-hover);
			border-radius: var(--border-radius);
			font-size: 12px;
			word-break: break-all;
		}
	}
}
</style>
