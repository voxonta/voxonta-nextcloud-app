<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Talks to the transcription service.
 *
 * The token stays here for a reason: it is not scoped to a user, so it opens
 * every meeting in the instance. Handing it to the browser would mean anyone
 * with devtools open walks away with it.
 */
class BackendClient {
	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function isConfigured(): bool {
		return $this->baseUrl() !== '';
	}

	/**
	 * @throws BackendException
	 */
	public function get(string $path, array $query = []): array {
		$base = $this->baseUrl();
		if ($base === '') {
			throw new BackendException('the transcription service is not configured',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$headers = ['Accept' => 'application/json'];
		$token = $this->appConfig->getValueString(Application::APP_ID,
			AdminSettings::KEY_BACKEND_TOKEN);
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->clientService->newClient()->get($base . $path, [
				'query' => $query,
				'headers' => $headers,
				'timeout' => 30,
			]);
		} catch (\Throwable $e) {
			// Deliberately vague to the caller, specific in the log: the
			// message can carry the URL, and the URL can carry the token.
			$this->logger->error('transcription service unreachable', ['exception' => $e]);
			throw new BackendException('the transcription service is unreachable',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$body = json_decode((string)$response->getBody(), true);
		if (!is_array($body)) {
			$this->logger->error('transcription service returned something that is not JSON');
			throw new BackendException('unexpected answer from the transcription service',
				Http::STATUS_BAD_GATEWAY);
		}
		return $body;
	}

	/**
	 * @throws BackendException
	 */
	public function post(string $path, array $body): array {
		$base = $this->baseUrl();
		if ($base === '') {
			throw new BackendException('the transcription service is not configured',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$headers = ['Accept' => 'application/json'];
		$token = $this->appConfig->getValueString(Application::APP_ID,
			AdminSettings::KEY_BACKEND_TOKEN);
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		try {
			$response = $this->clientService->newClient()->post($base . $path, [
				'json' => $body,
				'headers' => $headers,
				'timeout' => 30,
			]);
		} catch (\Throwable $e) {
			$this->logger->error('transcription service unreachable', ['exception' => $e]);
			throw new BackendException('the transcription service is unreachable',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}

		$decoded = json_decode((string)$response->getBody(), true);
		return is_array($decoded) ? $decoded : [];
	}

	private function baseUrl(): string {
		$url = $this->appConfig->getValueString(Application::APP_ID,
			AdminSettings::KEY_BACKEND_URL);
		return rtrim(trim($url), '/');
	}
}
