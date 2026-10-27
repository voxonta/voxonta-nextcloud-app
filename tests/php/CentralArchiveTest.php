<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\BackgroundJob\CollectArtifacts;
use OCA\Voxonta\Service\ArtifactWriter;
use OCA\Voxonta\Service\ChatAnnouncer;
use OCA\Voxonta\Service\GatewayClient;
use OCA\Voxonta\Service\PendingMeetings;
use OCA\Voxonta\Service\TalkParticipants;
use OCA\Voxonta\Settings\AdminSettings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * This app is the archive for every meeting the gateway holds.
 *
 * Talk, Element, Telemost and whatever comes next: the app takes them all and
 * shares each with the logins the gateway answers. It does not know where a
 * meeting came from, and it does not need to — the gateway matches people to
 * accounts, and other integrations keep receipts of their own. Until
 * 2026-10-28 it took only Talk and Telemost.
 */
class CentralArchiveTest extends TestCase {
	private string $askedFor = '';
	/** @var array<string, mixed> */
	private array $put = [];

	/** @param array<string, mixed> $body what the gateway answers */
	private function client(array $body, bool $putFails = false): GatewayClient {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = '') => [
				AdminSettings::KEY_GATEWAY_URL => 'https://api.example.com',
				AdminSettings::KEY_GATEWAY_TOKEN => 'key',
			][$key] ?? $default);

		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($body));
		$http = $this->createMock(IClient::class);
		$http->method('get')->willReturnCallback(function (string $url) use ($response) {
			$this->askedFor = $url;
			return $response;
		});
		$http->method('put')->willReturnCallback(
			function (string $url, array $options) use ($response, $putFails) {
				if ($putFails) {
					throw new \RuntimeException('404 Not Found');
				}
				$this->put = ['url' => $url, 'body' => json_decode($options['body'], true)];
				return $response;
			});
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		return new GatewayClient($service, $appConfig, $this->createMock(LoggerInterface::class));
	}

	public function testAsksForEveryPlatformsMeetings(): void {
		$this->client(['meetings' => []])->undelivered();

		$this->assertSame('https://api.example.com/v1/meetings/undelivered', $this->askedFor);
	}

	public function testAMeetingSaysWhoMaySeeIt(): void {
		$state = $this->client([
			'status' => 'complete', 'final' => true, 'artifacts' => [],
			'access' => ['vadim.k', '', 7, 'olga.s'],
		])->meeting('s1');

		$this->assertSame(['vadim.k', 'olga.s'], $state['access']);
	}

	public function testAGatewayThatKeepsNoPeopleGivesNoAccess(): void {
		// Older than the people store, or running without one. Sharing then
		// falls back to the Talk conversation, as it always did.
		$state = $this->client(['status' => 'complete', 'final' => true])->meeting('s1');

		$this->assertSame([], $state['access']);
	}

	public function testSendsTheAccountsDirectory(): void {
		$sent = $this->client([])->directory([
			['login' => 'vadim.k', 'name' => 'Вадим Куницын', 'email' => 'v@example.org'],
		]);

		$this->assertTrue($sent);
		$this->assertSame('https://api.example.com/v1/directory', $this->put['url']);
		$this->assertSame(['accounts' => [
			['login' => 'vadim.k', 'name' => 'Вадим Куницын', 'email' => 'v@example.org'],
		]], $this->put['body']);
	}

	public function testADirectoryTheGatewayRefusesIsReportedNotThrown(): void {
		$this->assertFalse($this->client([], putFails: true)->directory([
			['login' => 'a', 'name' => 'A', 'email' => ''],
		]));
	}

	/** @param array<int, array<string, string>> $waiting */
	private function adopted(array $waiting): array {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1_000_000);
		$gateway = $this->createMock(GatewayClient::class);
		$gateway->method('configured')->willReturn(true);
		$gateway->method('undelivered')->willReturn($waiting);
		$pending = $this->createMock(PendingMeetings::class);
		$pending->method('knownSessions')->willReturn([]);
		$pending->method('due')->willReturn([]);

		$added = [];
		$pending->method('add')->willReturnCallback(
			function (array $call) use (&$added) { $added[] = $call['session_id']; });

		$job = new CollectArtifacts($time, $pending, $gateway,
			$this->createMock(ArtifactWriter::class),
			$this->createMock(ChatAnnouncer::class),
			$this->createMock(TalkParticipants::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class));
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		return $added;
	}

	public function testTakesEveryPlatformsMeetings(): void {
		$added = $this->adopted([
			['session_id' => 'talk', 'room_token' => 'a', 'room_name' => ''],
			['session_id' => 'matrix', 'room_token' => '!r:example.org', 'room_name' => ''],
			['session_id' => 'telemost', 'room_token' => '', 'room_name' => ''],
		]);

		$this->assertSame(['talk', 'matrix', 'telemost'], $added);
	}
}
