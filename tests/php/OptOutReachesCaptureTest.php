<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Controller\ServiceController;
use OCA\Voxonta\Listener\CallListener;
use OCA\Voxonta\Service\ActiveCalls;
use OCA\Voxonta\Service\PendingMeetings;
use OCA\Voxonta\Service\RecordingState;
use OCA\Voxonta\Service\ServiceConfig;
use OCA\Voxonta\Service\TalkParticipants;
use OCP\EventDispatcher\Event;
use OCP\IAppConfig;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * "/no-record" has to reach the capture service, and has to end with the call.
 *
 * Until 2026-10-27 the opt-out was stored and confirmed in the chat, and then
 * read by nothing: the call went on being captured. These tests pin the two
 * places that close that gap — the flag in /calls, and clearing it when Talk
 * reports the call over.
 */
class OptOutReachesCaptureTest extends TestCase {
	private string $optedOut = '';

	private function recordingState(): RecordingState {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn () => $this->optedOut);
		$config->method('setValueString')->willReturnCallback(
			function ($app, $key, $value) {
				$this->optedOut = $value;
				return true;
			});
		return new RecordingState($config);
	}

	/**
	 * @param array<int, array<string, mixed>> $live
	 */
	private function controller(array $live): ServiceController {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->willReturnMap([
			['Authorization', 'Bearer secret'],
			['X-Transcription-Token', ''],
		]);
		$config = $this->createMock(ServiceConfig::class);
		$config->method('authenticates')->willReturn(true);
		$calls = $this->createMock(ActiveCalls::class);
		$calls->method('current')->willReturn($live);
		$participants = $this->createMock(TalkParticipants::class);
		$participants->method('countInCall')->willReturn(2);

		return new ServiceController('voxonta', $request, $config, $calls,
			$participants, $this->recordingState(),
			$this->createMock(LoggerInterface::class));
	}

	private function listener(?ActiveCalls $calls = null): CallListener {
		return new CallListener(
			$calls ?? $this->createMock(ActiveCalls::class),
			$this->createMock(PendingMeetings::class),
			$this->recordingState(),
			$this->createMock(LoggerInterface::class));
	}

	private static function room(string $token): object {
		return new class($token) {
			public function __construct(private string $token) {
			}

			public function getToken(): string {
				return $this->token;
			}

			public function getName(): string {
				return 'Daily';
			}

			public function getType(): int {
				return 2;
			}
		};
	}

	public function testCallsCarryTheOptOut(): void {
		$this->optedOut = 'quiet';
		$data = $this->controller([
			['token' => 'quiet', 'name' => 'A', 'type' => 2, 'started_at' => 1, 'session_id' => 's1'],
			['token' => 'loud', 'name' => 'B', 'type' => 2, 'started_at' => 2, 'session_id' => 's2'],
		])->calls()->getData();

		$byToken = array_column($data['calls'], 'recording', 'token');
		$this->assertFalse($byToken['quiet'],
			'a room that said /no-record must be listed with recording off');
		$this->assertTrue($byToken['loud'], 'every other room records as before');
	}

	public function testAnOptedOutCallStaysListed(): void {
		$this->optedOut = 'quiet';
		$data = $this->controller([
			['token' => 'quiet', 'name' => 'A', 'type' => 2, 'started_at' => 1, 'session_id' => 's1'],
		])->calls()->getData();

		$this->assertCount(1, $data['calls'],
			'dropping the call would end the meeting, and /record would start a second one');
		$this->assertSame('s1', $data['calls'][0]['session_id']);
	}

	public function testTheEndOfTheCallEndsTheOptOut(): void {
		$this->optedOut = 'quiet,other';
		$this->listener()->handle(new FakeCallEndedEvent(self::room('quiet')));

		$state = $this->recordingState();
		$this->assertTrue($state->isRecording('quiet'),
			'the reply promised "in this call"; the next call is recorded again');
		$this->assertFalse($state->isRecording('other'),
			'a call ending in one room must not lift another room\'s opt-out');
	}

	public function testAnEndWeNeverSawStartStillEndsTheOptOut(): void {
		$this->optedOut = 'quiet';
		$calls = $this->createMock(ActiveCalls::class);
		$calls->method('ended')->willReturn(null);
		$this->listener($calls)->handle(new FakeCallEndedEvent(self::room('quiet')));

		$this->assertTrue($this->recordingState()->isRecording('quiet'));
	}

	public function testStartingACallKeepsAnOptOutPostedBeforeIt(): void {
		$this->optedOut = 'quiet';
		$this->listener()->handle(new FakeCallStartedEvent(self::room('quiet')));

		$this->assertFalse($this->recordingState()->isRecording('quiet'),
			'"/no-record" posted before the call must hold for that call');
	}
}

/**
 * Stand-ins for Talk's events. CallListener tells start from end by the class
 * name, so these names matter.
 */
class FakeCallEndedEvent extends Event {
	public function __construct(private object $room) {
		parent::__construct();
	}

	public function getRoom(): object {
		return $this->room;
	}
}

class FakeCallStartedEvent extends Event {
	public function __construct(private object $room) {
		parent::__construct();
	}

	public function getRoom(): object {
		return $this->room;
	}
}
