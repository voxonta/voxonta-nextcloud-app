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

	/**
	 * How long to wait after a failed attempt, doubling each time up to the cap.
	 * The base matches the cron interval — retrying sooner than the job runs
	 * would be pointless — and the cap keeps a hopeless entry to four requests
	 * a day instead of nearly three hundred.
	 */
	public const BACKOFF_BASE = 5 * 60;
	public const BACKOFF_CAP = 6 * 3600;

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
	 * What to ask about now, oldest first: too old to bother with is dropped,
	 * and whatever is still backing off after failures is skipped.
	 *
	 * The skipping is the point. A round takes a handful of meetings from the
	 * front of this list, so entries that can never be collected — a call whose
	 * audio never reached the gateway, say — used to hold the front of the queue
	 * for a fortnight and starve every meeting behind them. On 2026-08-11 six
	 * such entries kept the batch full and no new meeting was collected at all.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	/**
	 * Session ids currently being waited for, whatever their backoff.
	 *
	 * Used by the sweep to tell "the gateway holds files we already know about"
	 * from "files for a meeting this app has forgotten".
	 *
	 * @return array<int, string>
	 */
	public function knownSessions(): array {
		return array_keys($this->all());
	}

	public function due(): array {
		$pending = $this->all();
		$now = $this->time->getTime();
		$cutoff = $now - self::GIVE_UP_AFTER;

		$live = array_filter($pending,
			static fn (array $m) => (int)$m['ended_at'] > $cutoff);
		if (count($live) !== count($pending)) {
			$this->logger->warning('giving up on {count} meeting(s) never collected',
				['count' => count($pending) - count($live)]);
			$this->store($live);
		}

		$ready = array_filter($live,
			static fn (array $m) => (int)($m['next_try'] ?? 0) <= $now);

		$ready = array_values($ready);
		usort($ready, static fn (array $a, array $b) => $a['ended_at'] <=> $b['ended_at']);
		return $ready;
	}

	/**
	 * The gateway had nothing for this meeting: ask again later, and later still
	 * next time.
	 *
	 * Doubling from the cron interval up to a cap means a meeting that will
	 * never arrive costs a handful of requests a day instead of one every five
	 * minutes, and — more importantly — stops standing in front of meetings that
	 * are ready. A single failure barely delays anything; a persistent one steps
	 * aside on its own.
	 */
	public function missed(string $sessionId): void {
		$pending = $this->all();
		if (!isset($pending[$sessionId])) {
			return;
		}
		$misses = (int)($pending[$sessionId]['misses'] ?? 0) + 1;
		$wait = min(self::BACKOFF_CAP, self::BACKOFF_BASE * (2 ** ($misses - 1)));
		$pending[$sessionId]['misses'] = $misses;
		$pending[$sessionId]['next_try'] = $this->time->getTime() + $wait;
		$this->store($pending);

		// Logged once, when it starts to matter: a meeting nobody will ever
		// collect should be visible before it has been silently retried for days.
		if ($wait >= self::BACKOFF_CAP) {
			$this->logger->warning(
				'{session}: {misses} failed attempts, backing off to {hours}h',
				['session' => $sessionId, 'misses' => $misses,
					'hours' => (int)round($wait / 3600)],
			);
		}
	}

	/** The gateway answered: start over from a short interval. */
	public function answered(string $sessionId): void {
		$pending = $this->all();
		if (!isset($pending[$sessionId]) || (int)($pending[$sessionId]['misses'] ?? 0) === 0) {
			return;
		}
		$pending[$sessionId]['misses'] = 0;
		$pending[$sessionId]['next_try'] = 0;
		$this->store($pending);
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
