<?php
/**
 * Nextcloud - ForgejoGitea integration
 *
 * Service skeleton — real Forgejo/Gitea REST v1 client lands in Checkpoint 2.
 *
 * @license GNU AGPL version 3 or any later version
 */

namespace OCA\ForgejoGitea\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class ForgejoGiteaAPIService {

	public function __construct(
		private IConfig $config,
		private IClientService $clientService,
		private LoggerInterface $logger,
		private TokenStorage $tokens,
	) {
	}

	/**
	 * OAuth token exchange (authorization-code + refresh grants).
	 * Implemented in Checkpoint 2.
	 */
	public function requestOAuthAccessToken(string $url, array $params, string $method = 'POST'): array {
		return ['error' => 'not_implemented'];
	}
}
