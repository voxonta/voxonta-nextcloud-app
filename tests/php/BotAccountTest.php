<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\BackendException;
use OCA\Voxonta\Service\BotAccount;
use OC\Authentication\Token\IProvider;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Setting up the account the capture service signs in as.
 *
 * Two things matter here beyond the happy path. The app must only ever touch
 * accounts it created — regenerating a password on an account the administrator
 * made is not its call. And the credential it hands out must be an app password
 * that was actually minted and stored, not the account's login.
 */
class BotAccountTest extends TestCase {
	/** @var array<string, mixed> */
	private array $stored = [];
	/** @var string[] tokens minted, by the password value */
	private array $tokens = [];
	private array $invalidated = [];

	private function bot(bool $userExists = false): BotAccount {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $a, string $k, string $d = '') => (string)($this->stored[$k] ?? $d));
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $a, string $k, bool $d = false) => (bool)($this->stored[$k] ?? $d));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $a, string $k, string $v, bool $lazy = false, bool $sensitive = false) {
				$this->stored[$k] = $v;
				return true;
			});
		$appConfig->method('setValueBool')->willReturnCallback(
			function (string $a, string $k, bool $v) {
				$this->stored[$k] = $v;
				return true;
			});

		$users = $this->createMock(IUserManager::class);
		$existing = $userExists ? $this->createMock(IUser::class) : null;
		$users->method('get')->willReturn($existing);
		$users->method('createUser')->willReturnCallback(
			function () {
				$user = $this->createMock(IUser::class);
				// A disabled account cannot authenticate even with an app
				// password, so disabling it would break the sign-in it exists
				// for. If this is ever called with false, the account is dead.
				$user->method('setEnabled')->willReturnCallback(
					function (bool $enabled) {
						$this->assertTrue($enabled,
							'the bot account must stay enabled or its app password will not work');
					});
				return $user;
			});

		$random = $this->createMock(ISecureRandom::class);
		$n = 0;
		$random->method('generate')->willReturnCallback(
			function () use (&$n) { return 'random-' . (++$n); });

		$provider = $this->createMock(IProvider::class);
		$provider->method('generateToken')->willReturnCallback(
			function (string $token, string $uid, string $login, ?string $pw, string $name) {
				$this->tokens[$name] = $token;
				return $this->createMock(\OCP\Authentication\Token\IToken::class);
			});
		$provider->method('invalidateTokensOfUser')->willReturnCallback(
			function (string $uid, ?string $name) {
				$this->invalidated[] = $name;
			});

		return new BotAccount($users, $appConfig, $random, $provider,
			$this->createMock(LoggerInterface::class));
	}

	public function testProvisioningCreatesAnAccountAndReturnsAnAppPassword(): void {
		$result = $this->bot()->provision();

		$this->assertSame(BotAccount::MANAGED_UID, $result['user']);
		$this->assertNotSame('', $result['password']);
		// What it returns is the token that was minted, not the login password.
		$this->assertSame($this->tokens['Voxonta service'], $result['password']);
	}

	public function testTheCredentialsAreStoredForTheServiceToFetch(): void {
		$bot = $this->bot();
		$provisioned = $bot->provision();

		$creds = $bot->credentials();

		$this->assertSame($provisioned['user'], $creds['user']);
		$this->assertSame($provisioned['password'], $creds['password']);
	}

	public function testAnInstanceWithNoAccountHasNoCredentials(): void {
		$this->assertNull($this->bot()->credentials(),
			'the service must be told there is nothing to sign in as');
	}

	public function testProvisioningIsMarkedAsManaged(): void {
		$bot = $this->bot();
		$bot->provision();

		$this->assertTrue($bot->status()['managed']);
	}

	public function testAnAdministratorsOwnAccountIsNotManaged(): void {
		$bot = $this->bot();
		$bot->useExisting('their-bot', 'their-app-password');

		$status = $bot->status();
		$this->assertSame('their-bot', $status['user']);
		$this->assertFalse($status['managed']);
		$this->assertSame(
			['user' => 'their-bot', 'password' => 'their-app-password'],
			$bot->credentials());
	}

	public function testTheAppWillNotRegeneratePasswordsForAnAccountItDoesNotOwn(): void {
		$bot = $this->bot();
		$bot->useExisting('their-bot', 'their-app-password');

		try {
			$bot->regenerate();
			$this->fail('expected a refusal to touch an unmanaged account');
		} catch (BackendException $e) {
			$this->assertSame(400, $e->getStatus());
		}
	}

	public function testRegeneratingReplacesTheOldPassword(): void {
		$bot = $this->bot();
		$first = $bot->provision()['password'];

		$second = $bot->regenerate();

		$this->assertNotSame($first, $second);
		$this->assertContains('Voxonta service', $this->invalidated,
			'the previous token must be revoked, not left live beside the new one');
		$this->assertSame($second, $bot->credentials()['password']);
	}

	public function testProvisioningTwiceReusesTheAccount(): void {
		// The account already exists (a reinstall, or a second click): it must
		// not error, just mint a fresh password on the same user.
		$result = $this->bot(userExists: true)->provision();

		$this->assertSame(BotAccount::MANAGED_UID, $result['user']);
		$this->assertNotSame('', $result['password']);
	}
}
