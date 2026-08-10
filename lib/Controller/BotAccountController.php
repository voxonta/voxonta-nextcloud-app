<?php

declare(strict_types=1);

namespace OCA\Voxonta\Controller;

use OCA\Voxonta\Service\BackendException;
use OCA\Voxonta\Service\BotAccount;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Setting up the account the capture service signs in as.
 *
 * The administrator's side of the two paths: have the app create the technical
 * account, or point it at one they made. Every method here is an administrator
 * action from the settings page — unlike ServiceController, which the capture
 * service reaches with the shared secret. Nextcloud's own admin-section auth
 * gates these; there is no NoAdminRequired attribute, so a non-admin cannot
 * reach them at all.
 *
 * The two that mint or change a credential require the administrator to
 * re-enter their password first (PasswordConfirmationRequired), the same guard
 * Nextcloud puts on creating an app password anywhere else.
 */
class BotAccountController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private BotAccount $botAccount,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Create the technical account, or mint a fresh password for the existing
	 * one. Returns the password once — it is not readable afterwards.
	 */
	#[PasswordConfirmationRequired]
	public function provision(): JSONResponse {
		try {
			return new JSONResponse($this->botAccount->provision());
		} catch (BackendException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}

	/**
	 * Use an account the administrator made themselves.
	 */
	#[PasswordConfirmationRequired]
	public function useExisting(string $user = '', string $password = ''): JSONResponse {
		$user = trim($user);
		if ($user === '' || $password === '') {
			return new JSONResponse(['message' => 'user and password are required'],
				Http::STATUS_BAD_REQUEST);
		}
		$this->botAccount->useExisting($user, $password);
		return new JSONResponse($this->botAccount->status());
	}

	/**
	 * A new password for the managed account, replacing the old.
	 */
	#[PasswordConfirmationRequired]
	public function regenerate(): JSONResponse {
		try {
			return new JSONResponse(['password' => $this->botAccount->regenerate()]);
		} catch (BackendException $e) {
			return new JSONResponse(['message' => $e->getMessage()], $e->getStatus());
		}
	}
}
