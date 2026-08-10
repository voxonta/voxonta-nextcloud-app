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

		foreach (array_slice($this->pending->due(), 0, self::BATCH) as $meeting) {
			try {
				$this->collect($meeting);
			} catch (\Throwable $e) {
				// One meeting's trouble must not stop the others: a failure
				// leaves it pending and the next tick tries again.
				$this->logger->error('collecting {session} failed', [
					'session' => $meeting['session_id'] ?? '?',
					'exception' => $e,
				]);
			}
		}
	}

	/** @param array<string, mixed> $meeting */
	private function collect(array $meeting): void {
		$sessionId = (string)$meeting['session_id'];
		$state = $this->gateway->meeting($sessionId);
		if ($state === null) {
			return;  // unreachable or not known yet — ask again next tick
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
			// Terminal: everything that will exist, exists. A failed meeting is
			// terminal too — retrying it would only ask the same question.
			if ($state['status'] === 'failed') {
				$this->logger->warning('{session} finished badly: {detail}',
					['session' => $sessionId, 'detail' => $state['detail']]);
			} else {
				// Once, here, rather than as each file lands: a room told three
				// times over an hour is a room that mutes the bot.
				$this->announce((string)($meeting['token'] ?? ''), $state['artifacts']);
			}
			$this->pending->done($sessionId);
		}
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
