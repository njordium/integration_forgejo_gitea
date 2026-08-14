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
	private const MAX_ITEMS_PER_WIDGET = 50;
	private const MAX_PER_REPO = 50;
	private const NOTIFICATIONS_LIMIT = 50;
	private const DEFAULT_REFRESH_SECONDS = 300;
	private const ALLOWED_REFRESH_SECONDS = [0, 30, 60, 300, 900, 1800, 3600];
	private const DEFAULT_MAX_ITEMS = 20;
	private const ALLOWED_MAX_ITEMS = [5, 10, 15, 20, 25, 50];

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

		$refreshInterval = $this->readRefreshInterval($configKeyPrefix);

		if (empty($repos)) {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => [], 'filter' => $filter, 'refresh_interval_seconds' => $refreshInterval, 'max_items' => $this->readMaxItems($configKeyPrefix)],
				'instance_url' => $instanceUrl,
			]);
		}

		$userName = $this->effectiveUserName();
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
			'config' => ['repos' => $repos, 'filter' => $filter, 'refresh_interval_seconds' => $refreshInterval, 'max_items' => $this->readMaxItems($configKeyPrefix)],
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
		$userName = $this->effectiveUserName();
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

		$windowRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'heatmap_window_weeks', '13');
		$window = (int) $windowRaw;
		if (!in_array($window, [13, 26], true)) {
			$window = 13;
		}

		return new DataResponse([
			'points' => $points,
			'total' => $total,
			'user_name' => $userName,
			'instance_url' => $instanceUrl,
			'instance_type' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
			'refresh_interval_seconds' => $this->readRefreshInterval('heatmap'),
			'window_weeks' => $window,
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
		$user = $this->effectiveUserName();
		if ($user === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}

		// /repos/issues/search takes BOOLEAN filters relative to the
		// bearer-authenticated user, NOT username strings. Passing
		// assigned_by=NAME etc. gets silently ignored — the endpoint then
		// returns *all* open issues visible to the token, which is why
		// every tile previously showed the same non-zero count.
		$count = fn (array $q): int => max(0, $this->api->countIssueSearch(
			$instanceUrl, $accessToken, $this->userId ?? '', $q,
		));
		$openAssignedIssues = $count(['type' => 'issues', 'state' => 'open', 'assigned' => 'true']);
		$openCreatedIssues = $count(['type' => 'issues', 'state' => 'open', 'created' => 'true']);
		$openAssignedPRs = $count(['type' => 'pulls', 'state' => 'open', 'review_requested' => 'true']);
		$openCreatedPRs = $count(['type' => 'pulls', 'state' => 'open', 'created' => 'true']);
		$mentioned = $count(['type' => 'issues', 'state' => 'open', 'mentioned' => 'true']);
		// Instance-wide totals — no user filter, so /repos/issues/search returns
		// every issue in every repo the bearer token can access, regardless of
		// whether the connected user is assigned/creator/mentioner. Uses the
		// X-Total-Count response header rather than counting rows in a capped
		// page, so a repo with hundreds of closed issues renders the real
		// number instead of the per-page ceiling.
		$totalOpenIssues = $this->api->countIssueSearch(
			$instanceUrl, $accessToken, $this->userId ?? '',
			['type' => 'issues', 'state' => 'open'],
		);
		$totalClosedIssues = $this->api->countIssueSearch(
			$instanceUrl, $accessToken, $this->userId ?? '',
			['type' => 'issues', 'state' => 'closed'],
		);
		if ($totalOpenIssues < 0) { $totalOpenIssues = 0; }
		if ($totalClosedIssues < 0) { $totalClosedIssues = 0; }

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
				['key' => 'total_open_issues', 'label' => 'Open issues (total)', 'value' => $totalOpenIssues],
				['key' => 'total_closed_issues', 'label' => 'Closed issues (total)', 'value' => $totalClosedIssues],
			],
			'user_name' => $user,
			'instance_url' => $instanceUrl,
			'instance_type' => $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo'),
			'refresh_interval_seconds' => $this->readRefreshInterval('stats'),
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
			'refresh_interval_seconds' => $this->readRefreshInterval('notifications'),
			'max_items' => $this->readMaxItems('notifications'),
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
	 * Recent commits authored by the connected user across their selected repos.
	 * @NoAdminRequired
	 */
	public function getCommits(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'commits_widget_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];
		$onlyMine = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'commits_widget_only_mine', '1') === '1';
		$userName = $this->effectiveUserName();

		if (empty($repos)) {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => [], 'only_mine' => $onlyMine],
				'instance_url' => $instanceUrl,
			]);
		}

		$items = [];
		foreach ($repos as $fullName) {
			if (!str_contains($fullName, '/')) {
				continue;
			}
			[$owner, $repo] = explode('/', $fullName, 2);
			$params = ['limit' => 10];
			if ($onlyMine && $userName !== '') {
				$params['author'] = $userName;
			}
			$commits = $this->api->getRepoCommits($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo, $params);
			foreach ($commits as $c) {
				if (!isset($c['sha'])) {
					continue;
				}
				$msg = $c['commit']['message'] ?? '';
				$msgFirstLine = strtok($msg, "\n") ?: '';
				$items[] = [
					'sha' => substr((string) $c['sha'], 0, 7),
					'sha_full' => (string) $c['sha'],
					'title' => $msgFirstLine,
					'html_url' => $c['html_url'] ?? '',
					'created_at' => $c['created'] ?? ($c['commit']['author']['date'] ?? ''),
					'author' => [
						'login' => $c['author']['login'] ?? ($c['commit']['author']['name'] ?? ''),
						'avatar_url' => $c['author']['avatar_url'] ?? '',
					],
					'repo_full_name' => $fullName,
				];
			}
		}
		usort($items, static fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
		$items = array_slice($items, 0, 30);

		return new DataResponse([
			'items' => $items,
			'config' => ['repos' => $repos, 'only_mine' => $onlyMine, 'refresh_interval_seconds' => $this->readRefreshInterval('commits'), 'max_items' => $this->readMaxItems('commits')],
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Open milestones with progress across the widget's selected repos.
	 * @NoAdminRequired
	 */
	public function getMilestones(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'milestones_widget_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];

		$milestonesRefresh = $this->readRefreshInterval('milestones');
		if (empty($repos)) {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => [], 'refresh_interval_seconds' => $milestonesRefresh, 'max_items' => $this->readMaxItems('milestones')],
				'instance_url' => $instanceUrl,
			]);
		}

		$items = [];
		foreach ($repos as $fullName) {
			if (!str_contains($fullName, '/')) {
				continue;
			}
			[$owner, $repo] = explode('/', $fullName, 2);
			$milestones = $this->api->getRepoMilestones($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo, ['state' => 'open', 'limit' => 30]);
			foreach ($milestones as $m) {
				if (!isset($m['id'])) {
					continue;
				}
				$open = (int) ($m['open_issues'] ?? 0);
				$closed = (int) ($m['closed_issues'] ?? 0);
				$total = $open + $closed;
				$items[] = [
					'id' => (int) $m['id'],
					'title' => $m['title'] ?? '',
					'html_url' => rtrim($instanceUrl, '/') . '/' . $fullName . '/milestone/' . ((int) $m['id']),
					'repo_full_name' => $fullName,
					'open_issues' => $open,
					'closed_issues' => $closed,
					'total_issues' => $total,
					'percent' => $total > 0 ? (int) round(($closed / $total) * 100) : 0,
					'due_on' => $m['due_on'] ?? '',
				];
			}
		}
		usort($items, static function ($a, $b) {
			$da = $a['due_on'] ?: '9999';
			$db = $b['due_on'] ?: '9999';
			return strcmp($da, $db);
		});
		$items = array_slice($items, 0, 20);

		return new DataResponse([
			'items' => $items,
			'config' => ['repos' => $repos, 'refresh_interval_seconds' => $milestonesRefresh, 'max_items' => $this->readMaxItems('milestones')],
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Per-repo stats card list (stars, forks, open issues, last commit, last release).
	 * @NoAdminRequired
	 */
	public function getRepoStats(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'repo_stats_widget_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];

		$repoStatsRefresh = $this->readRefreshInterval('repo_stats');
		if (empty($repos)) {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => [], 'refresh_interval_seconds' => $repoStatsRefresh, 'max_items' => $this->readMaxItems('repo_stats')],
				'instance_url' => $instanceUrl,
			]);
		}

		$items = [];
		foreach ($repos as $fullName) {
			if (!str_contains($fullName, '/')) {
				continue;
			}
			[$owner, $repo] = explode('/', $fullName, 2);
			$details = $this->api->getRepoDetails($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo);
			if (empty($details)) {
				continue;
			}
			$release = $this->api->getLatestRelease($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo);
			$items[] = [
				'full_name' => $fullName,
				'html_url' => $details['html_url'] ?? (rtrim($instanceUrl, '/') . '/' . $fullName),
				'description' => $details['description'] ?? '',
				'stars' => (int) ($details['stars_count'] ?? 0),
				'forks' => (int) ($details['forks_count'] ?? 0),
				'open_issues' => (int) ($details['open_issues_count'] ?? 0),
				'open_pulls' => (int) ($details['open_pr_counter'] ?? 0),
				'updated_at' => $details['updated_at'] ?? '',
				'default_branch' => $details['default_branch'] ?? '',
				'latest_release' => isset($release['tag_name']) ? [
					'tag_name' => $release['tag_name'],
					'name' => $release['name'] ?? $release['tag_name'],
					'html_url' => $release['html_url'] ?? '',
					'published_at' => $release['published_at'] ?? '',
				] : null,
			];
		}

		return new DataResponse([
			'items' => $items,
			'config' => ['repos' => $repos, 'refresh_interval_seconds' => $repoStatsRefresh, 'max_items' => $this->readMaxItems('repo_stats')],
			'instance_url' => $instanceUrl,
		]);
	}

	/**
	 * Open pull requests where the connected user is a requested reviewer,
	 * scoped to the widget's selected repos.
	 * @NoAdminRequired
	 */
	public function getReviewRequests(): DataResponse {
		[$instanceUrl, $accessToken] = $this->credentials();
		if ($accessToken === '') {
			return new DataResponse(['error' => 'not_connected'], Http::STATUS_UNAUTHORIZED);
		}
		$reposRaw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'reviews_widget_repos', '[]');
		$decodedRepos = json_decode($reposRaw, true);
		$repos = is_array($decodedRepos) ? array_values(array_filter($decodedRepos, 'is_string')) : [];
		$userName = $this->effectiveUserName();
		$reviewsRefresh = $this->readRefreshInterval('reviews');

		if (empty($repos) || $userName === '') {
			return new DataResponse([
				'items' => [],
				'config' => ['repos' => $repos, 'refresh_interval_seconds' => $reviewsRefresh, 'max_items' => $this->readMaxItems('reviews')],
				'instance_url' => $instanceUrl,
			]);
		}

		$items = [];
		foreach ($repos as $fullName) {
			if (!str_contains($fullName, '/')) {
				continue;
			}
			[$owner, $repo] = explode('/', $fullName, 2);
			$prs = $this->api->getRepoIssues($instanceUrl, $accessToken, $this->userId ?? '', $owner, $repo, [
				'type' => 'pulls',
				'state' => 'open',
				'limit' => 30,
			]);
			foreach ($prs as $pr) {
				$reviewers = $pr['requested_reviewers'] ?? [];
				$isReviewer = false;
				foreach ($reviewers as $reviewer) {
					if (($reviewer['login'] ?? '') === $userName) {
						$isReviewer = true;
						break;
					}
				}
				if (!$isReviewer) {
					continue;
				}
				$items[] = [
					'id' => $pr['id'] ?? 0,
					'number' => $pr['number'] ?? 0,
					'title' => $pr['title'] ?? '',
					'html_url' => $pr['html_url'] ?? '',
					'updated_at' => $pr['updated_at'] ?? '',
					'user' => [
						'login' => $pr['user']['login'] ?? '',
						'avatar_url' => $pr['user']['avatar_url'] ?? '',
					],
					'repo_full_name' => $fullName,
					'comments' => (int) ($pr['comments'] ?? 0),
					'labels' => array_map(
						static fn($l) => ['name' => $l['name'] ?? '', 'color' => $l['color'] ?? ''],
						is_array($pr['labels'] ?? null) ? $pr['labels'] : []
					),
				];
			}
		}
		usort($items, static fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));
		$items = array_slice($items, 0, 30);

		return new DataResponse([
			'items' => $items,
			'config' => ['repos' => $repos, 'refresh_interval_seconds' => $reviewsRefresh, 'max_items' => $this->readMaxItems('reviews')],
			'instance_url' => $instanceUrl,
		]);
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
	 * The Forgejo/Gitea login used when filtering queries by assigned_by /
	 * created_by / mentioned_by, and for the heatmap. Falls back to the
	 * OAuth-connected login (`user_name`) when the user has not entered
	 * an override in Personal Settings.
	 */
	private function effectiveUserName(): string {
		$override = trim($this->config->getUserValue(
			$this->userId ?? '',
			Application::APP_ID,
			'override_user_name',
			''
		));
		if ($override !== '') {
			return $override;
		}
		return $this->config->getUserValue($this->userId ?? '', Application::APP_ID, 'user_name');
	}


	/**
	 * @return array{0: string, 1: string} [instanceUrl, accessToken]
	 */
	private function credentials(): array {
		$instanceUrl = rtrim($this->config->getAppValue(Application::APP_ID, 'oauth_instance_url'), '/');
		$accessToken = $this->userId !== null ? $this->tokens->getAccessToken($this->userId) : '';
		return [$instanceUrl, $accessToken];
	}

	/**
	 * Read a per-widget refresh interval from user config, validated against
	 * the ALLOWED_REFRESH_SECONDS whitelist. Falls back to DEFAULT_REFRESH_SECONDS
	 * when unset or invalid.
	 */
	private function readRefreshInterval(string $widgetKey): int {
		$configKey = $widgetKey . '_refresh_seconds';
		$raw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $configKey, (string) self::DEFAULT_REFRESH_SECONDS);
		$seconds = (int) $raw;
		return in_array($seconds, self::ALLOWED_REFRESH_SECONDS, true) ? $seconds : self::DEFAULT_REFRESH_SECONDS;
	}

	/**
	 * Per-widget "records to show" value the user picked in the settings
	 * modal. Validated against ALLOWED_MAX_ITEMS; falls back to
	 * DEFAULT_MAX_ITEMS on unset / invalid values.
	 */
	private function readMaxItems(string $widgetKey): int {
		$configKey = $widgetKey . '_max_items';
		$raw = $this->config->getUserValue($this->userId ?? '', Application::APP_ID, $configKey, (string) self::DEFAULT_MAX_ITEMS);
		$n = (int) $raw;
		return in_array($n, self::ALLOWED_MAX_ITEMS, true) ? $n : self::DEFAULT_MAX_ITEMS;
	}
}
