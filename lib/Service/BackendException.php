<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

/**
 * Carries the status the client should see, so controllers do not have to
 * translate failure modes back into HTTP one by one.
 */
class BackendException extends \RuntimeException {
	public function __construct(string $message, private int $status) {
		parent::__construct($message);
	}

	public function getStatus(): int {
		return $this->status;
	}
}
