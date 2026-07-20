<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Controller;

use OCA\DoneTranscription\Service\BackendClient;
use OCA\DoneTranscription\Service\BackendException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The archive, scoped to whoever is asking.
 *
 * The transcription service isolates by tenant, not by user — it will happily
 * return the whole company's meetings. Nextcloud knows who is asking and it
 * does not, so that filter has to be applied here, and it is deliberately not
 * optional: every handler derives the user from the session. A `?user=` from
 * the browser is a request to read someone else's calls.
 */
class ArchiveController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BackendClient $backend,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function meetings(int $limit = 50, int $offset = 0): JSONResponse {
		try {
			$user = $this->requireUser();
			$data = $this->backend->get('/v1/meetings', [
				'user' => $user,
				'limit' => max(1, min($limit, 200)),
				'offset' => max(0, $offset),
			]);
		} catch (BackendException $e) {
			return $this->failure($e);
		}

		return new JSONResponse(['meetings' => $data['meetings'] ?? []]);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function meeting(string $sessionId): JSONResponse {
		try {
			return new JSONResponse($this->meetingIfAttended($sessionId));
		} catch (BackendException $e) {
			return $this->failure($e);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function transcript(string $sessionId): JSONResponse {
		return $this->guarded($sessionId, '/transcript');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function analysis(string $sessionId): JSONResponse {
		return $this->guarded($sessionId, '/analysis');
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function artifact(string $sessionId, string $name): JSONResponse {
		return $this->guarded($sessionId, '/analysis/' . rawurlencode($name));
	}

	private function guarded(string $sessionId, string $suffix): JSONResponse {
		try {
			$this->meetingIfAttended($sessionId);
			return new JSONResponse($this->backend->get(
				'/v1/meetings/' . rawurlencode($sessionId) . $suffix));
		} catch (BackendException $e) {
			return $this->failure($e);
		}
	}

	/**
	 * Fetch a meeting, having confirmed the caller took part in it.
	 *
	 * Knowing a session id is not authorisation: ids travel in links and chat
	 * messages. Without this check one forwarded URL would open a call to
	 * someone who was never in it.
	 *
	 * @throws BackendException
	 */
	private function meetingIfAttended(string $sessionId): array {
		$user = $this->requireUser();
		$meeting = $this->backend->get('/v1/meetings/' . rawurlencode($sessionId));

		$participants = $meeting['participants'] ?? [];
		if (!is_array($participants) || !in_array($user, $participants, true)) {
			$this->logger->warning('{user} asked for meeting {id} they did not attend', [
				'user' => $user,
				'id' => $sessionId,
			]);
			// 404, not 403: "forbidden" would confirm the meeting exists.
			throw new BackendException('not found', Http::STATUS_NOT_FOUND);
		}
		return $meeting;
	}

	/**
	 * @throws BackendException
	 */
	private function requireUser(): string {
		if ($this->userId === null || $this->userId === '') {
			// Should be unreachable behind Nextcloud's own auth, but if we
			// cannot tell who is asking, show nothing rather than everything.
			throw new BackendException('unknown user', Http::STATUS_UNAUTHORIZED);
		}
		return $this->userId;
	}

	private function failure(BackendException $e): JSONResponse {
		return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
	}
}
