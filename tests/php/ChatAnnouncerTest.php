<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\Announcement;
use OCA\Voxonta\Service\BotAccount;
use OCA\Voxonta\Service\ChatAnnouncer;
use OCA\Voxonta\Service\TalkParticipants;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\IL10N;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Telling a conversation that its meeting is written up.
 *
 * Only a member of a conversation may post to it, and in a one-to-one room the
 * bot never is one: it hears the call through the signalling server, not as a
 * participant. Posting there answered 404 for every meeting — a warning each
 * time for something that could not work.
 */
class ChatAnnouncerTest extends TestCase {
	/** @var array<int, string> */
	private array $posted = [];
	/** @var array<int, string> */
	private array $members = [];
	/** @var array<int, string> */
	private array $warnings = [];
	/** Whether Talk says this is a room nobody can be added to; null = unknown. */
	private ?bool $closedRoom = null;
	private bool $postThrows = false;

	private function announcer(): ChatAnnouncer {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url) {
			if ($this->postThrows) {
				throw new \RuntimeException('connection reset');
			}
			$this->posted[] = $url;
			return $this->createMock(\OCP\Http\Client\IResponse::class);
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$bot = $this->createMock(BotAccount::class);
		$bot->method('credentials')->willReturn(['user' => 'transcriber', 'password' => 'p']);

		$participants = $this->createMock(TalkParticipants::class);
		$participants->method('userIds')->willReturnCallback(fn () => $this->members);
		$participants->method('isClosedRoom')->willReturnCallback(fn () => $this->closedRoom);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string|\Stringable $message): void {
				$this->warnings[] = (string)$message;
			});

		return new ChatAnnouncer($clientService, $bot, $participants, $urls, $l10n,
			$logger);
	}

	private const LINKS = ['Summary' => 'https://cloud.example/f/1'];

	public function testSaysNothingInAOneToOneRoomItCannotJoin(): void {
		$this->members = ['admin', 'vadim.k'];  // the bot is not — and cannot be — here
		$this->closedRoom = true;

		$this->assertSame(Announcement::Impossible,
			$this->announcer()->announce('room', self::LINKS));
		$this->assertSame([], $this->posted, 'posted into a room it is not in');
		$this->assertSame([], $this->warnings, 'warned about how Talk works');
	}

	public function testAGroupRoomWithoutTheBotIsWorthSayingOutLoud(): void {
		// 2026-09-04: two group conversations had been losing every result for
		// weeks because nobody had added the account. This was debug level, so
		// production wrote nothing at all and it took a complaint to find.
		$this->members = ['anatoliy.h', 'vadim.k'];
		$this->closedRoom = false;

		$this->assertSame(Announcement::Impossible,
			$this->announcer()->announce('nhm29rr9', self::LINKS));
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('talk:room:add', $this->warnings[0],
			'a warning nobody can act on is only noise');
	}

	public function testPostsWhereItIsAMember(): void {
		$this->members = ['admin', 'vadim.k', 'transcriber'];

		$this->assertSame(Announcement::Posted,
			$this->announcer()->announce('room', self::LINKS));
		$this->assertCount(1, $this->posted);
		$this->assertStringContainsString('/chat/room', $this->posted[0]);
	}

	public function testTriesAnywayWhenMembershipCannotBeRead(): void {
		// An empty list means Talk could not answer, not that the room is empty:
		// refusing to post on that would silently stop announcements altogether.
		$this->members = [];

		$this->assertSame(Announcement::Posted,
			$this->announcer()->announce('room', self::LINKS));
		$this->assertCount(1, $this->posted);
	}

	public function testNothingToLinkIsNotAnnounced(): void {
		$this->members = ['transcriber'];

		$this->assertSame(Announcement::Impossible,
			$this->announcer()->announce('room', []));
		$this->assertSame([], $this->posted);
	}

	public function testABrokenAttemptIsWorthRepeating(): void {
		// The room is reachable and the bot belongs there; the chat API simply
		// did not answer. Reading that as "done" loses the announcement for good.
		$this->members = ['transcriber'];
		$this->postThrows = true;

		$this->assertSame(Announcement::Failed,
			$this->announcer()->announce('room', self::LINKS));
	}
}
