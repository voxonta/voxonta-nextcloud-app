<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Service\RecordingState;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Who asked not to be recorded.
 *
 * This is the most consequential state in the app. Getting "stop" wrong means
 * recording a conversation after the room was told it had stopped, which is a
 * different kind of bug from a broken list.
 */
class RecordingStateTest extends TestCase {
	private function state(string $stored = ''): array {
		$value = $stored;
		$config = $this->createMock(IAppConfig::class);
		// By reference: a plain closure would capture the value as it was when
		// the mock was built, and every read would return the empty string.
		$config->method('getValueString')->willReturnCallback(
			static function () use (&$value) {
				return $value;
			});
		$config->method('setValueString')->willReturnCallback(
			static function ($app, $key, $new) use (&$value) {
				$value = $new;
				return true;
			});
		// Same reason as above: by reference, or the inspector reports the
		// value the mock started with.
		return [new RecordingState($config), static function () use (&$value) {
			return $value;
		}];
	}

	public function testRecordingIsTheDefault(): void {
		[$state] = $this->state();
		$this->assertTrue($state->isRecording('room1'),
			'a room nobody configured must behave as the administrator set it up');
	}

	public function testStoppingIsRemembered(): void {
		[$state] = $this->state();
		$state->setRecording('room1', false);
		$this->assertFalse($state->isRecording('room1'));
	}

	public function testStoppingOneRoomLeavesOthersAlone(): void {
		[$state] = $this->state();
		$state->setRecording('room1', false);
		$this->assertTrue($state->isRecording('room2'),
			'one room opting out must not silence the whole instance');
	}

	public function testResumingClearsIt(): void {
		[$state] = $this->state();
		$state->setRecording('room1', false);
		$state->setRecording('room1', true);
		$this->assertTrue($state->isRecording('room1'));
	}

	public function testStoppingTwiceDoesNotDuplicate(): void {
		[$state, $stored] = $this->state();
		$state->setRecording('room1', false);
		$state->setRecording('room1', false);
		$this->assertSame('room1', $stored(),
			'repeated commands would grow the value without bound');
	}

	public function testResumingARoomThatNeverStoppedIsHarmless(): void {
		[$state, $stored] = $this->state();
		$state->setRecording('room1', true);
		$this->assertSame('', $stored());
		$this->assertTrue($state->isRecording('room1'));
	}

	public function testExistingOptOutsSurviveANewOne(): void {
		[$state] = $this->state('room1');
		$state->setRecording('room2', false);

		$this->assertFalse($state->isRecording('room1'),
			'a second room opting out must not re-enable the first');
		$this->assertFalse($state->isRecording('room2'));
	}
}
