<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Controller;

use OCA\DoneTranscription\Service\BackendException;
use OCA\DoneTranscription\Service\FileArchive;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The archive.
 *
 * There is no permission check in this file, and that is the design rather than
 * an omission. Meetings are files in Nextcloud, shared with the people who were
 * in the call, and every read goes through the *caller's own* folder. A meeting
 * they were not given is not findable — so there is no rule here to enforce, to
 * forget, or to widen by mistake.
 *
 * The previous version proxied a service that returned the whole company's
 * meetings and filtered them here. That filter was the only thing standing
 * between one employee and everyone else's conversations. Now the platform
 * holds it, along with sharing, trash, versions and search.
 */
class ArchiveController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private FileArchive $archive,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function meetings(int $limit = 50, int $offset = 0,
		string $query = '', int $from = 0, int $to = 0,
		string $room = ''): JSONResponse {
		if (!$this->identified()) {
			return $this->unauthorised();
		}

		$page = $this->archive->list(
			$this->userId,
			max(1, min($limit, 200)),
			max(0, $offset),
			trim($query),
			max(0, $from),
			max(0, $to),
			trim($room),
		);
		return new JSONResponse($page);
	}

	/**
	 * The conversations to offer as a filter.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function rooms(): JSONResponse {
		if (!$this->identified()) {
			return $this->unauthorised();
		}
		return new JSONResponse(['rooms' => $this->archive->rooms($this->userId)]);
	}

	/**
	 * The summary — what people open first, and usually all they read.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function summary(string $sessionId): JSONResponse {
		return $this->text(fn (string $user) => $this->archive->summary($user, $sessionId));
	}

	/**
	 * The verbatim transcript, fetched separately: it is wanted only when the
	 * summary left a question, and it is much the larger of the two.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function transcript(string $sessionId): JSONResponse {
		return $this->text(fn (string $user) => $this->archive->transcript($user, $sessionId));
	}

	private function text(callable $read): JSONResponse {
		if (!$this->identified()) {
			return $this->unauthorised();
		}
		try {
			return new JSONResponse(['content' => $read($this->userId)]);
		} catch (BackendException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}

	private function identified(): bool {
		return $this->userId !== null && $this->userId !== '';
	}

	private function unauthorised(): JSONResponse {
		// Should be unreachable behind Nextcloud's own auth, but if we cannot
		// tell who is asking, show nothing rather than everything.
		return new JSONResponse(['message' => 'unknown user'],
			Http::STATUS_UNAUTHORIZED);
	}
}
