<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\BackgroundJob\CollectArtifacts;
use OCA\Voxonta\Service\ArtifactWriter;
use OCA\Voxonta\Service\ChatAnnouncer;
use OCA\Voxonta\Service\GatewayClient;
use OCA\Voxonta\Service\PendingMeetings;
use OCA\Voxonta\Service\TalkParticipants;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IL10N;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * What a round of collection does with an answer it cannot use.
 *
 * "Cannot reach the gateway" and "the gateway has no such meeting" arrive the
 * same way — as no state at all — but they mean opposite things about the
 * future. The first is worth retrying; the second, past a point, never is.
 */
class CollectArtifactsTest extends TestCase {
	private int $now = 1_000_000;
	private PendingMeetings&MockObject $pending;
	private GatewayClient&MockObject $gateway;

	private function job(): CollectArtifacts {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(fn () => $this->now);

		$this->pending = $this->createMock(PendingMeetings::class);
		$this->gateway = $this->createMock(GatewayClient::class);
		$this->gateway->method('configured')->willReturn(true);

		return new CollectArtifacts(
			$time,
			$this->pending,
			$this->gateway,
			$this->createMock(ArtifactWriter::class),
			$this->createMock(ChatAnnouncer::class),
			$this->createMock(TalkParticipants::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class),
		);
	}

	/** @param array<string, mixed> $overrides */
	private function queued(array $overrides = []): array {
		return [array_merge([
			'session_id' => 's1',
			'token' => 'room',
			'name' => 'n',
			'type' => 1,
			'ended_at' => $this->now,
			'written' => [],
		], $overrides)];
	}

	private function collectRound(CollectArtifacts $job): void {
		$method = new \ReflectionMethod($job, 'run');
		$method->invoke($job, null);
	}

	public function testAnUnreachableGatewayIsRetriedNotDropped(): void {
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued(['ended_at' => $this->now - 40 * 3600]));
		$this->gateway->method('meeting')->willReturn(null);
		$this->gateway->method('lastAnswerWasUnknown')->willReturn(false);

		$this->pending->expects($this->once())->method('missed')->with('s1');
		$this->pending->expects($this->never())->method('done');

		$this->collectRound($job);
	}

	public function testAMeetingTheGatewayNeverKnewIsDroppedAfterADay(): void {
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued(['ended_at' => $this->now - 40 * 3600]));
		$this->gateway->method('meeting')->willReturn(null);
		$this->gateway->method('lastAnswerWasUnknown')->willReturn(true);

		$this->pending->expects($this->once())->method('done')->with('s1');

		$this->collectRound($job);
	}

	public function testAFreshUnknownMeetingIsGivenTime(): void {
		// The gateway records a meeting when the call starts, but a minute-old
		// 404 can still be a blip. Losing a meeting costs more than asking again.
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued(['ended_at' => $this->now - 60]));
		$this->gateway->method('meeting')->willReturn(null);
		$this->gateway->method('lastAnswerWasUnknown')->willReturn(true);

		$this->pending->expects($this->never())->method('done');
		$this->pending->expects($this->once())->method('missed')->with('s1');

		$this->collectRound($job);
	}

	public function testAFailedMeetingIsKeptInsteadOfWrittenOff(): void {
		// 2026-08-13: an analysis broke, this job dropped the meeting minutes
		// later, and the twenty files produced by the re-run had nobody left to
		// collect them. A failure is not the end of the story.
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued());
		$this->gateway->method('meeting')->willReturn([
			'status' => 'failed', 'final' => true, 'detail' => 'analysis failed', 'artifacts' => [],
		]);

		$this->pending->expects($this->never())->method('done');
		$this->pending->expects($this->once())->method('missed')->with('s1');

		$this->collectRound($job);
	}

	public function testAMeetingThatRecoveredIsAnnouncedAndClosed(): void {
		// The same meeting on a later tick, once the re-run has succeeded.
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued());
		$this->gateway->method('meeting')->willReturn([
			'status' => 'complete', 'final' => true, 'detail' => '', 'artifacts' => [],
		]);

		$this->pending->expects($this->once())->method('done')->with('s1');
		$this->pending->expects($this->never())->method('missed');

		$this->collectRound($job);
	}

	public function testAnAnsweringGatewayClearsTheBackoff(): void {
		$job = $this->job();
		$this->pending->method('due')->willReturn($this->queued());
		$this->gateway->method('meeting')->willReturn([
			'status' => 'transcribing', 'final' => false, 'detail' => '', 'artifacts' => [],
		]);

		$this->pending->expects($this->once())->method('answered')->with('s1');
		$this->pending->expects($this->never())->method('missed');

		$this->collectRound($job);
	}
}
