<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Which calls are running right now, for the capture service to join.
 *
 * The service used to learn this by polling Nextcloud's database directly over
 * an SSH tunnel — which meant an installation needed the database credentials
 * and a shell account on the Nextcloud server, and so could never be something
 * an administrator did for themselves. Talk raises an event when a call starts
 * and another when it ends; listening to those and answering "these are live"
 * needs neither.
 *
 * Kept in appconfig rather than a table of its own: this is a handful of rows
 * that exist for the length of a call, and a migration is a cost paid on every
 * install and every upgrade for something that never grows.
 *
 * Nothing here is a permission decision — a call is listed if it is happening.
 * Who may fetch the list is the shared secret's business (see ServiceConfig),
 * and what may be captured is the conversation allowlist's.
 */
class ActiveCalls {
	public const KEY = 'active_calls';

	/**
	 * Calls with no end event are dropped after this long.
	 *
	 * Talk's end event can be missed — the server restarting mid-call is enough
	 * — and without a sweep such a call would be offered to the capture service
	 * for ever. Twelve hours is past any real meeting and well short of "it is
	 * still there tomorrow".
	 */
	public const ABANDONED_AFTER = 12 * 3600;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Record that a call started. Repeats are harmless: Talk raises the event
	 * again when the call flag changes (someone turns on video), and the first
	 * time it started is the time worth keeping.
	 */
	public function started(string $token, string $name, int $type): void {
		$calls = $this->all();
		if (!isset($calls[$token])) {
			$calls[$token] = [
				'token' => $token,
				'name' => $name,
				'type' => $type,
				'started_at' => $this->time->getTime(),
			];
			$this->store($calls);
			$this->logger->info('call started in {token}', ['token' => $token]);
		}
	}

	public function ended(string $token): void {
		$calls = $this->all();
		if (isset($calls[$token])) {
			unset($calls[$token]);
			$this->store($calls);
			$this->logger->info('call ended in {token}', ['token' => $token]);
		}
	}

	/**
	 * The live calls, oldest first — the order they should be picked up in.
	 *
	 * @return array<int, array{token: string, name: string, type: int, started_at: int}>
	 */
	public function current(): array {
		$calls = $this->all();
		$cutoff = $this->time->getTime() - self::ABANDONED_AFTER;

		$live = array_filter($calls,
			static fn (array $c) => (int)$c['started_at'] > $cutoff);
		if (count($live) !== count($calls)) {
			// A missed end event, swept on the next read rather than by a job:
			// this is read every few seconds anyway.
			$this->store($live);
		}

		$live = array_values($live);
		usort($live, static fn (array $a, array $b) => $a['started_at'] <=> $b['started_at']);
		return $live;
	}

	/**
	 * @return array<string, array<string, mixed>> by token
	 */
	private function all(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}
		try {
			$calls = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
		} catch (\JsonException $e) {
			// Unreadable state must not stop calls being captured: forget it and
			// start again from the next event.
			$this->logger->warning('active calls unreadable, starting over',
				['exception' => $e]);
			return [];
		}
		return is_array($calls) ? $calls : [];
	}

	/**
	 * @param array<string, array<string, mixed>> $calls
	 */
	private function store(array $calls): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY,
			json_encode($calls, JSON_UNESCAPED_UNICODE));
	}
}
