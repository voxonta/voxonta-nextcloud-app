<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Listener;

use OCA\DoneTranscription\AppInfo\Application;
use OCA\DoneTranscription\Service\BackendClient;
use OCA\DoneTranscription\Service\BackendException;
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
		private BackendClient $backend,
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
		} elseif (in_array($text, self::START, true)) {
			$this->setRecording($event, $message, true);
		}
	}

	private function setRecording(\OCA\Talk\Events\BotInvokeEvent $event,
		array $message, bool $recording): void {
		$token = (string)($message['target']['id'] ?? '');
		if ($token === '') {
			return;
		}

		try {
			$this->backend->post('/v1/rooms/' . rawurlencode($token) . '/recording',
				['recording' => $recording]);
		} catch (BackendException $e) {
			// Say so. Someone who has just asked not to be recorded must not be
			// left with a silence that reads as "done" — if we could not apply
			// it, the honest answer is that recording may still be running.
			$this->logger->error('could not apply the recording command in {token}', [
				'token' => $token,
				'exception' => $e,
			]);
			$event->addAnswer($recording
				? $this->l10n->t('Could not reach the transcription service, so recording was not resumed.')
				: $this->l10n->t('Could not reach the transcription service. Recording may still be running — please ask an administrator.'));
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
