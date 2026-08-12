<?php
/**
 * Nextcloud - ForgejoGitea integration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

namespace OCA\ForgejoGitea\Controller;

use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Controller;

use OCA\ForgejoGitea\Service\ForgejoGiteaAPIService;
use OCA\ForgejoGitea\Service\TokenStorage;
use OCA\ForgejoGitea\AppInfo\Application;

class ConfigController extends Controller {

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ForgejoGiteaAPIService $forgejoGiteaAPIService,
		private TokenStorage $tokens,
		private IURLGenerator $urlGenerator,
		private IUserSession $userSession,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Store per-user config values.
	 * @NoAdminRequired
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$this->tokens->clear($this->userId);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'last_notification_check', '');
		}

		return new DataResponse([]);
	}

	/**
	 * Store admin config values (OAuth client id/secret, instance URL, instance type default).
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * OAuth authorization-code callback. Stub — implemented in Checkpoint 2.
	 * @NoAdminRequired
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$target = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
		return new RedirectResponse($target);
	}
}
