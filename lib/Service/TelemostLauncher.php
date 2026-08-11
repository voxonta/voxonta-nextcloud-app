<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service;

use OCA\Voxonta\AppInfo\Application;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Asking for a Telemost meeting to be transcribed.
 *
 * Someone drops a meeting link into a Talk room; this asks the service to send a
 * bot to it. Nothing else about the meeting happens here — the bot joins by
 * link, the audio goes to the gateway, and the finished files come back through
 * the same collection as any other meeting.
 *
 * Which is why the answer matters: it carries the meeting's id, and that id is
 * what puts the meeting into the collection queue. Without it nobody would know
 * to go looking for its files.
 */
class TelemostLauncher {
	/** A bot takes a moment to start; the answer is not worth waiting long for. */
	private const TIMEOUT = 20;

	/**
	 * Recognising a link is deliberately loose — people paste them with query
	 * strings, from mobile, out of calendar invitations. Whether it is really a
	 * meeting is the launcher's call, not ours.
	 */
	private const LINK = '~https?://telemost(?:\.[a-z0-9-]+)*\.yandex\.[a-z]+/j/\d+[^\s]*~i';

	public function __construct(
		private IClientService $clientService,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}

	public function configured(): bool {
		return $this->base() !== '';
	}

	/** The first Telemost link in a message, or empty. */
	public function linkIn(string $text): string {
		return preg_match(self::LINK, $text, $m) === 1 ? $m[0] : '';
	}

	/**
	 * Ask for a bot. Returns the meeting's id, or empty when it could not be
	 * arranged — the caller says so in the room rather than leaving silence.
	 *
	 * Asking twice for the same meeting is safe and expected: a re-posted link
	 * gets the same id back and no second bot.
	 */
	public function launch(string $meetingUrl, string $trigger = 'nc-chat'): string {
		if (!$this->configured()) {
			return '';
		}
		try {
			$response = $this->clientService->newClient()->post(
				$this->base() . '/v1/launch', [
					'headers' => [
						'Authorization' => 'Bearer ' . $this->token(),
						'Content-Type' => 'application/json',
					],
					'body' => json_encode([
						'meeting_url' => $meetingUrl,
						'trigger' => $trigger,
					]),
					'timeout' => self::TIMEOUT,
				]);
		} catch (\Throwable $e) {
			// A refusal for lack of capacity arrives here too, as a 429. Either
			// way there is no bot and no meeting to wait for.
			$this->logger->warning('could not launch a bot for {url}: {message}',
				['url' => $meetingUrl, 'message' => $e->getMessage()]);
			return '';
		}

		$body = json_decode((string)$response->getBody(), true);
		if (!is_array($body)) {
			return '';
		}
		return (string)($body['session_hint'] ?? '');
	}

	/**
	 * Where to ask for a bot. The dedicated setting when an administrator filled
	 * one in, the gateway's address otherwise — they are the same service, and
	 * asking for two addresses invited them to drift apart.
	 */
	private function base(): string {
		$own = rtrim($this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_TELEMOST_URL, ''), '/');
		if ($own !== '') {
			return $own;
		}
		return rtrim($this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_GATEWAY_URL, ''), '/');
	}

	/**
	 * Which key to present.
	 *
	 * The gateway key, because it says whose meeting this is: the launcher
	 * verifies it and files the meeting under this installation's tenant. The
	 * separate Telemost key is a leftover from when the launcher had one shared
	 * key for everybody — it still works, so an installation that has one keeps
	 * working, but it does not identify anyone.
	 */
	private function token(): string {
		$gateway = $this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_GATEWAY_TOKEN, '');
		if ($gateway !== '') {
			return $gateway;
		}
		return $this->appConfig->getValueString(
			Application::APP_ID, AdminSettings::KEY_TELEMOST_TOKEN, '');
	}
}
