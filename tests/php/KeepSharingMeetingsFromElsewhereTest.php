<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Migration\KeepSharingMeetingsFromElsewhere;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * The account list is off for a new installation and stays on for one that
 * was running before it became a setting: that owner asked for meetings from
 * Element and Telemost to reach their people.
 */
class KeepSharingMeetingsFromElsewhereTest extends TestCase {
	/** @var array<string, string> */
	private array $written = [];

	private function upgradeFrom(string $previous, bool $alreadySet = false): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') =>
				$key === 'installed_version' ? $previous : $default);
		$appConfig->method('hasKey')->willReturn($alreadySet);
		$config = $this->createMock(IConfig::class);
		$config->method('setAppValue')->willReturnCallback(
			function (string $app, string $key, string $value): void {
				$this->written[$key] = $value;
			});

		(new KeepSharingMeetingsFromElsewhere($appConfig, $config))
			->run($this->createMock(IOutput::class));
	}

	public function testAnInstallationFromBeforeKeepsSharing(): void {
		$this->upgradeFrom('3.0.9');

		$this->assertSame([AdminSettings::KEY_SEND_DIRECTORY => '1'], $this->written);
	}

	public function testALaterUpgradeLeavesTheSettingAlone(): void {
		// Installed fresh at 3.1.0, where it is off; a later upgrade must not
		// switch it on behind the administrator's back.
		$this->upgradeFrom('3.1.0');

		$this->assertSame([], $this->written);
	}

	public function testTheAdministratorsChoiceStands(): void {
		$this->upgradeFrom('3.0.9', alreadySet: true);

		$this->assertSame([], $this->written);
	}
}
