<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Controller;

use OCA\DoneTranscription\Service\ServiceConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * The transcription service's own two endpoints.
 *
 * Reached by a machine, not a person, so they are outside Nextcloud's session:
 * the service presents the shared secret from the admin settings as a bearer
 * token. Everything else in this app answers only to a logged-in user reading
 * their own files; these two answer only to that secret, and hold nothing about
 * anyone's calls — settings out, a heartbeat in.
 */
class ServiceController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private ServiceConfig $config,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * The settings, for the service to apply.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function config(): JSONResponse {
		if (!$this->authorised()) {
			return $this->refuse();
		}
		return new JSONResponse($this->config->forService());
	}

	/**
	 * The service reporting that it is running.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function heartbeat(string $version = '', string $note = ''): JSONResponse {
		if (!$this->authorised()) {
			return $this->refuse();
		}
		$this->config->reportIn($version, $note);
		// The settings come back with it: a service that reports every minute
		// then needs no second request to notice a setting changed.
		return new JSONResponse($this->config->forService());
	}

	/**
	 * The secret, from an Authorization header or an X-Transcription-Token one.
	 *
	 * Both because a bearer token is the convention, while some proxies strip
	 * Authorization headers they did not issue themselves.
	 */
	private function authorised(): bool {
		$header = (string)$this->request->getHeader('Authorization');
		$presented = str_starts_with($header, 'Bearer ')
			? substr($header, 7)
			: (string)$this->request->getHeader('X-Transcription-Token');

		if (!$this->config->authenticates(trim($presented))) {
			$this->logger->warning('transcription service refused: bad or missing token');
			return false;
		}
		return true;
	}

	private function refuse(): JSONResponse {
		// No detail: a caller without the secret learns only that it was wrong,
		// not whether one is configured at all.
		return new JSONResponse(['message' => 'unauthorised'],
			Http::STATUS_UNAUTHORIZED);
	}
}
