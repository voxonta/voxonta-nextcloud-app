<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Which conversations have asked not to be recorded.
 *
 * Written here when somebody posts the command, read by the capture app before
 * it takes a frame. It goes through Nextcloud's own configuration rather than
 * an HTTP call to the capture service, and that is deliberate: an HTTP call can
 * fail, and a failed "stop recording" is the one failure in this product that
 * must not happen quietly. Configuration is written before the confirmation is
 * posted, so the state is committed by the time the room is told it is.
 *
 * The list is of rooms that opted *out*. Recording is the default, so an empty
 * value means "record", and losing this value fails towards the behaviour the
 * administrator configured — not towards silently recording a room that asked
 * us not to, because that room would be in the list.
 */
class RecordingState {
	private const KEY = 'rooms_opted_out';

	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function isRecording(string $token): bool {
		return !in_array($token, $this->optedOut(), true);
	}

	public function setRecording(string $token, bool $recording): void {
		$rooms = $this->optedOut();

		if ($recording) {
			$rooms = array_values(array_filter($rooms,
				static fn (string $t) => $t !== $token));
		} elseif (!in_array($token, $rooms, true)) {
			$rooms[] = $token;
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY,
			implode(',', $rooms));
	}

	/**
	 * @return string[]
	 */
	private function optedOut(): array {
		$raw = $this->appConfig->getValueString(Application::APP_ID, self::KEY);
		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}
}
