<?php
declare(strict_types=1);

namespace OCA\ForgejoGitea\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\ForgejoGitea\AppInfo\Application;

class Personal implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
		private ?string $userId,
	) {
	}

	public function getForm(): TemplateResponse {
		$instanceUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$oauthConfigured = ($instanceUrl !== '' && $clientId !== '' && $clientSecret !== '');

		$this->initialStateService->provideInitialState('user-config', [
			'oauth_configured' => $oauthConfigured,
			'oauth_instance_url' => $instanceUrl,
			'instance_type_default' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
			'user_name' => $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name'),
		]);
		return new TemplateResponse(Application::APP_ID, 'personalSettings');
	}

	public function getSection(): string {
		return 'connected-accounts';
	}

	public function getPriority(): int {
		return 10;
	}
}
