<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\TelemostLauncher;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;

/**
 * Which key and which address a launch request goes out with.
 *
 * The launcher used to have a key of its own, shared by every installation,
 * and the gateway filed whatever it launched under whoever that key belonged
 * to. With one customer nobody could tell; with two, one customer's Telemost
 * meetings would quietly land in the other's space — transcribed, analysed,
 * filed, and wrong.
 *
 * So the key that identifies this installation is the one to send.
 */
class TelemostKeyTest extends TestCase {
	/** @param array<string, string> $settings */
	private function launcher(array $settings): TelemostLauncher {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '')
				=> $settings[$key] ?? $default);

		return new TelemostLauncher(
			$this->createMock(IClientService::class),
			$appConfig,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function call(TelemostLauncher $launcher, string $method): string {
		$m = new ReflectionMethod($launcher, $method);
		return (string)$m->invoke($launcher);
	}

	public function testSendsTheGatewayKeyThatSaysWhoWeAre(): void {
		$launcher = $this->launcher([
			AdminSettings::KEY_GATEWAY_TOKEN => 'tenant-key',
			AdminSettings::KEY_TELEMOST_TOKEN => 'shared-launcher-key',
		]);

		$this->assertSame('tenant-key', $this->call($launcher, 'token'));
	}

	public function testFallsBackToTheOldSharedKey(): void {
		// An installation configured before the launcher learned about tenants
		// keeps working — it simply cannot say whose meeting it is.
		$launcher = $this->launcher([
			AdminSettings::KEY_TELEMOST_TOKEN => 'shared-launcher-key',
		]);

		$this->assertSame('shared-launcher-key', $this->call($launcher, 'token'));
	}

	public function testUsesTheGatewayAddressWhenNoSeparateOneIsSet(): void {
		// Two settings for one service invited them to drift apart; on the
		// production install they held the same value anyway.
		$launcher = $this->launcher([
			AdminSettings::KEY_GATEWAY_URL => 'https://api.example.com/',
		]);

		$this->assertSame('https://api.example.com', $this->call($launcher, 'base'));
	}

	public function testASeparateAddressStillWins(): void {
		$launcher = $this->launcher([
			AdminSettings::KEY_GATEWAY_URL => 'https://api.example.com',
			AdminSettings::KEY_TELEMOST_URL => 'https://telemost.example.com',
		]);

		$this->assertSame('https://telemost.example.com',
			$this->call($launcher, 'base'));
	}
}
