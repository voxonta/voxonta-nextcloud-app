<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Asking the processing gateway what a meeting produced, and taking it.
 *
 * Plain HTTP on purpose. The audio leaves over a long two-way stream, but
 * collecting the results is one question and one answer — and doing that over
 * gRPC would mean a PHP extension no ordinary Nextcloud install has.
 *
 * Nothing about a meeting's contents is decided here: the gateway says what
 * exists, this fetches it, and ArtifactWriter puts it away.
 */
class GatewayClient {
	/** Generous: an analysis file can be a few hundred kilobytes. */
	private const TIMEOUT = 30;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function configured(): bool {
		return $this->base() !== '' && $this->token() !== '';
	}

	/**
	 * What exists for a meeting so far.
	 *
	 * @return array{status: string, final: bool, detail: string,
	 *               artifacts: array<int, array<string, mixed>>}|null
	 *         null when the gateway cannot be reached or does not know it — both
	 *         are "ask again later", never "this meeting produced nothing".
	 */
	public function meeting(string $sessionId): ?array {
		$url = $this->base() . '/v1/meetings/' . rawurlencode($sessionId);
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->token()],
				'timeout' => self::TIMEOUT,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('could not reach the gateway for {session}: {message}',
				['session' => $sessionId, 'message' => $e->getMessage()]);
			return null;
		}

		$body = json_decode((string)$response->getBody(), true);
		if (!is_array($body)) {
			$this->logger->warning('the gateway answered unreadably for {session}',
				['session' => $sessionId]);
			return null;
		}
		return [
			'status' => (string)($body['status'] ?? 'unknown'),
			'final' => (bool)($body['final'] ?? false),
			'detail' => (string)($body['detail'] ?? ''),
			'artifacts' => is_array($body['artifacts'] ?? null) ? $body['artifacts'] : [],
		];
	}

	/**
	 * One file's contents. Null when it cannot be fetched — the caller keeps the
	 * meeting pending and tries again rather than writing half of it.
	 */
	public function artifact(string $sessionId, string $sha256): ?string {
		$url = $this->base() . '/v1/meetings/' . rawurlencode($sessionId)
			. '/artifacts/' . rawurlencode($sha256);
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->token()],
				'timeout' => self::TIMEOUT,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('could not fetch {sha} of {session}: {message}',
				['sha' => substr($sha256, 0, 12), 'session' => $sessionId,
					'message' => $e->getMessage()]);
			return null;
		}

		$content = (string)$response->getBody();
		// The name is the digest, so verifying costs nothing and catches a
		// truncated body before it becomes a file someone reads.
		if (hash('sha256', $content) !== $sha256) {
			$this->logger->error('{sha} of {session} arrived corrupted — not writing it',
				['sha' => substr($sha256, 0, 12), 'session' => $sessionId]);
			return null;
		}
		return $content;
	}

	/**
	 * Tell the gateway these are stored, so it can retire them. Advisory: a
	 * failure here costs nothing but a longer retention.
	 *
	 * @param array<int, string> $digests
	 */
	public function ack(string $sessionId, array $digests): void {
		if ($digests === []) {
			return;
		}
		$url = $this->base() . '/v1/meetings/' . rawurlencode($sessionId) . '/ack';
		try {
			$this->clientService->newClient()->post($url, [
				'headers' => [
					'Authorization' => 'Bearer ' . $this->token(),
					'Content-Type' => 'application/json',
				],
				'body' => json_encode(['sha256' => array_values($digests)]),
				'timeout' => self::TIMEOUT,
			]);
		} catch (\Throwable $e) {
			$this->logger->info('could not acknowledge {session}: {message}',
				['session' => $sessionId, 'message' => $e->getMessage()]);
		}
	}

	private function base(): string {
		return rtrim($this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_GATEWAY_URL, ''), '/');
	}

	private function token(): string {
		return $this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_GATEWAY_TOKEN, '');
	}
}
