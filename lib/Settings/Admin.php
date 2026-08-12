<?php
declare(strict_types=1);

namespace OCA\ForgejoGitea\Settings;

use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IConfig;
use OCP\Settings\ISettings;

use OCA\ForgejoGitea\AppInfo\Application;

class Admin implements ISettings {

	public function __construct(
		private IConfig $config,
		private IInitialState $initialStateService,
	) {
	}

	public function getForm(): TemplateResponse {
		$this->initialStateService->provideInitialState('admin-config', [
			'oauth_instance_url' => $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'),
			'client_id' => $this->config->getAppValue(Application::APP_ID, 'client_id'),
			'client_secret' => $this->config->getAppValue(Application::APP_ID, 'client_secret'),
			'instance_type_default' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
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
