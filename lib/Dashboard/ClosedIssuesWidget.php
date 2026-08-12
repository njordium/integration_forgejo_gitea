<?php
declare(strict_types=1);

/**
 * @copyright Copyright (c) 2026 Njordium
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\ForgejoGitea\AppInfo\Application;

class ClosedIssuesWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
	) {
	}

	public function getId(): string {
		return 'forgejo_gitea_closed_issues';
	}

	public function getTitle(): string {
		return $this->l10n->t('Forgejo/Gitea — Closed Issues');
	}

	public function getOrder(): int {
		return 20;
	}

	public function getIconClass(): string {
		return 'icon-forgejo_gitea';
	}

	public function getUrl(): ?string {
		return $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboardClosed');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
