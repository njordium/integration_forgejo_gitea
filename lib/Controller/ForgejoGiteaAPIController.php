<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;

use OCA\ForgejoGitea\AppInfo\Application;
use OCA\ForgejoGitea\Service\ForgejoGiteaAPIService;
use OCA\ForgejoGitea\Service\TokenStorage;

class ForgejoGiteaAPIController extends Controller {

	private const FILTERS = ['assigned', 'created', 'mentioned', 'all'];
	private const ITEM_TYPES = ['issues', 'pulls'];
	private const MAX_ITEMS_PER_WIDGET = 30;
	private const MAX_PER_REPO = 15;
	private const NOTIFICATIONS_LIMIT = 20;

	public function __construct(
		string $appName,
		IRequest $request,
		private IConfig $config,
		private ForgejoGiteaAPIService $api,
		private TokenStorage $tokens,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Instance URL for widget deep links.
	 * @NoAdminRequired
	 */
	public function getForgejoGiteaUrl(): DataResponse {
		return new DataResponse([
			'instance_url' => rtrim($this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'), '/'),
			'instance_type' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
			'user_name' => $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name'),
		]);
	}

	/**
	 * All repos the connected user can access.
	 * @NoAdminRequired
	 */
	public function getRepos(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$repos = $this->api->getUserRepos($instanceUrl, $accessToken, $this->userId ?? '');
		$out = [];
		foreach ($repos as $r) {
			$out[] = [
				'full_name' => $r['full_name'] ?? '',
				'name' => $r['name'] ?? '',
				'owner' => $r['owner']['login'] ?? '',
				'description' => $r['description'] ?? '',
				'private' => (bool) ($r['private'] ?? false),
			];
		}
		return new DataResponse(['repos' => $out]);
	}

	/**
	 * Legacy alias for /items?type=issues — kept for older widget builds.
	 * @NoAdminRequired
	 */
	public function getIssues(string $state = 'open'): DataResponse {
		return $this->getItems($state, 'issues');
	}

	/**
	 * Items (issues or pulls) for a widget. Reads the widget's saved repos +
	 * filter, fans out per-repo requests, merges, sorts by updated_at.
	 * @NoAdminRequired
	 */
	public function getItems(string $state = 'open', string $type = 'issues'): DataResponse {
		$state = $state === 'closed' ? 'closed' : 'open';
		$type = in_array($type, self::ITEM_TYPES, true) ? $type : 'issues';

		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}

		$configKeyPrefix = $this->configKeyPrefix($state, $type);
		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $configKeyPrefix . '_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];

		$filter = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $configKeyPrefix . '_filter', 'assigned');
		if (!in_array($filter, self::FILTERS, true)) {
			$filter = 'assigned';
		}

		if (empty($repos)) {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => [], 'filter' => $filter],
				'instance_url' => $instanceUrl,
			]);
		}

		$userName = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name');
		$params = [
			'state' => $state,
			'type' => $type === 'pulls' ? 'pulls' : 'issues',
			'limit' => self::MAX_PER_REPO,
		];
		if ($filter !== 'all' && $userName !== '') {
			$params[$filter . '_by'] = $userName;
		}

		$items = [];
		foreach ($repos as $fullName) {
			if (!str_contains($fullName, '/')) {
				continue;
			}
			[$owner, $repo] = explode('/', $fullName, 2);
			$repoIssues = $this->api->getRepoIssues($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo, $params);
			foreach ($repoIssues as $issue) {
				if (!isset($issue['id'], $issue['title'])) {
					continue;
				}
				$items[] = [
					'id' => $issue['id'],
					'number' => $issue['number'] ?? 0,
					'title' => $issue['title'],
					'html_url' => $issue['html_url'] ?? '',
					'state' => $issue['state'] ?? $state,
					'updated_at' => $issue['updated_at'] ?? '',
					'created_at' => $issue['created_at'] ?? '',
					'user' => [
						'login' => $issue['user']['login'] ?? '',
						'avatar_url' => $issue['user']['avatar_url'] ?? '',
					],
					'repo_full_name' => $fullName,
					'comments' => (int) ($issue['comments'] ?? 0),
					'labels' => array_map(
						static fn($l) => ['name' => $l['name'] ?? '', 'color' => $l['color'] ?? ''],
						is_array($issue['labels'] ?? null) ? $issue['labels'] : []
					),
				];
			}
		}

		usort($items, static fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
		$items = array_slice($items, 0, self::MAX_ITEMS_PER_WIDGET);

		return new DataResponse([
			'items' => $items,
			'config' => ['repos' => $repos, 'filter' => $filter],
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Contribution heatmap for the connected user.
	 * @NoAdminRequired
	 */
	public function getHeatmap(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$userName = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name');
		$raw = $this->api->getHeatmap($instanceUrl, $accessToken, $this->userId ?? '', $userName);

		$points = [];
		$total = 0;
		foreach ($raw as $entry) {
			$ts = (int) ($entry['timestamp'] ?? 0);
			$count = (int) ($entry['contributions'] ?? 0);
			if ($ts <= 0) {
				continue;
			}
			$points[] = ['ts' => $ts, 'count' => $count];
			$total += $count;
		}

		return new DataResponse([
			'points' => $points,
			'total' => $total,
			'user_name' => $userName,
			'instance_url' => $instanceUrl,
			'instance_type' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
		]);
	}

	/**
	 * Aggregate KPI counts for the stats widget. One call per tile, batched.
	 * @NoAdminRequired
	 */
	public function getStats(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$user = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name');
		if ($user === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}

		$openAssignedIssues = $this->countSearch(['type' => 'issues', 'state' => 'open', 'assigned_by' => $user]);
		$openCreatedIssues = $this->countSearch(['type' => 'issues', 'state' => 'open', 'created_by' => $user]);
		$openAssignedPRs = $this->countSearch(['type' => 'pulls', 'state' => 'open', 'assigned_by' => $user]);
		$openCreatedPRs = $this->countSearch(['type' => 'pulls', 'state' => 'open', 'created_by' => $user]);
		$mentioned = $this->countSearch(['type' => 'issues', 'state' => 'open', 'mentioned_by' => $user]);

		$heatmap = $this->api->getHeatmap($instanceUrl, $accessToken, $this->userId ?? '', $user);
		$sevenDayAgo = time() - (7 * 86400);
		$contribs7d = 0;
		foreach ($heatmap as $entry) {
			if ((int) ($entry['timestamp'] ?? 0) >= $sevenDayAgo) {
				$contribs7d += (int) ($entry['contributions'] ?? 0);
			}
		}

		return new DataResponse([
			'tiles' => [
				['key' => 'open_assigned_issues', 'label' => 'Open issues assigned', 'value' => $openAssignedIssues],
				['key' => 'open_created_issues', 'label' => 'Open issues I opened', 'value' => $openCreatedIssues],
				['key' => 'open_assigned_prs', 'label' => 'Open PRs to review', 'value' => $openAssignedPRs],
				['key' => 'open_created_prs', 'label' => 'Open PRs I opened', 'value' => $openCreatedPRs],
				['key' => 'mentioned_open', 'label' => 'Open issues mentioning me', 'value' => $mentioned],
				['key' => 'contributions_7d', 'label' => 'Contributions last 7 days', 'value' => $contribs7d],
			],
			'user_name' => $user,
			'instance_url' => $instanceUrl,
			'instance_type' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
		]);
	}

	/**
	 * Unread notifications for the connected user.
	 * @NoAdminRequired
	 */
	public function getNotifications(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$raw = $this->api->getNotifications($instanceUrl, $accessToken, $this->userId ?? '', [
			'status-types' => 'unread',
			'limit' => self::NOTIFICATIONS_LIMIT,
		]);

		$items = [];
		foreach ($raw as $n) {
			$subject = $n['subject'] ?? [];
			$items[] = [
				'id' => (string) ($n['id'] ?? ''),
				'title' => $subject['title'] ?? '',
				'type' => $subject['type'] ?? 'Unknown',
				'state' => $subject['state'] ?? '',
				'html_url' => $subject['html_url'] ?? ($subject['url'] ?? ''),
				'updated_at' => $n['updated_at'] ?? '',
				'repo_full_name' => $n['repository']['full_name'] ?? '',
				'unread' => (bool) ($n['unread'] ?? true),
			];
		}
		usort($items, static fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

		return new DataResponse([
			'items' => $items,
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Mark a single notification thread as read.
	 * @NoAdminRequired
	 */
	public function markNotificationRead(string $threadId): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$ok = $this->api->markNotificationRead($instanceUrl, $accessToken, $this->userId ?? '', $threadId);
		return new DataResponse(['ok' => $ok]);
	}

	/**
	 * @NoAdminRequired
	 */
	public function getForgejoGiteaAvatar(string $url = ''): DataResponse {
		return new DataResponse(['avatar_url' => $url]);
	}

	/**
	 * Config-key prefix for a widget's saved repos + filter. Keeps
	 * backward compatibility for existing issues widgets (unsuffixed).
	 */
	private function configKeyPrefix(string $state, string $type): string {
		return $type === 'pulls'
			? $state . '_pulls_widget'
			: $state . '_widget';
	}

	/**
	 * Count results from /repos/issues/search with the given filters, capping
	 * at 50 (Forgejo's max limit per page) — 50+ is displayed as "50+".
	 */
	private function countSearch(array $params): int {
		[$instanceUrl, $accessToken] = $this->credentials();
		$params['limit'] = 50;
		$rows = $this->api->searchAllIssues($instanceUrl, $accessToken, $this->userId ?? '', $params);
		return is_array($rows) ? count($rows) : 0;
	}

	/**
	 * @return array{0: string, 1: string} [instanceUrl, accessToken]
	 */
	private function credentials(): array {
		$instanceUrl = rtrim($this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'), '/');
		$accessToken = $this->userId !== null ? $this->tokens->getAccessToken($this->userId) : '';
		return [$instanceUrl, $accessToken];
	}
}
