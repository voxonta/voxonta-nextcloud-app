<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCA\DoneTranscription\AppInfo\Application;
use OC\Authentication\Token\IProvider;
use OCP\Authentication\Token\IToken;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use Psr\Log\LoggerInterface;

/**
 * The Nextcloud account the capture service signs in as.
 *
 * Capturing a call means joining it as a participant and writing the transcript
 * back — both of which are things a Nextcloud user does, so the service needs an
 * account. Making the administrator create one by hand, then copy its password
 * into the service, is exactly the manual step that keeps this from being
 * something they can install for themselves.
 *
 * So the app can create the account: a disabled-for-login technical user and an
 * app password scoped to it. Only that app password ever leaves here, and it is
 * shown to the administrator once — the way Nextcloud shows any app password —
 * because after generation not even Nextcloud can read it back.
 *
 * An administrator who would rather not have the app create users keeps the
 * choice: they make the account themselves and enter its name and an app
 * password of their own. This class only owns the accounts it made, and never
 * touches one it did not.
 */
class BotAccount {
	/** appconfig keys. */
	public const KEY_USER = 'bot_user';
	/**
	 * The app password, stored sensitive (encrypted at rest, hidden from
	 * config reports). It has to be stored rather than only shown once: the
	 * capture service fetches it from /config, so that an administrator enters
	 * nothing on the Nextcloud side but a button press. An app password, not a
	 * login one — scoped, revocable, and the account cannot be logged into — so
	 * what a leak of it grants is bounded and can be cut off in one place.
	 */
	public const KEY_PASSWORD = 'bot_password';
	/** Set when the app created the account, so it only ever removes its own. */
	public const KEY_MANAGED = 'bot_managed';

	/** The uid the app uses for the account it creates. */
	public const MANAGED_UID = 'done-transcription-bot';

	public function __construct(
		private IUserManager $userManager,
		private IAppConfig $appConfig,
		private ISecureRandom $random,
		// The private provider, not OCP's: minting an app password is
		// generateToken(), which the published OCP interface does not expose,
		// though every app that creates app passwords reaches for this one. A
		// test stub stands in for it, since it is absent from the OCP stubs.
		private IProvider $tokenProvider,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Create the technical account and return an app password for it.
	 *
	 * Returned so it can be shown to the administrator once, and stored (below,
	 * sensitive) so the capture service can fetch it. Losing the shown copy means
	 * regenerating, which the administrator can do — the point of showing it is a
	 * value they can paste elsewhere if they want, not the only copy.
	 *
	 * @return array{user: string, password: string}
	 * @throws BackendException if the account cannot be made
	 */
	public function provision(): array {
		$uid = self::MANAGED_UID;

		$user = $this->userManager->get($uid);
		if ($user === null) {
			// A long random login password that is never used or kept: login is
			// disabled and the service authenticates with the app password
			// below, not this.
			$loginPassword = $this->random->generate(30,
				ISecureRandom::CHAR_ALPHANUMERIC);
			try {
				$user = $this->userManager->createUser($uid, $loginPassword);
			} catch (\Throwable $e) {
				$this->logger->error('could not create the bot account',
					['exception' => $e]);
				throw new BackendException('could not create the account',
					\OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			if (!$user instanceof IUser) {
				throw new BackendException('could not create the account',
					\OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR);
			}
			$user->setDisplayName('Транскрибация');
			// No interactive login: the account is for the service, and one that
			// can also be logged into is a second door to guard.
			$user->setEnabled(false);
		}

		$this->appConfig->setValueString(Application::APP_ID, self::KEY_USER, $uid);
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_MANAGED, true);

		$password = $this->freshAppPassword($uid);
		return ['user' => $uid, 'password' => $password];
	}

	/**
	 * Point at an account the administrator made themselves.
	 *
	 * The app holds neither the password nor a token for it — the administrator
	 * gave the service its own app password directly. All that is kept is which
	 * account it is, and that the app does not own it.
	 */
	public function useExisting(string $uid, string $appPassword): void {
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_USER, trim($uid));
		// Their password, kept the same sensitive way as a managed one — the
		// capture service reads both through the same path.
		$this->appConfig->setValueString(Application::APP_ID, self::KEY_PASSWORD,
			$appPassword, sensitive: true);
		$this->appConfig->setValueBool(Application::APP_ID, self::KEY_MANAGED, false);
	}

	/**
	 * The credentials the capture service signs in to Nextcloud with, or null
	 * when no account has been set up. For ServiceConfig to hand over — the
	 * secret gating that endpoint is what protects them.
	 *
	 * @return array{user: string, password: string}|null
	 */
	public function credentials(): ?array {
		$uid = $this->appConfig->getValueString(Application::APP_ID, self::KEY_USER, '');
		$password = $this->appConfig->getValueString(
			Application::APP_ID, self::KEY_PASSWORD, '');
		if ($uid === '' || $password === '') {
			return null;
		}
		return ['user' => $uid, 'password' => $password];
	}

	/**
	 * A new app password for the managed account, invalidating the old.
	 *
	 * For rotation, and for the case where the one shown at creation was lost.
	 * Refused for an account the app does not own: its passwords are the
	 * administrator's to manage, in Nextcloud's own settings.
	 *
	 * @throws BackendException
	 */
	public function regenerate(): string {
		if (!$this->isManaged()) {
			throw new BackendException('not a managed account',
				\OCP\AppFramework\Http::STATUS_BAD_REQUEST);
		}
		$uid = $this->appConfig->getValueString(Application::APP_ID, self::KEY_USER, '');
		if ($uid === '') {
			throw new BackendException('no account to regenerate for',
				\OCP\AppFramework\Http::STATUS_BAD_REQUEST);
		}
		return $this->freshAppPassword($uid);
	}

	/**
	 * @return array{user: string, managed: bool, exists: bool}
	 */
	public function status(): array {
		$uid = $this->appConfig->getValueString(Application::APP_ID, self::KEY_USER, '');
		return [
			'user' => $uid,
			'managed' => $this->isManaged(),
			'exists' => $uid !== '' && $this->userManager->get($uid) !== null,
		];
	}

	private function isManaged(): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, self::KEY_MANAGED, false);
	}

	/**
	 * Mint an app password, replacing any this app made before.
	 *
	 * Old ones under the same name are invalidated first so a rotation actually
	 * revokes the previous credential rather than leaving both live.
	 */
	private function freshAppPassword(string $uid): string {
		$name = 'Done Transcription service';
		try {
			$this->tokenProvider->invalidateTokensOfUser($uid, $name);

			$password = $this->random->generate(72,
				ISecureRandom::CHAR_UPPER . ISecureRandom::CHAR_LOWER
				. ISecureRandom::CHAR_DIGITS);
			$this->tokenProvider->generateToken(
				$password,
				$uid,
				$uid,
				null,
				$name,
				IToken::PERMANENT_TOKEN,
				IToken::DO_NOT_REMEMBER,
			);
			$this->appConfig->setValueString(Application::APP_ID, self::KEY_PASSWORD,
				$password, sensitive: true);
			return $password;
		} catch (\Throwable $e) {
			$this->logger->error('could not mint a bot app password',
				['exception' => $e]);
			throw new BackendException('could not create an app password',
				\OCP\AppFramework\Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}
}
