<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Telling the room that its meeting is ready.
 *
 * Files appearing in a folder is not news anybody sees. The call happened in a
 * conversation, so that is where the result belongs — one short message with
 * links, posted once, when there is nothing further to wait for.
 *
 * Posted as the bot account over Talk's own chat API: it is a participant of the
 * room already (it has to be, to be in the call), and the app holds an app
 * password for it because that is how it writes the files. Nothing new to
 * configure, and no second set of credentials in the installation.
 *
 * The links carry readable names rather than file names. What a meeting's files
 * are called is a matter for the archive — dated, sortable, machine-made. What
 * a person reads in a chat should say what it is.
 */
class ChatAnnouncer {
	/** A chat post is not worth holding a cron tick open for. */
	private const TIMEOUT = 15;

	public function __construct(
		private IClientService $clientService,
		private BotAccount $botAccount,
		private IURLGenerator $urlGenerator,
		private IL10N $l10n,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Announce a finished meeting. Returns whether the room was told.
	 *
	 * Failing is not worth a retry: the files are written and shared either way,
	 * and a second attempt would as likely post twice as succeed.
	 *
	 * @param array<string, string> $links readable name => absolute URL
	 */
	public function announce(string $token, array $links): bool {
		if ($token === '' || $links === []) {
			return false;
		}
		$credentials = $this->botAccount->credentials();
		if ($credentials === null) {
			$this->logger->warning('no bot account — cannot announce {token}',
				['token' => $token]);
			return false;
		}

		try {
			$this->clientService->newClient()->post(
				$this->urlGenerator->getAbsoluteURL(
					'/ocs/v2.php/apps/spreed/api/v1/chat/' . rawurlencode($token)), [
					'auth' => [$credentials['user'], $credentials['password']],
					'headers' => [
						'OCS-APIRequest' => 'true',
						'Accept' => 'application/json',
					],
					'body' => ['message' => $this->compose($links)],
					'timeout' => self::TIMEOUT,
				]);
		} catch (\Throwable $e) {
			// The common cause is the bot not being a participant of that room
			// any more — a conversation it was removed from after the call.
			$this->logger->warning('could not announce in {token}: {message}',
				['token' => $token, 'message' => $e->getMessage()]);
			return false;
		}

		$this->logger->info('announced {count} file(s) in {token}',
			['count' => count($links), 'token' => $token]);
		return true;
	}

	/** @param array<string, string> $links */
	private function compose(array $links): string {
		$parts = [];
		foreach ($links as $label => $url) {
			$parts[] = '[' . $label . '](' . $url . ')';
		}
		return $this->l10n->t('The meeting has been transcribed: %s',
			[implode(' · ', $parts)]);
	}
}
