<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Listener;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Service\PendingMeetings;
use OCA\DoneTranscription\Service\RecordingState;
use OCA\DoneTranscription\Service\TelemostLauncher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * The opt-out command.
 *
 * Talk calls this directly — the bot is registered under a nextcloudapp:// URL,
 * so there is no webhook, no shared secret and no REST polling. That last point
 * is what makes the command work in one-to-one calls, where the previous
 * version could not see the message at all.
 *
 * @template-implements IEventListener<Event>
 */
class BotListener implements IEventListener {
	private const STOP = ['/без-записи', '/no-record', '/no-transcribe'];
	private const START = ['/запись', '/record', '/transcribe'];

	public function __construct(
		private RecordingState $recordingState,
		private TelemostLauncher $telemost,
		private PendingMeetings $pending,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!class_exists('\OCA\Talk\Events\BotInvokeEvent')
			|| !($event instanceof \OCA\Talk\Events\BotInvokeEvent)) {
			return;
		}
		if ($event->getBotUrl() !== 'nextcloudapp://' . Application::APP_ID) {
			return;
		}

		$message = $event->getMessage();
		if (($message['object']['name'] ?? '') !== 'message') {
			return;
		}

		// Matched exactly, not by prefix: "/записать протокол" must not read as
		// the /запись command.
		$text = trim(mb_strtolower($this->contentOf($message)));
		if (in_array($text, self::STOP, true)) {
			$this->setRecording($event, $message, false);
			return;
		}
		if (in_array($text, self::START, true)) {
			$this->setRecording($event, $message, true);
			return;
		}

		// Not a command: someone may simply have shared a meeting they want
		// transcribed. Read from the original text, not the lowercased copy —
		// a link's path is case-sensitive.
		$link = $this->telemost->linkIn($this->contentOf($message));
		if ($link !== '') {
			$this->launchTelemost($event, $message, $link);
		}
	}

	/**
	 * Send a bot to a shared meeting, and remember the meeting so its files are
	 * collected when they are ready.
	 *
	 * The queue entry is what makes this more than a fire-and-forget: without
	 * it the meeting would be transcribed and nobody would ever fetch the
	 * result. The room the link was posted in becomes the meeting's room, so
	 * the files are shared with the people who were talking about it.
	 */
	private function launchTelemost(\OCA\Talk\Events\BotInvokeEvent $event,
		array $message, string $link): void {
		if (!$this->telemost->configured()) {
			return;  // no launcher set up — a shared link is just a link
		}

		$sessionId = $this->telemost->launch($link);
		if ($sessionId === '') {
			// Unreachable, refused, or at capacity. Saying so is better than a
			// silence someone reads as "it is being recorded".
			$event->addAnswer($this->l10n->t(
				'Could not send a bot to that meeting. Please try again in a few minutes.'));
			return;
		}

		$token = (string)($message['target']['id'] ?? '');
		$this->pending->add([
			'session_id' => $sessionId,
			'token' => $token,
			'name' => (string)($message['target']['name'] ?? 'Telemost'),
			'type' => 2,
		]);
		$this->logger->info('sent a bot to a Telemost meeting [{session}]',
			['session' => $sessionId]);
		$event->addAnswer($this->l10n->t(
			'A bot is joining that meeting. The transcript will appear here when it ends.'));
	}

	private function setRecording(\OCA\Talk\Events\BotInvokeEvent $event,
		array $message, bool $recording): void {
		$token = (string)($message['target']['id'] ?? '');
		if ($token === '') {
			return;
		}

		try {
			// Written before the confirmation is posted: telling the room that
			// recording stopped and then failing to store it is the one order
			// of events this must never produce.
			$this->recordingState->setRecording($token, $recording);
		} catch (\Throwable $e) {
			// Someone who has just asked not to be recorded must not be left
			// with a silence that reads as "done".
			$this->logger->error('could not apply the recording command in {token}', [
				'token' => $token,
				'exception' => $e,
			]);
			$event->addAnswer($recording
				? $this->l10n->t('Could not resume recording. Please try again.')
				: $this->l10n->t('Could not stop the recording. It may still be running — please ask an administrator.'));
			return;
		}

		// The confirmation is not decoration: silence here reads as "did it
		// work?", which is exactly the wrong feeling after asking not to be
		// recorded.
		$event->addAnswer($recording
			? $this->l10n->t('Recording resumed for this call.')
			: $this->l10n->t('Recording stopped. Nothing said from now on in this call will be transcribed. Type /запись to resume.'));
	}

	private function contentOf(array $message): string {
		$content = $message['object']['content'] ?? '';
		if (is_string($content)) {
			$decoded = json_decode($content, true);
			if (is_array($decoded)) {
				return (string)($decoded['message'] ?? '');
			}
			return $content;
		}
		return is_array($content) ? (string)($content['message'] ?? '') : '';
	}
}
