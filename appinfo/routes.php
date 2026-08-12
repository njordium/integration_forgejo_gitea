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
        ['name' => 'forgejoGiteaAPI#getIssues', 'url' => '/issues', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getForgejoGiteaUrl', 'url' => '/url', 'verb' => 'GET'],
        ['name' => 'forgejoGiteaAPI#getForgejoGiteaAvatar', 'url' => '/avatar', 'verb' => 'GET'],
    ]
];
