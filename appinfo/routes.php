<?php
/**
 * Nextcloud - ForgejoGitea integration
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 */

return [
    'routes' => [
        ['name' => 'config#oauthStart', 'url' => '/oauth-start', 'verb' => 'POST'],
        ['name' => 'config#oauthRedirect', 'url' => '/oauth-redirect', 'verb' => 'GET'],
        ['name' => 'config#setConfig', 'url' => '/config', 'verb' => 'PUT'],
        ['name' => 'config#setAdminConfig', 'url' => '/admin-config', 'verb' => 'PUT'],
        ['name' => 'forgejoGiteaAPI#getRepos', 'url' => '/repos', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getItems', 'url' => '/items', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getIssues', 'url' => '/issues', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getCommits', 'url' => '/commits', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getMilestones', 'url' => '/milestones', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getRepoStats', 'url' => '/repo-stats', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getReviewRequests', 'url' => '/review-requests', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getHeatmap', 'url' => '/heatmap', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getStats', 'url' => '/stats', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getNotifications', 'url' => '/notifications', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#markNotificationRead', 'url' => '/notifications/{threadId}', 'verb' => 'PATCH'],
        ['name' => 'forgejoGiteaAPI#getForgejoGiteaUrl', 'url' => '/url', 'verb' => 'GET'],
    ]
];
