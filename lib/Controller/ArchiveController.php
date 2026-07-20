<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Controller;

use OCA\DoneTranscription\Service\ArchiveAccess;
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
		private ArchiveAccess $access,
		private LoggerInterface $logger,
		private ?string $userId,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * @param string $scope 'mine' (default) or 'all' for the whole archive,
	 *                      which requires membership of an archive group
	 * @param string $with  only meetings this person attended — the "who talked
	 *                      to whom" view, and only meaningful with scope=all
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function meetings(int $limit = 50, int $offset = 0,
		string $scope = 'mine', string $with = ''): JSONResponse {
		try {
			$user = $this->requireUser();
			$everything = $scope === 'all' && $this->access->canSeeEverything($user);

			$query = [
				'limit' => max(1, min($limit, 200)),
				'offset' => max(0, $offset),
			];
			if ($everything) {
				// Asking about someone else is exactly what the wider access is
				// for, so the filter is honoured only on this branch.
				if ($with !== '') {
					$query['user'] = $with;
				}
			} else {
				// Not a fallback to "everything": a request for scope=all
				// without the right quietly becomes a request for your own
				// meetings, which is the safe reading of an ambiguous ask.
				$query['user'] = $user;
			}

			$data = $this->backend->get('/v1/meetings', $query);
		} catch (BackendException $e) {
			return $this->failure($e);
		}

		return new JSONResponse([
			'meetings' => $data['meetings'] ?? [],
			// The frontend cannot work this out on its own, and guessing it
			// would mean showing a tab that 403s when clicked.
			'can_see_everything' => $everything || $this->maySeeEverything(),
		]);
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
	 * Whether the caller may read the whole archive, without failing the
	 * request if that cannot be determined.
	 */
	private function maySeeEverything(): bool {
		try {
			return $this->userId !== null
				&& $this->access->canSeeEverything($this->userId);
		} catch (BackendException) {
			return false;
		}
	}

	/**
	 * Fetch a meeting, having confirmed the caller may read it.
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
		if ($this->access->canSeeEverything($user)) {
			return $meeting;
		}
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
