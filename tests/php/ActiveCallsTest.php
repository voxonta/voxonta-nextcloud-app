<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\ActiveCalls;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which calls the capture service is told about.
 *
 * This replaces polling Nextcloud's database over an SSH tunnel, so it has to
 * be at least as reliable as that was: a call that is happening must appear,
 * and a call that ended — or whose end was never announced — must not linger
 * and have the service dial into nothing.
 */
class ActiveCallsTest extends TestCase {
	private string $stored = '';
	private int $now = 1_000_000;

	private function calls(): ActiveCalls {
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

		return new ActiveCalls($appConfig, $time,
			$this->createMock(LoggerInterface::class));
	}

	public function testAnInstanceWithNoCallsSaysSo(): void {
		$this->assertSame([], $this->calls()->current());
	}

	public function testAStartedCallIsOffered(): void {
		$calls = $this->calls();

		$calls->started('abc123', 'Планёрка', 2);
		$live = $calls->current();

		$this->assertCount(1, $live);
		$this->assertSame('abc123', $live[0]['token']);
		$this->assertSame('Планёрка', $live[0]['name']);
		$this->assertSame(1_000_000, $live[0]['started_at']);
	}

	public function testAnEndedCallIsNotOffered(): void {
		$calls = $this->calls();
		$calls->started('abc123', 'Планёрка', 2);

		$calls->ended('abc123');

		$this->assertSame([], $calls->current());
	}

	public function testTheSecondStartEventDoesNotResetTheClock(): void {
		// Talk raises the event again when the call flag changes — somebody
		// turning on their camera must not make the call look new.
		$calls = $this->calls();
		$calls->started('abc123', 'Планёрка', 2);

		$this->now += 60;
		$calls->started('abc123', 'Планёрка', 2);

		$this->assertSame(1_000_000, $calls->current()[0]['started_at']);
	}

	public function testACallWhoseEndWasMissedIsEventuallyDropped(): void {
		// The server restarting mid-call loses the end event; without a sweep
		// the service would be told to join that call for ever.
		$calls = $this->calls();
		$calls->started('abc123', 'Планёрка', 2);

		$this->now += ActiveCalls::ABANDONED_AFTER + 1;

		$this->assertSame([], $calls->current());
	}

	public function testALongMeetingIsNotMistakenForAbandoned(): void {
		$calls = $this->calls();
		$calls->started('abc123', 'Стратсессия', 2);

		$this->now += ActiveCalls::ABANDONED_AFTER - 60;

		$this->assertCount(1, $calls->current());
	}

	public function testCallsComeOldestFirst(): void {
		$calls = $this->calls();
		$calls->started('first', 'Раз', 2);
		$this->now += 30;
		$calls->started('second', 'Два', 2);

		$this->assertSame(['first', 'second'],
			array_column($calls->current(), 'token'));
	}

	public function testUnreadableStateDoesNotStopCapture(): void {
		// Whatever wrote this, a call happening now still has to be reported.
		$this->stored = 'not json at all';
		$calls = $this->calls();

		$calls->started('abc123', 'Планёрка', 2);

		$this->assertSame(['abc123'], array_column($calls->current(), 'token'));
	}

	public function testEndingACallThatWasNeverStartedIsHarmless(): void {
		$calls = $this->calls();

		$calls->ended('never-seen');

		$this->assertSame([], $calls->current());
	}
}
