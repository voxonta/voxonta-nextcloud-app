<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

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

	private function announcer(): ChatAnnouncer {
		$client = $this->createMock(IClient::class);
		$client->method('post')->willReturnCallback(function (string $url) {
			$this->posted[] = $url;
			return $this->createMock(\OCP\Http\Client\IResponse::class);
		});
		$clientService = $this->createMock(IClientService::class);
		$clientService->method('newClient')->willReturn($client);

		$bot = $this->createMock(BotAccount::class);
		$bot->method('credentials')->willReturn(['user' => 'transcriber', 'password' => 'p']);

		$participants = $this->createMock(TalkParticipants::class);
		$participants->method('userIds')->willReturnCallback(fn () => $this->members);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path) => 'https://cloud.example' . $path);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text) => $text);

		return new ChatAnnouncer($clientService, $bot, $participants, $urls, $l10n,
			$this->createMock(LoggerInterface::class));
	}

	private const LINKS = ['Summary' => 'https://cloud.example/f/1'];

	public function testSaysNothingInAOneToOneRoomItCannotJoin(): void {
		$this->members = ['admin', 'vadim.k'];  // the bot is not — and cannot be — here

		$told = $this->announcer()->announce('room', self::LINKS);

		$this->assertFalse($told);
		$this->assertSame([], $this->posted, 'posted into a room it is not in');
	}

	public function testPostsWhereItIsAMember(): void {
		$this->members = ['admin', 'vadim.k', 'transcriber'];

		$told = $this->announcer()->announce('room', self::LINKS);

		$this->assertTrue($told);
		$this->assertCount(1, $this->posted);
		$this->assertStringContainsString('/chat/room', $this->posted[0]);
	}

	public function testTriesAnywayWhenMembershipCannotBeRead(): void {
		// An empty list means Talk could not answer, not that the room is empty:
		// refusing to post on that would silently stop announcements altogether.
		$this->members = [];

		$told = $this->announcer()->announce('room', self::LINKS);

		$this->assertTrue($told);
		$this->assertCount(1, $this->posted);
	}

	public function testNothingToLinkIsNotAnnounced(): void {
		$this->members = ['transcriber'];

		$this->assertFalse($this->announcer()->announce('room', []));
		$this->assertSame([], $this->posted);
	}
}
