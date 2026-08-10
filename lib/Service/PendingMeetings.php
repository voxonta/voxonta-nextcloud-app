<?php

declare(strict_types=1);

namespace OCA\Voxonta\Service;

use OCA\Voxonta\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Meetings whose files we are still waiting for.
 *
 * A call ends and its audio has already gone to the gateway; what comes back —
 * a transcript within seconds, an analysis within an hour or two — arrives on
 * its own schedule. This is the list of what to ask about, and it is the only
 * memory that survives a restart between the call ending and its files landing.
 *
 * Kept in app config as JSON rather than a table: the list holds a handful of
 * entries at a time, is read once a cron tick, and a table would need a
 * migration for something that never grows.
 */
class PendingMeetings {
	public const KEY = 'pending_meetings';

	/**
	 * After this long we stop asking. The gateway keeps a meeting for seven
	 * days; a fortnight of failed collection means something is wrong that
	 * retrying will not fix, and an entry that never leaves is a slow leak.
	 */
	public const GIVE_UP_AFTER = 14 * 24 * 3600;

	public function __construct(
		private IAppConfig $appConfig,
		private ITimeFactory $time,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Start waiting for a finished call's files.
	 *
	 * @param array{token: string, name: string, type: int, session_id: string} $call
	 */
	public function add(array $call): void {
		$sessionId = (string)($call['session_id'] ?? '');
		if ($sessionId === '') {
			// A call that started before this app knew how to issue ids. There
			// is nothing to collect it by, so there is nothing to wait for.
			return;
		}
		$pending = $this->all();
		if (isset($pending[$sessionId])) {
			return;
		}
		$pending[$sessionId] = [
			'session_id' => $sessionId,
			'token' => (string)($call['token'] ?? ''),
			'name' => (string)($call['name'] ?? ''),
			'type' => (int)($call['type'] ?? 2),
			'ended_at' => $this->time->getTime(),
			// Digests already written. Re-fetching is normal — analysis lands
			// long after the transcript — and this is what keeps a second write
			// from duplicating the first.
			'written' => [],
		];
		$this->store($pending);
		$this->logger->info('waiting for the files of {session}',
			['session' => $sessionId]);
	}

	/**
	 * What to ask about, oldest first, dropping whatever we have waited too
	 * long for.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function due(): array {
		$pending = $this->all();
		$cutoff = $this->time->getTime() - self::GIVE_UP_AFTER;

		$live = array_filter($pending,
			static fn (array $m) => (int)$m['ended_at'] > $cutoff);
		if (count($live) !== count($pending)) {
			$this->logger->warning('giving up on {count} meeting(s) never collected',
				['count' => count($pending) - count($live)]);
			$this->store($live);
		}

		$live = array_values($live);
		usort($live, static fn (array $a, array $b) => $a['ended_at'] <=> $b['ended_at']);
		return $live;
	}

	/**
	 * Remember digests written for a meeting, so a later round skips them.
	 *
	 * @param array<int, string> $digests
	 */
	public function markWritten(string $sessionId, array $digests): void {
		if ($digests === []) {
			return;
		}
		$pending = $this->all();
		if (!isset($pending[$sessionId])) {
			return;
		}
		$pending[$sessionId]['written'] = array_values(array_unique(
			array_merge($pending[$sessionId]['written'] ?? [], $digests)));
		$this->store($pending);
	}

	/** Nothing further will come for this meeting. */
	public function done(string $sessionId): void {
		$pending = $this->all();
		if (isset($pending[$sessionId])) {
			unset($pending[$sessionId]);
			$this->store($pending);
			$this->logger->info('collected everything for {session}',
				['session' => $sessionId]);
		}
	}

	/** @return array<string, array<string, mixed>> by session id */
	private function all(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY, '');
		if ($raw === '') {
			return [];
		}
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			// Unreadable state is treated as empty rather than fatal: losing the
			// queue costs a few uncollected meetings, throwing here would stop
			// every call being recorded.
			$this->logger->warning('the pending list was unreadable — starting over');
			return [];
		}
		return $decoded;
	}

	/** @param array<string, array<string, mixed>> $pending */
	private function store(array $pending): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY,
			json_encode($pending, JSON_UNESCAPED_UNICODE) ?: '');
	}
}
