<?php
declare(strict_types=1);

namespace OCA\ForgejoGitea\Dashboard;

use OCP\Dashboard\IWidget;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;

use OCA\ForgejoGitea\AppInfo\Application;

class PendingReviewsWidget implements IWidget {

	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $url,
		private IConfig $config,
	) {
	}

	public function getId(): string {
		return 'forgejo_gitea_pending_reviews';
	}

	public function getTitle(): string {
		$type = $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo');
		return $type === 'gitea'
			? $this->l10n->t('Gitea: Reviews')
			: $this->l10n->t('Forgejo: Reviews');
	}

	public function getOrder(): int {
		return 25;
	}

	public function getIconClass(): string {
		$type = $this->config->getAppValue(Application::APP_ID, 'instance_type_default', 'forgejo');
		return 'icon-forgejo_gitea-' . ($type === 'gitea' ? 'gitea' : 'forgejo');
	}

	public function getUrl(): ?string {
		return $this->url->linkToRoute('settings.PersonalSettings.index', ['section' => 'connected-accounts']);
	}

	public function load(): void {
		Util::addScript(Application::APP_ID, Application::APP_ID . '-dashboard');
		Util::addStyle(Application::APP_ID, 'dashboard');
	}
}
