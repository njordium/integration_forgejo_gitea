<?php
declare(strict_types=1);

/**
 * Nextcloud - ForgejoGitea integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserManager;

use OCA\ForgejoGitea\AppInfo\Application;
use OCA\ForgejoGitea\Service\ForgejoGiteaAPIService;
use OCA\ForgejoGitea\Service\TokenStorage;

class ConfigController extends Controller {

	private const SESSION_STATE_KEY = 'forgejo_gitea_oauth_state';

	/**
	 * Exact user-scoped keys the frontend is allowed to write via /config.
	 * Everything else is silently dropped — the OAuth `token` / `refresh_token`
	 * are set only by the callback handler, never by the client.
	 */
	private const ALLOWED_USER_KEYS = [
		'override_user_name',
		'user_name',
		'heatmap_window_weeks',
		'commits_widget_repos',
		'commits_widget_only_mine',
		'milestones_widget_repos',
		'repo_stats_widget_repos',
		'reviews_widget_repos',
	];

	/**
	 * Suffixes on per-widget keys (composed with a widget-specific prefix by
	 * the frontend). Composes with ALLOWED_USER_KEYS — a key passes if it is
	 * in the exact list OR ends with one of these suffixes.
	 */
	private const ALLOWED_USER_KEY_SUFFIXES = [
		'_widget_repos',
		'_widget_filter',
		'_pulls_widget_repos',
		'_pulls_widget_filter',
		'_refresh_seconds',
		'_max_items',
	];

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ForgejoGiteaAPIService $api,
		private TokenStorage $tokens,
		private IURLGenerator $urlGenerator,
		private ISession $session,
		private IUserManager $userManager,
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
			if (!$this->isAllowedUserKey($key)) {
				// Silent drop — do not signal to a caller which keys are
				// gated. Keeps forward-compat with older Vue bundles that
				// might still POST removed keys after an upgrade.
				continue;
			}
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
	 * Store admin config values. Validates the instance URL to prevent common
	 * misconfigurations (missing scheme, plain-HTTP for a real instance).
	 */
	public function setAdminConfig(array $values): DataResponse {
		$warnings = [];
		if (isset($values['oauth_instance_url'])) {
			$rawUrl = trim((string) $values['oauth_instance_url']);
			if ($rawUrl !== '') {
				$parsed = parse_url($rawUrl);
				if (!is_array($parsed) || !isset($parsed['scheme'], $parsed['host'])) {
					return new DataResponse(['error' => 'invalid_instance_url'], 400);
				}
				if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) {
					return new DataResponse(['error' => 'invalid_instance_url_scheme'], 400);
				}
				if (strtolower($parsed['scheme']) === 'http'
					&& !$this->isLoopbackHost($parsed['host'])) {
					$warnings[] = 'http_url_not_recommended';
				}
			}
		}

		// If the admin points the app at a different instance or rotates the
		// OAuth client, every stored per-user token is now for the WRONG
		// server. Sending it to the new host would leak working bearers for
		// each user to the new endpoint. Detect the change and clear all
		// tokens before writing the new admin values.
		$reconnectAllUsers = false;
		foreach (['oauth_instance_url', 'client_id'] as $sensitiveKey) {
			if (isset($values[$sensitiveKey])
				&& (string) $values[$sensitiveKey] !== $this->config->getAppValue(Application::APP_ID, $sensitiveKey, '')) {
				$reconnectAllUsers = true;
				break;
			}
		}

		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, (string) $value);
		}

		if ($reconnectAllUsers) {
			// IUserManager::callForAllUsers iterates every user on the
			// instance; we clear only those that actually had a Forgejo /
			// Gitea connection stored (non-empty user_name), so the O(n)
			// scan is cheap in write terms.
			$this->userManager->callForAllUsers(function ($user): void {
				$uid = $user->getUID();
				if ($this->config->getUserValue($uid, Application::APP_ID, 'user_name', '') === '') {
					return;
				}
				$this->tokens->clear($uid);
				$this->config->setUserValue($uid, Application::APP_ID, 'user_id', '');
				$this->config->setUserValue($uid, Application::APP_ID, 'user_name', '');
				$this->config->setUserValue($uid, Application::APP_ID, 'last_notification_check', '');
			});
			$warnings[] = 'users_reconnect_required';
		}

		return new DataResponse(['ok' => 1, 'warnings' => $warnings]);
	}

	/**
	 * Loopback hosts allowed to use plain HTTP without warning — dev setups
	 * running against local Forgejo/Gitea instances.
	 */
	private function isLoopbackHost(string $host): bool {
		$host = strtolower($host);
		return $host === 'localhost'
			|| $host === '127.0.0.1'
			|| $host === '::1'
			|| str_ends_with($host, '.localhost');
	}

	/**
	 * A user-scoped config key is writable via /config only when it is in the
	 * exact-match allowlist OR it ends with one of the widget-key suffixes.
	 * Blocks arbitrary keys (including `token` / `refresh_token`, which the
	 * OAuth callback writes directly via TokenStorage, never via /config).
	 */
	private function isAllowedUserKey(string $key): bool {
		if (in_array($key, self::ALLOWED_USER_KEYS, true)) {
			return true;
		}
		foreach (self::ALLOWED_USER_KEY_SUFFIXES as $suffix) {
			if (str_ends_with($key, $suffix)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Start the OAuth authorization-code flow. Generates and stores a CSRF
	 * state token in the user's session, then returns the authorize URL the
	 * frontend should navigate to.
	 * @NoAdminRequired
	 */
	#[BruteForceProtection(action: 'forgejoGiteaOauth')]
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
	 *
	 * External endpoint — Forgejo redirects the user's browser here, so
	 * there is no Nextcloud requesttoken to check against. State parameter
	 * from the OAuth spec provides equivalent CSRF protection.
	 *
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	#[BruteForceProtection(action: 'forgejoGiteaOauth')]
	public function oauthRedirect(string $code = '', string $state = ''): RedirectResponse {
		$targetBase = $this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);

		$expected = $this->session->get(self::SESSION_STATE_KEY);
		$this->session->remove(self::SESSION_STATE_KEY);

		if ($code === '' || $state === '' || !is_string($expected) || !hash_equals($expected, $state)) {
			$response = new RedirectResponse($targetBase . '?forgejo_gitea_error=invalid_state');
			$response->throttle(['action' => 'forgejoGiteaOauth']);
			return $response;
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
			$response = new RedirectResponse($targetBase . '?forgejo_gitea_error=token_exchange_failed');
			$response->throttle(['action' => 'forgejoGiteaOauth']);
			return $response;
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
