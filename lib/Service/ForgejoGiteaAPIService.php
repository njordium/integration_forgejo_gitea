<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Service;

use Exception;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;
use Throwable;

use OCA\ForgejoGitea\AppInfo\Application;

/**
 * Thin HTTP wrapper for Forgejo & Gitea REST v1. Both expose identical
 * OAuth 2 authorization-code + refresh-token grants at /login/oauth/access_token
 * and identical API v1 endpoints under /api/v1/.
 */
class ForgejoGiteaAPIService {

	public function __construct(
		private IConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private TokenStorage $tokens,
	) {
	}

	/**
	 * Bearer-authenticated call to the instance's /api/v1/ tree.
	 * On 401 attempts a single refresh_token exchange and retries.
	 *
	 * @return array Decoded JSON or ['error' => string]
	 */
	public function request(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $endpoint,
		array $params = [],
		string $method = 'GET',
	): array {
		try {
			$url = rtrim($instanceUrl, '/') . '/api/v1/' . ltrim($endpoint, '/');
			$options = [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud Forgejo/Gitea integration',
				],
				'timeout' => 30,
			];

			if ($method === 'GET') {
				if (!empty($params)) {
					$url .= '?' . http_build_query($params);
				}
			} else {
				$options['json'] = $params;
			}

			$client = $this->clientService->newClient();
			$response = match ($method) {
				'GET' => $client->get($url, $options),
				'POST' => $client->post($url, $options),
				'PUT' => $client->put($url, $options),
				'PATCH' => $client->patch($url, $options),
				'DELETE' => $client->delete($url, $options),
				default => throw new Exception('Unsupported method: ' . $method),
			};

			$status = $response->getStatusCode();
			$body = (string) $response->getBody();

			if ($status === 401 && $userId !== '') {
				return $this->retryAfterRefresh($instanceUrl, $userId, $endpoint, $params, $method);
			}

			if ($status >= 400) {
				return ['error' => 'HTTP ' . $status . ': ' . substr($body, 0, 200)];
			}

			$decoded = json_decode($body, true);
			return is_array($decoded) ? $decoded : [];
		} catch (Throwable $e) {
			$this->logger->warning('Forgejo/Gitea request failed: ' . $e->getMessage(), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}

	private function retryAfterRefresh(
		string $instanceUrl,
		string $userId,
		string $endpoint,
		array $params,
		string $method,
	): array {
		$refreshToken = $this->tokens->getRefreshToken($userId);
		if ($refreshToken === '') {
			return ['error' => 'unauthorized'];
		}
		$clientId = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$result = $this->requestOAuthAccessToken($instanceUrl, [
			'grant_type' => 'refresh_token',
			'refresh_token' => $refreshToken,
			'client_id' => $clientId,
			'client_secret' => $clientSecret,
		]);
		if (!isset($result['access_token'])) {
			return ['error' => 'refresh_failed'];
		}
		$this->tokens->setAccessToken($userId, $result['access_token']);
		if (isset($result['refresh_token'])) {
			$this->tokens->setRefreshToken($userId, $result['refresh_token']);
		}
		return $this->request($instanceUrl, $result['access_token'], '', $endpoint, $params, $method);
	}

	/**
	 * POST to {instanceUrl}/login/oauth/access_token — used for both
	 * authorization_code and refresh_token grants.
	 */
	public function requestOAuthAccessToken(string $instanceUrl, array $params): array {
		try {
			$url = rtrim($instanceUrl, '/') . '/login/oauth/access_token';
			$client = $this->clientService->newClient();
			$response = $client->post($url, [
				'body' => $params,
				'headers' => [
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud Forgejo/Gitea integration',
				],
				'timeout' => 30,
			]);
			$body = (string) $response->getBody();
			$decoded = json_decode($body, true);
			return is_array($decoded) ? $decoded : ['error' => 'invalid_response'];
		} catch (Throwable $e) {
			$this->logger->warning('OAuth token exchange failed: ' . $e->getMessage(), ['exception' => $e]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * Fetches the currently authenticated user via /api/v1/user.
	 */
	public function getUser(string $instanceUrl, string $accessToken): array {
		return $this->request($instanceUrl, $accessToken, '', 'user');
	}

	/**
	 * All repositories the authenticated user can access — paginated,
	 * bounded to a sane cap so we don't loop forever on huge accounts.
	 *
	 * @return array<int, array{full_name: string, name: string, owner: array}>
	 */
	public function getUserRepos(string $instanceUrl, string $accessToken, string $userId, int $maxPages = 5): array {
		$out = [];
		for ($page = 1; $page <= $maxPages; $page++) {
			$batch = $this->request($instanceUrl, $accessToken, $userId, 'user/repos', [
				'page' => $page,
				'limit' => 50,
			]);
			if (isset($batch['error']) || !is_array($batch) || empty($batch)) {
				break;
			}
			foreach ($batch as $repo) {
				if (isset($repo['full_name'])) {
					$out[] = $repo;
				}
			}
			if (count($batch) < 50) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Issues (or pulls, controlled by type param) for a single repo.
	 * Params passed through verbatim: state, type, assigned_by, created_by,
	 * mentioned_by, limit, page…
	 */
	public function getRepoIssues(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/issues';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		if (isset($result['error'])) {
			return [];
		}
		return is_array($result) ? $result : [];
	}

	/**
	 * Cross-repo issue/pull search — one call, uses server-side scoping.
	 * Used for stats aggregation across all accessible repos.
	 */
	public function searchAllIssues(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'repos/issues/search', $params);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Contribution heatmap for the given user. Returns
	 * [{ timestamp: <unix>, contributions: N }, …].
	 */
	public function getHeatmap(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $username,
	): array {
		if ($username === '') {
			return [];
		}
		$result = $this->request(
			$instanceUrl,
			$accessToken,
			$userId,
			'users/' . rawurlencode($username) . '/heatmap',
		);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Notifications for the connected user. Params: status-types (unread|read|pinned),
	 * subject-type (Issue|Pull|Commit|Repository), page, limit.
	 */
	public function getNotifications(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): array {
		$result = $this->request($instanceUrl, $accessToken, $userId, 'notifications', $params);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Mark a notification thread as read. Uses PATCH /notifications/threads/{id}.
	 */
	public function markNotificationRead(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $threadId,
	): bool {
		$endpoint = 'notifications/threads/' . rawurlencode($threadId);
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, [], 'PATCH');
		return !isset($result['error']);
	}
}
