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
	private const MAX_ITEMS_PER_WIDGET = 30;
	private const MAX_PER_REPO = 15;

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
		]);
	}

	/**
	 * All repos the connected user can access — for the widget settings picker.
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
	 * Issues for a widget. Reads the widget's saved repos + filter from
	 * user config, fans out per-repo requests, merges, sorts by updated_at.
	 * @NoAdminRequired
	 */
	public function getIssues(string $state = 'open'): DataResponse {
		$state = $state === 'closed' ? 'closed' : 'open';
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}

		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $state . '_widget_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];

		$filter = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $state . '_widget_filter', 'assigned');
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
			'type' => 'issues',
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
	 * @NoAdminRequired
	 */
	public function getForgejoGiteaAvatar(string $url = ''): DataResponse {
		return new DataResponse(['avatar_url' => $url]);
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
