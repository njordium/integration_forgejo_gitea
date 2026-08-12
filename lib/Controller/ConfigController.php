<?php
declare(strict_types=1);

/**
 * Nextcloud - ForgejoGitea integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;

use OCA\ForgejoGitea\AppInfo\Application;
use OCA\ForgejoGitea\Service\ForgejoGiteaAPIService;
use OCA\ForgejoGitea\Service\TokenStorage;

class ConfigController extends Controller {

	private const SESSION_STATE_KEY = 'forgejo_gitea_oauth_state';

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ForgejoGiteaAPIService $api,
		private TokenStorage $tokens,
		private IURLGenerator $urlGenerator,
		private ISession $session,
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
			$stored = is_array($value) ? json_encode($value) : (string) $value;
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $stored);
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
	 * Store admin config values.
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, (string) $value);
		}
		return new DataResponse(1);
	}

	/**
	 * Start the OAuth authorization-code flow. Generates and stores a CSRF
	 * state token in the user's session, then returns the authorize URL the
	 * frontend should navigate to.
	 * @NoAdminRequired
	 */
	public function oauthStart(): DataResponse {
		$instanceUrl = rtrim($this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'), '/');
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		if ($instanceUrl === '' || $clientId === '') {
			return new DataResponse(['error' => 'admin_not_configured'], 400);
		}

		$state = bin2hex(random_bytes(32));
		$this->session->set(self::SESSION_STATE_KEY, $state);

		$redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.config.oauthRedirect');

		$authorizeUrl = $instanceUrl . '/login/oauth/authorize?' . http_build_query([
			'client_id' => $clientId,
			'response_type' => 'code',
			'state' => $state,
			'redirect_uri' => $redirectUri,
		]);

		return new DataResponse(['authorize_url' => $authorizeUrl]);
	}

	/**
	 * OAuth authorization-code callback. Verifies state, exchanges the code
	 * for tokens, resolves and stores the connected user's login, then
	 * redirects back to Personal Settings with a flash query param.
	 * @NoAdminRequired
	 */
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$targetBase = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);

		$expected = $this->session->get(self::SESSION_STATE_KEY);
		$this->session->remove(self::SESSION_STATE_KEY);

		if ($code === '' || $state === '' || !is_string($expected) || !hash_equals($expected, $state)) {
			return new RedirectResponse($targetBase . '?forgejo_gitea_error=invalid_state');
		}

		$instanceUrl = rtrim($this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'), '/');
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$redirectUri = $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.config.oauthRedirect');

		$result = $this->api->requestOAuthAccessToken($instanceUrl, [
			'grant_type' => 'authorization_code',
			'code' => $code,
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
			'redirect_uri' => $redirectUri,
		]);

		if (!isset($result['access_token'])) {
			return new RedirectResponse($targetBase . '?forgejo_gitea_error=token_exchange_failed');
		}

		$this->tokens->setAccessToken($this->userId, $result['access_token']);
		if (isset($result['refresh_token'])) {
			$this->tokens->setRefreshToken($this->userId, $result['refresh_token']);
		}

		$userInfo = $this->api->getUser($instanceUrl, $result['access_token']);
		if (isset($userInfo['login'])) {
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', (string) $userInfo['login']);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', (string) ($userInfo['id'] ?? ''));
		}

		return new RedirectResponse($targetBase . '?forgejo_gitea_connected=1');
	}
}
