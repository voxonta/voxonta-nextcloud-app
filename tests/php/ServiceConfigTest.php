<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Service\BotAccount;
use OCA\DoneTranscription\Service\ServiceConfig;
use OCA\DoneTranscription\Settings\AdminSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * The settings the transcription service reads, and the secret that gates them.
 *
 * This is the one door in the app that is not a Nextcloud user session, so what
 * it refuses matters as much as what it returns.
 */
class ServiceConfigTest extends TestCase {
	/** @var array<string, mixed> */
	private array $stored = [];
	/** @var array{user: string, password: string}|null */
	private ?array $botCredentials = null;

	private function config(int $now = 1_000_000): ServiceConfig {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') =>
				(string)($this->stored[$key] ?? $default));
		$appConfig->method('getValueBool')->willReturnCallback(
			fn (string $app, string $key, bool $default = false) =>
				(bool)($this->stored[$key] ?? $default));
		$appConfig->method('getValueInt')->willReturnCallback(
			fn (string $app, string $key, int $default = 0) =>
				(int)($this->stored[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->stored[$key] = $value;
				return true;
			});
		$appConfig->method('setValueInt')->willReturnCallback(
			function (string $app, string $key, int $value) {
				$this->stored[$key] = $value;
				return true;
			});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$bot = $this->createMock(BotAccount::class);
		$bot->method('credentials')->willReturnCallback(
			fn () => $this->botCredentials);

		return new ServiceConfig($appConfig, $time, $bot);
	}

	// ── the secret ─────────────────────────────────────────────────────────
	public function testAnInstanceWithNoKeySetLetsNobodyIn(): void {
		$config = $this->config();

		$this->assertFalse($config->authenticates(''),
			'an empty presented secret must never match an unset one');
		$this->assertFalse($config->authenticates('anything'));
	}

	public function testOnlyTheConfiguredKeyIsAccepted(): void {
		$this->stored[AdminSettings::KEY_SERVICE_TOKEN] = 's3cret';
		$config = $this->config();

		$this->assertTrue($config->authenticates('s3cret'));
		$this->assertFalse($config->authenticates('s3cre'));
		$this->assertFalse($config->authenticates('s3crets'));
		$this->assertFalse($config->authenticates(''));
	}

	// ── what goes out ──────────────────────────────────────────────────────
	public function testTheSecretIsNeverInTheAnswer(): void {
		$this->stored[AdminSettings::KEY_SERVICE_TOKEN] = 's3cret';

		$json = json_encode($this->config()->forService());

		$this->assertStringNotContainsString('s3cret', $json,
			'a response that echoes the secret ends up in logs');
	}

	public function testTheRoomListIsSplitForTheCaller(): void {
		$this->stored[AdminSettings::KEY_ROOM_ALLOWLIST] = ' abc123 , ,def456 ';

		$this->assertSame(['abc123', 'def456'],
			$this->config()->forService()['rooms']);
	}

	public function testNothingInTheSettingsCanOrderADeletion(): void {
		$this->stored[AdminSettings::KEY_SERVICE_TOKEN] = 's3cret';

		$settings = $this->config()->forService();

		// A retention period was drafted and dropped: the files are the only
		// copy of what was said, and no field here should be able to remove
		// them. If one is ever added, this test is the argument against it.
		$this->assertSame([], array_filter(array_keys($settings),
			static fn (string $k) => str_contains($k, 'retention')
				|| str_contains($k, 'delete')));
	}

	public function testTheBotCredentialsArePassedToTheService(): void {
		$this->botCredentials = ['user' => 'done-transcription-bot', 'password' => 'app-pw'];

		$settings = $this->config()->forService();

		$this->assertSame(['user' => 'done-transcription-bot', 'password' => 'app-pw'],
			$settings['nextcloud']);
	}

	public function testSignalingIsReadFromTalksOwnConfig(): void {
		$this->stored['signaling_servers'] = json_encode([
			'servers' => [['server' => 'https://signal.example.com', 'verify' => true]],
			'secret' => 'hpb-secret',
		]);

		$signaling = $this->config()->forService()['signaling'];

		// The raw https server and the secret, as Talk stores them; the capture
		// side turns the URL into the wss form it dials.
		$this->assertSame('https://signal.example.com', $signaling['url']);
		$this->assertSame('hpb-secret', $signaling['secret']);
	}

	public function testNoTalkSignalingConfigIsNotAnError(): void {
		// An instance without an external signaling server: null, and the
		// administrator sets HPB by hand — not a crash.
		$this->assertNull($this->config()->forService()['signaling']);
	}

	public function testWithoutABotAccountTheServiceIsToldThereIsNone(): void {
		$this->assertNull($this->config()->forService()['nextcloud'],
			'null, not an empty string — the service must not try to sign in');
	}

	public function testAnUnsetInstanceGetsTheDefaults(): void {
		$settings = $this->config()->forService();

		$this->assertTrue($settings['enabled']);
		$this->assertTrue($settings['publish_to_chat']);
		$this->assertSame([], $settings['rooms'], 'empty means every call');
		$this->assertSame(AdminSettings::DEFAULT_ANALYSIS_FOLDER,
			$settings['folders']['analysis']);
	}

	public function testABlankFolderFallsBackRatherThanBreakingTheArchive(): void {
		$this->stored[AdminSettings::KEY_ANALYSIS_FOLDER] = '   ';

		$this->assertSame(AdminSettings::DEFAULT_ANALYSIS_FOLDER,
			$this->config()->forService()['folders']['analysis']);
	}

	// ── the connection an administrator sees ───────────────────────────────
	public function testAnInstanceThatWasNeverContactedSaysSo(): void {
		$status = $this->config()->status();

		$this->assertFalse($status['configured']);
		$this->assertFalse($status['connected']);
		$this->assertSame(0, $status['last_seen']);
	}

	public function testReportingInMakesItConnected(): void {
		$this->stored[AdminSettings::KEY_SERVICE_TOKEN] = 's3cret';
		$config = $this->config(1_000_000);

		$config->reportIn('1.2.3', 'ok');
		$status = $config->status();

		$this->assertTrue($status['configured']);
		$this->assertTrue($status['connected']);
		$this->assertSame(1_000_000, $status['last_seen']);
		$this->assertSame('1.2.3', $status['version']);
	}

	public function testAServiceThatWentQuietStopsCountingAsConnected(): void {
		$this->stored[ServiceConfig::KEY_LAST_SEEN] = 1_000_000;

		$status = $this->config(1_000_000 + ServiceConfig::STALE_AFTER + 1)->status();

		$this->assertFalse($status['connected']);
		$this->assertSame(1_000_000, $status['last_seen'],
			'when it was last heard from is still worth showing');
	}
}
