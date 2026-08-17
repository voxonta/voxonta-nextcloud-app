<?php

declare(strict_types=1);

namespace OCA\Voxonta\BackgroundJob;

use OCA\Voxonta\Service\ArtifactWriter;
use OCA\Voxonta\Service\ChatAnnouncer;
use OCA\Voxonta\Service\GatewayClient;
use OCA\Voxonta\Service\PendingMeetings;
use OCA\Voxonta\Service\TalkParticipants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Collect the files of meetings that have finished.
 *
 * The call ended some time ago and its audio went to the gateway; the transcript
 * is usually ready within seconds of that, the analysis an hour or two later.
 * This asks, takes what is there, and stops asking once nothing further will
 * come.
 *
 * Polling rather than being pushed: the gateway would otherwise need to reach
 * into a Nextcloud that is often behind NAT, and an installation that has to
 * open a port is an installation nobody completes. Nextcloud's cron is the
 * natural clock for that, and a few minutes of delay on something that took an
 * hour to produce is not worth a webhook.
 */
class CollectArtifacts extends TimedJob {
	/**
	 * Meetings per run. Each is one listing plus the files not yet held, so a
	 * handful keeps a cron tick short even when several calls end together.
	 */
	private const BATCH = 5;

	/**
	 * How long a meeting the gateway has never heard of stays in the queue.
	 * Shorter than PendingMeetings::GIVE_UP_AFTER because "unknown" is a
	 * definite answer, where an unreachable gateway is not.
	 */
	private const UNKNOWN_GRACE = 24 * 3600;

	public function __construct(
		ITimeFactory $time,
		private PendingMeetings $pending,
		private GatewayClient $gateway,
		private ArtifactWriter $writer,
		private ChatAnnouncer $announcer,
		private TalkParticipants $participants,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(5 * 60);
		// Time-sensitive, though the files are safe on the gateway either way.
		// Insensitive does not mean "low priority", it means Nextcloud may defer
		// the job during busy periods — measured at 6.5 hours between runs on a
		// real installation, against the five minutes asked for here. Safe and
		// prompt are different questions, and the person waiting for their
		// meeting notes only cares about the second.
		$this->setTimeSensitivity(self::TIME_SENSITIVE);
	}

	protected function run($argument): void {
		if (!$this->gateway->configured()) {
			return;  // no gateway set up yet — nothing to collect from
		}

		$this->adopt();

		foreach (array_slice($this->pending->due(), 0, self::BATCH) as $meeting) {
			$sessionId = (string)($meeting['session_id'] ?? '');
			try {
				if ($this->collect($meeting)) {
					$this->pending->answered($sessionId);
				} else {
					$this->pending->missed($sessionId);
				}
			} catch (\Throwable $e) {
				// One meeting's trouble must not stop the others: a failure
				// leaves it pending, backing off, and the next tick tries again.
				$this->pending->missed($sessionId);
				$this->logger->error('collecting {session} failed', [
					'session' => $sessionId ?: '?',
					'exception' => $e,
				]);
			}
		}
	}

	/**
	 * Take back meetings the gateway is holding files for and this app forgot.
	 *
	 * The queue here is a memory, and it can be lost: a status read as final, a
	 * wiped config, a reinstall. Until 2026-08-17 every such loss was permanent
	 * — the files sat in the gateway until retention deleted them, and the only
	 * way anyone found out was a person asking why their transcript never
	 * arrived. Eight meetings were recovered by hand that day, the oldest a
	 * fortnight old.
	 *
	 * The gateway has always known, through the acked flag on every artifact.
	 * Asking it makes that the authority instead of this list. A meeting is
	 * adopted with the room the gateway recorded, so a forgotten meeting still
	 * knows where its result belongs.
	 *
	 * Silent when there is nothing to adopt, which is the normal case.
	 */
	private function adopt(): void {
		$waiting = $this->gateway->undelivered();
		if ($waiting === []) {
			return;
		}
		$known = array_flip($this->pending->knownSessions());

		$adopted = 0;
		foreach ($waiting as $meeting) {
			if (isset($known[$meeting['session_id']])) {
				continue;  // already being waited for, backoff and all
			}
			if ($meeting['room_token'] === '') {
				// Nothing to publish into. Rare — the gateway records the room
				// with the first frame — but guessing a room is worse than
				// saying so and leaving the files where they are.
				$this->logger->warning(
					'the gateway holds files for {session} but recorded no room',
					['session' => $meeting['session_id']]);
				continue;
			}
			$this->pending->add([
				'session_id' => $meeting['session_id'],
				'token' => $meeting['room_token'],
				'name' => $meeting['room_name'],
				'type' => 2,
			]);
			$adopted++;
		}

		if ($adopted > 0) {
			$this->logger->warning(
				'took back {count} meeting(s) the gateway still had files for',
				['count' => $adopted]);
		}
	}

	/**
	 * @param array<string, mixed> $meeting
	 * @return bool whether this meeting is settled for now; false makes the
	 *              caller back off and ask again later — either because the
	 *              gateway said nothing useful, or because it said "failed",
	 *              which a re-run can still turn into files
	 */
	private function collect(array $meeting): bool {
		$sessionId = (string)$meeting['session_id'];
		$state = $this->gateway->meeting($sessionId);
		if ($state === null) {
			// The gateway does not know this meeting and enough time has passed
			// that it never will: the call's audio never got there. Waiting out
			// the fortnight would only keep it in front of meetings that exist.
			if ($this->gateway->lastAnswerWasUnknown() && $this->hopeless($meeting)) {
				$this->logger->warning(
					'giving up on {session}: the gateway has not known it for a day',
					['session' => $sessionId],
				);
				$this->pending->done($sessionId);
			}
			return false;
		}

		$held = $meeting['written'] ?? [];
		$participants = $this->participantsOf((string)($meeting['token'] ?? ''));
		$written = [];

		foreach ($state['artifacts'] as $artifact) {
			$sha = (string)($artifact['sha256'] ?? '');
			if ($sha === '' || in_array($sha, $held, true)) {
				continue;  // already ours
			}
			$content = $this->gateway->artifact($sessionId, $sha);
			if ($content === null) {
				continue;  // fetch failed or arrived corrupt — try next tick
			}
			if ($this->writer->write($artifact, $content, $participants)) {
				$written[] = $sha;
			}
		}

		if ($written !== []) {
			// Recorded before acknowledging: if we crash between the two, the
			// worst case is the gateway keeping files a while longer, not us
			// writing them twice.
			$this->pending->markWritten($sessionId, $written);
			$this->gateway->ack($sessionId, $written);
			$this->logger->info('collected {count} file(s) for {session}',
				['count' => count($written), 'session' => $sessionId]);
		}

		if ($state['final']) {
			if ($state['status'] === 'failed') {
				// A failure is not the end of the story. The gateway re-runs an
				// analysis once the reason it fell over is fixed, and then the
				// files appear against a meeting we had already written off —
				// with nobody left to collect them. That happened on 2026-08-13:
				// the analysis broke, this job dropped the meeting minutes
				// later, and the twenty files produced by the re-run sat
				// undelivered until someone noticed by hand.
				//
				// So stay on it, backing off as with any other empty answer.
				// The queue gives up after a fortnight either way, and a
				// hopeless meeting costs four requests a day at the cap.
				$this->logger->warning('{session} finished badly, still watching: {detail}',
					['session' => $sessionId, 'detail' => $state['detail']]);
				return false;
			}
			// Terminal and good: everything that will exist, exists.
			// Announced once, here, rather than as each file lands: a room told
			// three times over an hour is a room that mutes the bot.
			$this->announce((string)($meeting['token'] ?? ''), $state['artifacts']);
			$this->pending->done($sessionId);
		}
		return true;
	}

	/**
	 * Whether a meeting the gateway does not know is worth asking about again.
	 *
	 * A day, not the fortnight the queue otherwise allows: the gateway records a
	 * meeting when the call starts, so if it still has never heard of one a day
	 * later, the audio never arrived and nothing will change that.
	 *
	 * @param array<string, mixed> $meeting
	 */
	private function hopeless(array $meeting): bool {
		$ended = (int)($meeting['ended_at'] ?? 0);
		return $ended > 0 && $this->time->getTime() - $ended > self::UNKNOWN_GRACE;
	}

	/**
	 * Tell the room, naming the files by what they are.
	 *
	 * Only the two a person actually opens. The rest of an analysis set is in
	 * the folder for whoever wants it, and listing all thirteen would bury the
	 * two that answer "what happened in that call".
	 *
	 * @param array<int, array<string, mixed>> $artifacts
	 */
	private function announce(string $token, array $artifacts): void {
		if ($token === '' || !$this->writer->publishesToChat()) {
			return;
		}

		$labels = [
			'09_Enriched_Transcript.md' => $this->l10n->t('Transcript'),
			'01_Executive_Summary.md' => $this->l10n->t('Summary'),
		];
		$links = [];
		foreach ($artifacts as $artifact) {
			$label = $labels[basename((string)($artifact['name'] ?? ''))] ?? null;
			if ($label === null || isset($links[$label])) {
				continue;
			}
			$url = $this->writer->linkTo($artifact);
			if ($url !== null) {
				$links[$label] = $url;
			}
		}

		$this->announcer->announce($token, $links);
	}

	/**
	 * Who to share with. An empty list is fine — the files are written either
	 * way, and a share that could not be worked out is not worth losing them
	 * over.
	 *
	 * @return array<int, string>
	 */
	private function participantsOf(string $token): array {
		if ($token === '') {
			return [];
		}
		try {
			return $this->participants->userIds($token);
		} catch (\Throwable $e) {
			$this->logger->debug('could not list participants of {token}: {message}',
				['token' => $token, 'message' => $e->getMessage()]);
			return [];
		}
	}
}
