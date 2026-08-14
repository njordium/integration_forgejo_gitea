<?php
declare(strict_types=1);

namespace OCA\ForgejoGitea\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

use OCA\ForgejoGitea\AppInfo\Application;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		// Never re-serialise the client_secret into the DOM on page load —
		// it widens the blast radius of any admin-scoped XSS or malicious
		// browser extension. Send only a boolean "is set", and require an
		// explicit new value on save if the admin wants to rotate it.
		$this->initialStateService->provideInitialState('admin-config', [
			'oauth_instance_url' => $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'),
			'client_id' => $this->config->getAppValue(Application::APP_ID, 'client_id'),
			'client_secret_set' => ($this->config->getAppValue(Application::APP_ID, 'client_secret', '') !== ''),
			'instance_type_default' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
			'redirect_uri' => $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.config.oauthRedirect'),
		]);
		return new TemplateResponse(Application::APP_ID, 'adminSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
