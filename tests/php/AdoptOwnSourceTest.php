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
 * The sweep takes back this app's meetings and nobody else's.
 *
 * The gateway key belongs to an installation, and an installation can run more
 * than one integration under it — the Matrix one is next. Until 2026-10-27 the
 * sweep adopted every undelivered meeting it was shown, so a Matrix call left
 * uncollected for five minutes would have been published into a Talk room.
 */
class AdoptOwnSourceTest extends TestCase {
	private string $askedFor = '';

	/** @param array<string, mixed> $body what the gateway answers */
	private function client(array $body): GatewayClient {
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
		$service = $this->createMock(IClientService::class);
		$service->method('newClient')->willReturn($http);

		return new GatewayClient($service, $appConfig, $this->createMock(LoggerInterface::class));
	}

	public function testAsksForTheGivenPlatformsAsRepeatedParameters(): void {
		$this->client(['meetings' => []])->undelivered(['nc-talk', 'telemost', '']);

		$this->assertSame(
			'https://api.example.com/v1/meetings/undelivered?source=nc-talk&source=telemost&source=',
			$this->askedFor);
	}

	public function testAsksForEverythingWhenGivenNothing(): void {
		$this->client(['meetings' => []])->undelivered();

		$this->assertSame('https://api.example.com/v1/meetings/undelivered', $this->askedFor);
	}

	public function testAGatewayThatDoesNotSayTheSourceReadsAsEmpty(): void {
		// A gateway older than the column ignores ?source= and answers as it
		// always did. Its rows are then treated like the ones stored before the
		// column existed, which is what they are.
		[$row] = $this->client(['meetings' => [
			['session_id' => 's1', 'room_token' => 'tok', 'room_name' => 'n'],
		]])->undelivered(['nc-talk', '']);

		$this->assertSame('', $row['source']);
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

	public function testTakesBackTalkTelemostAndUnlabelledMeetings(): void {
		// Telemost too: this app sends the bot from a Talk chat and collects the
		// result, so a lost queue must not lose those.
		$added = $this->adopted([
			['session_id' => 'talk', 'source' => 'nc-talk', 'room_token' => 'a', 'room_name' => ''],
			['session_id' => 'tm', 'source' => 'telemost', 'room_token' => 'b', 'room_name' => ''],
			['session_id' => 'old', 'source' => '', 'room_token' => 'c', 'room_name' => ''],
		]);

		$this->assertSame(['talk', 'tm', 'old'], $added);
	}

	public function testLeavesAnotherIntegrationsMeetingAlone(): void {
		// The gateway was asked for our platforms only; one that ignored the
		// question still must not hand us a Matrix call to publish into Talk.
		$added = $this->adopted([
			['session_id' => 'matrix', 'source' => 'element-call', 'room_token' => '!r:x', 'room_name' => ''],
			['session_id' => 'talk', 'source' => 'nc-talk', 'room_token' => 'a', 'room_name' => ''],
		]);

		$this->assertSame(['talk'], $added);
	}
}
