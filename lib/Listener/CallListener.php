<?php

declare(strict_types=1);

namespace OCA\Voxonta\Listener;

use OCA\Voxonta\Service\ActiveCalls;
use OCA\Voxonta\Service\PendingMeetings;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Talk telling us a call started or ended.
 *
 * This is the whole reason the capture service can stop reading Nextcloud's
 * database: the two events carry everything needed to know a call is live, and
 * they arrive as it happens rather than up to five seconds later.
 *
 * Typed loosely against Talk's classes on purpose. The app has to install and
 * run on an instance without Talk — the archive is useful on its own — so this
 * file must not mention a class that would then be missing. Application::register
 * only wires it up when Talk is there.
 *
 * @template-implements IEventListener<Event>
 */
class CallListener implements IEventListener {
	public function __construct(
		private ActiveCalls $calls,
		private PendingMeetings $pending,
		private LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		try {
			if (!method_exists($event, 'getRoom')) {
				return;
			}
			$room = $event->getRoom();

			// Ending is decided by the class name rather than by asking the room
			// whether it is active: at the moment the end event fires, the room
			// may not have been written back yet.
			$ended = str_contains($event::class, 'CallEnded');
			if ($ended) {
				$call = $this->calls->ended($room->getToken());
				if ($call !== null) {
					// Its audio is already with the gateway; from here on the
					// only thing left is to collect what it produced.
					$this->pending->add($call);
				}
				return;
			}

			$this->calls->started($room->getToken(), $room->getName(), $room->getType());
		} catch (\Throwable $e) {
			// A failure here must never break the call it is reporting on.
			$this->logger->error('could not record a call event', ['exception' => $e]);
		}
	}
}
