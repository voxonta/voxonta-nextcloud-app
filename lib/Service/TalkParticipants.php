<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * How many people are actually in a call right now.
 *
 * The capture service needs this for one thing: a one-to-one call rings before
 * it is answered, and joining the signaling room while it rings makes Nextcloud
 * think it was answered and stops the ringing. So the service waits until a
 * second person is in the call — and to wait, it has to be able to ask.
 *
 * Talk's own ParticipantService answers it (getParticipantsInCall), which is
 * why this reaches for Talk's classes. It does so lazily and defensively: the
 * app installs and runs on an instance without Talk, where these classes do not
 * exist, so nothing here is constructor-injected and every failure returns null
 * — "cannot tell", which the caller treats as "do not block".
 */
class TalkParticipants {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	/**
	 * People currently in the call in this room, or null when it cannot be told
	 * — Talk absent, room gone, or any error. Null is not zero: zero would tell
	 * the caller to keep waiting, and a room we cannot read is not a reason to
	 * hold a call back.
	 */
	public function countInCall(string $token): ?int {
		if (!class_exists('\OCA\Talk\Manager')
			|| !class_exists('\OCA\Talk\Service\ParticipantService')) {
			return null;
		}
		try {
			$manager = Server::get(\OCA\Talk\Manager::class);
			$participants = Server::get(\OCA\Talk\Service\ParticipantService::class);
			$room = $manager->getRoomByToken($token);
			return count($participants->getParticipantsInCall($room));
		} catch (\Throwable $e) {
			// A room that just ended, or a Talk version whose signature moved:
			// either way, not knowing must not stop a call being captured.
			$this->logger->debug('could not count participants in {token}: {msg}', [
				'token' => $token, 'msg' => $e->getMessage(),
			]);
			return null;
		}
	}

	/**
	 * The user ids of everyone in a room, for sharing a meeting's files with
	 * them. Guests have no id and are skipped: there is nobody to share with.
	 *
	 * An empty list rather than null on failure — the caller writes the files
	 * either way, and a share it could not work out is not worth losing them
	 * over.
	 *
	 * @return array<int, string>
	 */
	public function userIds(string $token): array {
		if (!class_exists('\OCA\Talk\Manager')
			|| !class_exists('\OCA\Talk\Service\ParticipantService')) {
			return [];
		}
		try {
			$manager = Server::get(\OCA\Talk\Manager::class);
			$participants = Server::get(\OCA\Talk\Service\ParticipantService::class);
			$room = $manager->getRoomByToken($token);

			$ids = [];
			foreach ($participants->getParticipantsForRoom($room) as $participant) {
				$uid = $participant->getAttendee()->getActorId();
				$type = $participant->getAttendee()->getActorType();
				if ($type === 'users' && $uid !== '') {
					$ids[$uid] = true;
				}
			}
			return array_keys($ids);
		} catch (\Throwable $e) {
			$this->logger->debug('could not list participants of {token}: {msg}', [
				'token' => $token, 'msg' => $e->getMessage(),
			]);
			return [];
		}
	}
}
