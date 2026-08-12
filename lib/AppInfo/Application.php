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
use OCA\ForgejoGitea\Dashboard\ClosedPRsWidget;
use OCA\ForgejoGitea\Dashboard\HeatmapWidget;
use OCA\ForgejoGitea\Dashboard\MilestonesWidget;
use OCA\ForgejoGitea\Dashboard\NotificationsWidget;
use OCA\ForgejoGitea\Dashboard\OpenIssuesWidget;
use OCA\ForgejoGitea\Dashboard\OpenPRsWidget;
use OCA\ForgejoGitea\Dashboard\PendingReviewsWidget;
use OCA\ForgejoGitea\Dashboard\RecentCommitsWidget;
use OCA\ForgejoGitea\Dashboard\RepoStatsWidget;
use OCA\ForgejoGitea\Dashboard\StatsWidget;

class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_forgejo_gitea';

	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(StatsWidget::class);
		$context->registerDashboardWidget(OpenIssuesWidget::class);
		$context->registerDashboardWidget(NotificationsWidget::class);
		$context->registerDashboardWidget(ClosedIssuesWidget::class);
		$context->registerDashboardWidget(PendingReviewsWidget::class);
		$context->registerDashboardWidget(OpenPRsWidget::class);
		$context->registerDashboardWidget(ClosedPRsWidget::class);
		$context->registerDashboardWidget(HeatmapWidget::class);
		$context->registerDashboardWidget(RecentCommitsWidget::class);
		$context->registerDashboardWidget(MilestonesWidget::class);
		$context->registerDashboardWidget(RepoStatsWidget::class);
	}

	public function boot(IBootContext $context): void {
	}
}
