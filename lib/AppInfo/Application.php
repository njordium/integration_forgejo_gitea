<?php
/**
 * Nextcloud - ForgejoGitea integration
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;

use OCA\ForgejoGitea\Dashboard\ClosedIssuesWidget;
use OCA\ForgejoGitea\Dashboard\OpenIssuesWidget;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_forgejo_gitea';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(OpenIssuesWidget::class);
		$context->registerDashboardWidget(ClosedIssuesWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
