<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Service\TelemostLauncher;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Which pasted links become a meeting, and which are left alone.
 *
 * There was no test here, and that is exactly how a link stopped working
 * without anybody noticing: Yandex 360 serves meetings from
 * telemost.360.yandex.ru, the pattern allowed only one label between
 * "telemost" and "yandex", and the message was read, ignored and never
 * mentioned again. Nothing failed — the launcher was simply never called.
 */
class TelemostLinkTest extends TestCase {
	private string $pattern;

	protected function setUp(): void {
		// The constant is private because nothing outside the launcher should
		// depend on how a link is spotted. Reading it here rather than copying
		// it keeps this test honest: a copy would pass while production broke.
		$this->pattern = (new ReflectionClass(TelemostLauncher::class))
			->getConstant('LINK');
	}

	private function finds(string $text): bool {
		return preg_match($this->pattern, $text) === 1;
	}

	/** @dataProvider meetings */
	public function testAMeetingLinkIsRecognised(string $text): void {
		$this->assertTrue($this->finds($text), "should have matched: $text");
	}

	/** @return array<string, array{string}> */
	public static function meetings(): array {
		return [
			'plain' => ['https://telemost.yandex.ru/j/72264758238919'],
			// The one that went unnoticed: 360 puts a label in the middle.
			'yandex 360' => ['https://telemost.360.yandex.ru/j/4835891501'],
			'com zone' => ['https://telemost.yandex.com/j/123456'],
			'inside a sentence' => [
				'посмотрите https://telemost.360.yandex.ru/j/4835891501 в 15:00',
			],
			'with a query string' => [
				'https://telemost.yandex.ru/j/123?from=calendar',
			],
			'plain http' => ['http://telemost.yandex.ru/j/1'],
		];
	}

	/** @dataProvider notMeetings */
	public function testEverythingElseIsLeftAlone(string $text): void {
		$this->assertFalse($this->finds($text), "should not have matched: $text");
	}

	/** @return array<string, array{string}> */
	public static function notMeetings(): array {
		return [
			// Somebody else's domain wearing ours at the front. Loose matching
			// here would send a bot wherever a message told it to.
			'lookalike domain' => ['https://telemost.yandex.ru.evil.com/j/123'],
			'glued prefix' => ['https://fake-telemost.yandex.ru/j/123'],
			'no telemost' => ['https://yandex.ru/j/123'],
			'not a meeting path' => ['https://telemost.360.yandex.ru/about'],
			'no scheme' => ['telemost.360.yandex.ru/j/123'],
			'no meeting id' => ['https://telemost.yandex.ru/j/'],
		];
	}
}
