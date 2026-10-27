<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\BackgroundJob\SendDirectory;
use OCA\Voxonta\Service\GatewayClient;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The accounts the gateway matches a meeting's people against.
 *
 * Only what a match needs, and only accounts that can still sign in: a person
 * who left must not be the match for the next person with their name.
 */
class SendDirectoryTest extends TestCase {
	/** @var array<int, array<int, array<string, string>>> */
	private array $sent = [];

	private function user(string $uid, string $name, ?string $email, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($name);
		$user->method('getEMailAddress')->willReturn($email);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}

	/** @param array<int, IUser> $users */
	private function sendFor(array $users, bool $configured = true, bool $allowed = true): void {
		$gateway = $this->createMock(GatewayClient::class);
		$gateway->method('configured')->willReturn($configured);
		$gateway->method('directory')->willReturnCallback(function (array $accounts): bool {
			$this->sent[] = $accounts;
			return true;
		});
		$manager = $this->createMock(IUserManager::class);
		$manager->method('callForAllUsers')->willReturnCallback(
			static function (\Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			});

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueBool')->willReturnCallback(
			static fn (string $app, string $key, bool $default) => $allowed);

		$job = new SendDirectory($this->createMock(ITimeFactory::class), $gateway, $manager,
			$appConfig, $this->createMock(LoggerInterface::class));
		(new \ReflectionMethod($job, 'run'))->invoke($job, null);
	}

	public function testSendsLoginNameAndAddressOfEnabledAccounts(): void {
		$this->sendFor([
			$this->user('vadim.k', 'Вадим Куницын', 'vadim.k@example.org'),
			$this->user('olga.s', 'Ольга Семёнова', null),
			$this->user('left', 'Иван Петров', 'ivan@example.org', enabled: false),
		]);

		$this->assertSame([[
			['login' => 'vadim.k', 'name' => 'Вадим Куницын', 'email' => 'vadim.k@example.org'],
			['login' => 'olga.s', 'name' => 'Ольга Семёнова', 'email' => ''],
		]], $this->sent);
	}

	public function testAnEmptyListIsNotSent(): void {
		// A user backend that did not answer would otherwise wipe the
		// gateway's list and stop every share outside Talk for an hour.
		$this->sendFor([$this->user('left', 'L', null, enabled: false)]);

		$this->assertSame([], $this->sent);
	}

	public function testNothingIsSentWithoutAGateway(): void {
		$this->sendFor([$this->user('vadim.k', 'V', null)], configured: false);

		$this->assertSame([], $this->sent);
	}

	public function testNothingIsSentUnlessTheAdministratorAllowsIt(): void {
		$this->sendFor([$this->user('vadim.k', 'V', null)], allowed: false);

		$this->assertSame([], $this->sent);
	}
}
