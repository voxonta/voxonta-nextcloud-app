<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service;

use OCA\Voxonta\AppInfo\Application;
use OCA\Voxonta\Settings\AdminSettings;
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

	/** Whether the last meeting() failure was "no such meeting" rather than "cannot ask". */
	private bool $lastWasUnknown = false;

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * True when the last meeting() returned null because the gateway does not
	 * know the meeting, rather than because it could not be reached.
	 */
	public function lastAnswerWasUnknown(): bool {
		return $this->lastWasUnknown;
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
		$this->lastWasUnknown = false;
		try {
			$response = $this->clientService->newClient()->get($url, [
				'headers' => ['Authorization' => 'Bearer ' . $this->token()],
				'timeout' => self::TIMEOUT,
			]);
		} catch (\Throwable $e) {
			// A 404 means the gateway has no such meeting — the call never
			// reached it, and no amount of asking will change that. Everything
			// else is "not right now". Both return null, but the caller can tell
			// them apart through lastAnswerWasUnknown() and give up early on the
			// first rather than retrying it for a fortnight.
			$this->lastWasUnknown = str_contains($e->getMessage(), '404');
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
	 * Meetings the gateway still holds files for.
	 *
	 * The queue in this app is a memory, and memories are lost: a status read
	 * as final, a wiped config, a reinstall. Every such loss used to turn
	 * finished work into files nobody would come for — eight of them were found
	 * by hand on 2026-08-17, the oldest a fortnight old, after someone asked why
	 * their transcript never arrived.
	 *
	 * The gateway has always known, through its own acked flag. This is asking.
	 *
	 * @return array<int, array{session_id: string, room_token: string,
	 *               room_name: string}> empty when nothing is waiting OR when
	 *         the gateway cannot be reached: a sweep that fails is a sweep that
	 *         runs again in an hour, not a reason to disturb anything.
	 */
	public function undelivered(): array {
		if (!$this->configured()) {
			return [];
		}
		try {
			$response = $this->clientService->newClient()->get(
				$this->base() . '/v1/meetings/undelivered', [
					'headers' => ['Authorization' => 'Bearer ' . $this->token()],
					'timeout' => self::TIMEOUT,
				]);
		} catch (\Throwable $e) {
			// Includes a gateway too old to know this route. Nothing breaks:
			// the app keeps working from its own queue exactly as before.
			$this->logger->debug('could not ask the gateway what is undelivered: {message}',
				['message' => $e->getMessage()]);
			return [];
		}

		$body = json_decode((string)$response->getBody(), true);
		$rows = is_array($body) && is_array($body['meetings'] ?? null)
			? $body['meetings'] : [];

		$out = [];
		foreach ($rows as $row) {
			$sessionId = (string)($row['session_id'] ?? '');
			if ($sessionId === '') {
				continue;
			}
			$out[] = [
				'session_id' => $sessionId,
				'room_token' => (string)($row['room_token'] ?? ''),
				'room_name' => (string)($row['room_name'] ?? ''),
			];
		}
		return $out;
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
