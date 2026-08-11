<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\PendingMeetings;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The queue of meetings waiting for their files.
 *
 * A round of collection takes a few entries from the front of this queue, so
 * the queue's own behaviour decides whether a meeting is ever collected. On
 * 2026-08-11 six entries that could never be collected — calls whose audio had
 * never reached the gateway — sat at the front and starved every meeting behind
 * them; nothing new was collected for a week and nothing said so. These tests
 * are about that: a failing entry must step aside rather than hold the door.
 */
class PendingMeetingsTest extends TestCase {
	private string $stored = '';
	private int $now = 1_000_000;

	private function queue(): PendingMeetings {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = '') => $this->stored ?: $default);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value) {
				$this->stored = $value;
				return true;
			});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		return new PendingMeetings($appConfig, $time,
			$this->createMock(LoggerInterface::class));
	}

	private function add(PendingMeetings $queue, string $sessionId): void {
		$queue->add(['session_id' => $sessionId, 'token' => 't', 'name' => 'n', 'type' => 1]);
	}

	/** @return array<int, string> session ids currently due, in order */
	private function dueIds(PendingMeetings $queue): array {
		return array_map(static fn (array $m) => (string)$m['session_id'], $queue->due());
	}

	public function testAFreshMeetingIsDueAtOnce(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');

		$this->assertSame(['a'], $this->dueIds($queue));
	}

	public function testOldestFirst(): void {
		$queue = $this->queue();
		$this->add($queue, 'old');
		$this->now += 60;
		$this->add($queue, 'new');

		$this->assertSame(['old', 'new'], $this->dueIds($queue));
	}

	public function testAMissedMeetingWaitsBeforeBeingAskedAgain(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');
		$queue->missed('a');

		$this->assertSame([], $this->dueIds($queue), 'asked again immediately');

		$this->now += PendingMeetings::BACKOFF_BASE;
		$this->assertSame(['a'], $this->dueIds($queue));
	}

	public function testTheWaitDoublesWithEachFailure(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');

		$queue->missed('a');
		$this->now += PendingMeetings::BACKOFF_BASE;
		$queue->missed('a');  // second failure: twice the wait

		$this->now += PendingMeetings::BACKOFF_BASE;
		$this->assertSame([], $this->dueIds($queue), 'the wait did not grow');

		$this->now += PendingMeetings::BACKOFF_BASE;
		$this->assertSame(['a'], $this->dueIds($queue));
	}

	public function testTheWaitIsCapped(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');
		for ($i = 0; $i < 20; $i++) {
			$queue->missed('a');
		}

		$this->now += PendingMeetings::BACKOFF_CAP;
		$this->assertSame(['a'], $this->dueIds($queue), 'the wait grew past the cap');
	}

	public function testAnsweringClearsTheWait(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');
		$queue->missed('a');
		$queue->missed('a');

		$queue->answered('a');

		$this->assertSame(['a'], $this->dueIds($queue), 'still waiting after a good answer');
	}

	public function testAFailingMeetingDoesNotStarveTheRest(): void {
		// The 2026-08-11 shape: entries that can never be collected must not
		// hold the front of the queue against a meeting that is ready now.
		$queue = $this->queue();
		foreach (['dead1', 'dead2', 'dead3', 'dead4', 'dead5'] as $dead) {
			$this->add($queue, $dead);
		}
		$this->now += 3600;
		$this->add($queue, 'live');

		foreach (['dead1', 'dead2', 'dead3', 'dead4', 'dead5'] as $dead) {
			$queue->missed($dead);
		}

		$this->assertSame(['live'], $this->dueIds($queue));
	}

	public function testAMeetingWaitedOnTooLongIsDropped(): void {
		$queue = $this->queue();
		$this->add($queue, 'a');

		$this->now += PendingMeetings::GIVE_UP_AFTER + 1;

		$this->assertSame([], $this->dueIds($queue));
		$this->assertStringNotContainsString('"a"', $this->stored);
	}

	public function testMissingAnUnknownSessionIsHarmless(): void {
		$queue = $this->queue();
		$queue->missed('never-added');
		$queue->answered('never-added');

		$this->assertSame([], $this->dueIds($queue));
	}

	public function testWrittenDigestsSurviveABackoff(): void {
		// Backing off must not lose track of what has already been written, or
		// the next successful round would write every file a second time.
		$queue = $this->queue();
		$this->add($queue, 'a');
		$queue->markWritten('a', ['sha-1']);
		$queue->missed('a');

		$this->now += PendingMeetings::BACKOFF_BASE;
		$due = $queue->due();

		$this->assertSame(['sha-1'], $due[0]['written']);
	}
}
