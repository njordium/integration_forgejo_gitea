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
				// Handle 4xx/5xx ourselves rather than have Guzzle throw — otherwise
				// the 401 → refresh path below is dead.
				'http_errors' => false,
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
				return $this->retryAfterRefresh($instanceUrl, $userId, $accessToken, $endpoint, $params, $method);
			}

			if ($status >= 400) {
				$this->logger->info('Forgejo/Gitea request returned non-2xx', [
					'endpoint' => $endpoint,
					'method' => $method,
					'status' => $status,
				]);
				return ['error' => 'upstream_' . $status];
			}

			$decoded = json_decode($body, true);
			return is_array($decoded) ? $decoded : [];
		} catch (Throwable $e) {
			// Log a redacted summary — never the exception object, which
			// Nextcloud's logger otherwise records with full request context
			// (including the Authorization: Bearer header).
			$this->logger->warning('Forgejo/Gitea request failed', [
				'endpoint' => $endpoint,
				'method' => $method,
				'reason' => $this->redactSecrets($e->getMessage(), $accessToken),
			]);
			return ['error' => 'request_failed'];
		}
	}

	private function retryAfterRefresh(
		string $instanceUrl,
		string $userId,
		string $staleAccessToken,
		string $endpoint,
		array $params,
		string $method,
	): array {
		// TOCTOU guard: the dashboard fires 8+ widget requests in parallel.
		// If the access token expired, all of them 401 and land here. Without
		// this check every one would race to POST /login/oauth/access_token
		// with the same refresh_token; Forgejo rotates refresh_tokens on use,
		// so only the winner succeeds and the losers get invalid_grant and
		// silently disconnect the user. Re-reading the current token first
		// lets losers pick up the winner's fresh token instead of racing.
		$currentAccess = $this->tokens->getAccessToken($userId);
		if ($currentAccess !== '' && $currentAccess !== $staleAccessToken) {
			return $this->request($instanceUrl, $currentAccess, $userId, $endpoint, $params, $method);
		}
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
		return $this->request($instanceUrl, $result['access_token'], $userId, $endpoint, $params, $method);
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
				'http_errors' => false,
			]);
			$body = (string) $response->getBody();
			$decoded = json_decode($body, true);
			return is_array($decoded) ? $decoded : ['error' => 'invalid_response'];
		} catch (Throwable $e) {
			$secret = (string) ($params['client_secret'] ?? '');
			$refreshToken = (string) ($params['refresh_token'] ?? '');
			$this->logger->warning('OAuth token exchange failed', [
				'reason' => $this->redactSecrets($e->getMessage(), $secret, $refreshToken),
			]);
			return ['error' => 'token_exchange_failed'];
		}
	}

	/**
	 * Replace known-secret substrings with a fixed marker so the redacted
	 * string is safe to write to the log. Empty secrets are ignored.
	 */
	private function redactSecrets(string $subject, string ...$secrets): string {
		foreach ($secrets as $s) {
			if ($s !== '') {
				$subject = str_replace($s, '[REDACTED]', $subject);
			}
		}
		return $subject;
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
	 * Uses /repos/search (not /user/repos) because /user/repos only lists
	 * repositories the user OWNS. When a user reads issues across an org
	 * they belong to via team/collaborator membership (typical for the
	 * dashboard use case), /user/repos returns empty and every widget's
	 * repo picker shows "No repositories accessible with the current
	 * token." — even though /repos/issues/search returns hundreds of
	 * matches for the same token. /repos/search returns everything the
	 * token can read regardless of how access is granted.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getUserRepos(string $instanceUrl, string $accessToken, string $userId, int $maxPages = 5): array {
		$out = [];
		for ($page = 1; $page <= $maxPages; $page++) {
			$response = $this->request($instanceUrl, $accessToken, $userId, 'repos/search', [
				'page' => $page,
				'limit' => 50,
			]);
			if (isset($response['error']) || !is_array($response)) {
				break;
			}
			// /repos/search wraps results in {ok: true, data: [...]}; guard
			// against both wrappings in case Forgejo/Gitea versions differ.
			$batch = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
			if (empty($batch)) {
				break;
			}
			foreach ($batch as $repo) {
				if (is_array($repo) && isset($repo['full_name'])) {
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
	 * Total count for a /repos/issues/search query, read from the
	 * X-Total-Count response header rather than counting rows in the
	 * body — so we return the real total even when it exceeds the
	 * page size. Uses limit=1 to keep the transfer tiny; only the
	 * header matters for the count.
	 *
	 * Returns -1 on error so the caller can distinguish "0 real
	 * matches" from "the request failed."
	 */
	public function countIssueSearch(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		array $params = [],
	): int {
		try {
			$params['limit'] = 1;
			$params['page'] = 1;
			$url = rtrim($instanceUrl, '/') . '/api/v1/repos/issues/search?' . http_build_query($params);
			$response = $this->clientService->newClient()->get($url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $accessToken,
					'Accept' => 'application/json',
					'User-Agent' => 'Nextcloud Forgejo/Gitea integration',
				],
				'timeout' => 30,
				'http_errors' => false,
			]);
			$status = $response->getStatusCode();
			if ($status === 401 && $userId !== '') {
				if ($this->tryRefresh($userId, $instanceUrl, $accessToken)) {
					return $this->countIssueSearch($instanceUrl, $this->tokens->getAccessToken($userId), $userId, $params);
				}
				return -1;
			}
			if ($status >= 400) {
				return -1;
			}
			return (int) $response->getHeader('X-Total-Count');
		} catch (Throwable $e) {
			$this->logger->warning('Forgejo/Gitea countIssueSearch failed', [
				'reason' => $this->redactSecrets($e->getMessage(), $accessToken),
			]);
			return -1;
		}
	}

	/**
	 * Refresh the access token in place; returns true on success.
	 * Extracted from retryAfterRefresh() so the count path can share it.
	 *
	 * $staleAccessToken is the token that just returned 401. If a concurrent
	 * request already refreshed while we were queued, the stored access token
	 * differs from the stale one — we return true immediately so the caller
	 * re-reads the fresh token and retries with it, rather than racing to
	 * refresh again (see retryAfterRefresh for the full rationale).
	 */
	private function tryRefresh(string $userId, string $instanceUrl, string $staleAccessToken = ''): bool {
		$currentAccess = $this->tokens->getAccessToken($userId);
		if ($staleAccessToken !== '' && $currentAccess !== '' && $currentAccess !== $staleAccessToken) {
			return true;
		}
		$refreshToken = $this->tokens->getRefreshToken($userId);
		if ($refreshToken === '') {
			return false;
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
			return false;
		}
		$this->tokens->setAccessToken($userId, (string) $result['access_token']);
		if (isset($result['refresh_token'])) {
			$this->tokens->setRefreshToken($userId, (string) $result['refresh_token']);
		}
		return true;
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

	/**
	 * Recent commits in a repo. Optional author filter.
	 */
	public function getRepoCommits(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/commits';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Open milestones in a repo.
	 */
	public function getRepoMilestones(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
		array $params = [],
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/milestones';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint, $params);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Repo metadata (stars, forks, open_issues, updated_at, etc.).
	 */
	public function getRepoDetails(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo);
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}

	/**
	 * Latest release in a repo. Returns [] if none exists.
	 */
	public function getLatestRelease(
		string $instanceUrl,
		string $accessToken,
		string $userId,
		string $owner,
		string $repo,
	): array {
		$endpoint = 'repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/latest';
		$result = $this->request($instanceUrl, $accessToken, $userId, $endpoint);
		if (isset($result['error']) || !is_array($result)) {
			return [];
		}
		return $result;
	}
}
