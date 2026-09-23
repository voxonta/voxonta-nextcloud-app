<?php

declare(strict_types=1);

namespace OCA\Voxonta\Tests;

use OCA\Voxonta\Service\ArtifactWriter;
use OCA\Voxonta\Service\BotAccount;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who a shared meeting file appears to come from.
 *
 * Nextcloud tells the recipient "{actor} shared {file} with you" and reads that
 * actor from the session rather than from the share. A cron job has none, so
 * between 2026-08-02 and 2026-09-04 every recipient was told a file had been
 * shared with them by nobody — 2931 shares, each one anonymous.
 */
class ArtifactWriterTest extends TestCase {
	/** Who the session held at each createShare call, in order. */
	private array $actors = [];
	/** Who the session held when the file was created — the first activity. */
	private array $creators = [];
	private ?IUser $sessionUser = null;
	private bool $shareThrows = false;
	/** What each recipient's copy was renamed to, as [uid, target]. */
	private array $moves = [];
	private bool $moveThrows = false;

	/**
	 * IShare's setters are fluent but declare no return type, so a plain mock
	 * answers null and the builder chain dies on the second call — silently,
	 * inside the catch that exists for "already shared".
	 */
	private function share(): IShare {
		$share = $this->createMock(IShare::class);
		foreach (['setNode', 'setShareType', 'setSharedWith', 'setSharedBy',
			'setPermissions'] as $setter) {
			$share->method($setter)->willReturnSelf();
		}
		return $share;
	}

	/**
	 * A share as Nextcloud hands it back: named after the file, in the
	 * recipient's share folder, with a target that can be changed.
	 */
	private function createdShare(): IShare {
		$target = '/Shares/01_Executive_Summary.md';
		$share = $this->createMock(IShare::class);
		$share->method('getTarget')->willReturnCallback(static function () use (&$target) {
			return $target;
		});
		$share->method('setTarget')->willReturnCallback(
			static function (string $t) use (&$target, $share) {
				$target = $t;
				return $share;
			});
		return $share;
	}

	private function bot(): IUser {
		$bot = $this->createMock(IUser::class);
		$bot->method('getUID')->willReturn('transcriber');
		return $bot;
	}

	private function writer(?IUser $known): ArtifactWriter {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn () => $this->sessionUser);
		$session->method('setUser')->willReturnCallback(function (?IUser $u): void {
			$this->sessionUser = $u;
		});

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid) => $uid === 'transcriber' ? $known : null);

		$shares = $this->createMock(IManager::class);
		$shares->method('newShare')->willReturnCallback(fn () => $this->share());
		$shares->method('createShare')->willReturnCallback(function () {
			$this->actors[] = $this->sessionUser?->getUID();
			if ($this->shareThrows) {
				throw new \RuntimeException('already shared');
			}
			return $this->createdShare();
		});
		$shares->method('moveShare')->willReturnCallback(
			function (IShare $share, string $uid) {
				if ($this->moveThrows) {
					throw new \InvalidArgumentException('Invalid share recipient');
				}
				$this->moves[] = [$uid, $share->getTarget()];
				return $share;
			});

		$account = $this->createMock(BotAccount::class);
		$account->method('credentials')->willReturn(
			['user' => 'transcriber', 'password' => 'p']);

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturn(true);
		$config->method('getValueString')->willReturn('');

		return new ArtifactWriter($this->rootFolder(), $shares, $account, $config,
			$this->createMock(IURLGenerator::class), $session, $users,
			$this->createMock(LoggerInterface::class));
	}

	/** A folder that swallows creation and hands back a file for anything. */
	private function rootFolder(): IRootFolder {
		$folder = $this->createMock(Folder::class);
		$folder->method('nodeExists')->willReturn(false);
		$folder->method('newFolder')->willReturnCallback(fn () => $folder);
		$folder->method('newFile')->willReturnCallback(function () {
			$this->creators[] = $this->sessionUser?->getUID();
			return $this->createMock(File::class);
		});
		$folder->method('get')->willReturnCallback(
			fn () => $this->createMock(File::class));

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($folder);
		return $root;
	}

	private function writeOne(ArtifactWriter $writer): bool {
		return $writer->write(
			['name' => '2026-09-04/001_planerka/01_Executive_Summary.md', 'kind' => 'summary'],
			'содержимое',
			['anatoliy.h', 'vadim.k'],
		);
	}

	public function testEveryShareIsMadeUnderTheBotAccount(): void {
		$writer = $this->writer($this->bot());

		$this->assertTrue($this->writeOne($writer));
		// Both recipients, both named — this is the whole point of the fix.
		$this->assertSame(['transcriber', 'transcriber'], $this->actors);
	}

	public function testTheFileIsCreatedUnderTheBotAccountToo(): void {
		// The one that matters, and the one the 2026-09-05 fix missed. Creating
		// the file is an activity of its own and it happens first; the activity
		// app asks who did it once and keeps that answer for the whole process.
		// Sign in only for the share and the blank is already cached — which is
		// how a shipped fix changed nothing for three weeks.
		$writer = $this->writer($this->bot());

		$this->writeOne($writer);

		$this->assertSame(['transcriber'], $this->creators,
			'подмена опоздала на шаг: файл создан до входа в учётку');
	}

	public function testTheSessionIsGivenBackAfterwards(): void {
		// A cron process runs many jobs in turn. Leaving the bot signed in
		// would hang its name on whatever ran next.
		$writer = $this->writer($this->bot());
		$this->writeOne($writer);

		$this->assertNull($this->sessionUser);
	}

	public function testAnEarlierSessionIsRestoredNotCleared(): void {
		$someone = $this->createMock(IUser::class);
		$someone->method('getUID')->willReturn('vadim.k');
		$this->sessionUser = $someone;

		$writer = $this->writer($this->bot());
		$this->writeOne($writer);

		$this->assertSame($someone, $this->sessionUser);
	}

	public function testTheSessionIsGivenBackEvenWhenSharingFails(): void {
		// "Already shared" is the common case on a re-run, and it arrives as an
		// exception. A leaked session would be a strange price for it.
		$this->shareThrows = true;
		$writer = $this->writer($this->bot());
		$this->writeOne($writer);

		$this->assertNull($this->sessionUser);
	}

	private const SUMMARY = "---\nmeeting_date: '2026-09-04'\n"
		. "meeting_name: Софтмус • Планёрка\n"
		. "title: Статусы задач и внедрение ИИ\n---\n\n# Executive Summary\n";

	public function testTheRecipientsCopyIsNamedAfterTheMeeting(): void {
		// Every summary is "01_Executive_Summary.md" under the bot's account, and
		// by September one person's share folder held two hundred of them,
		// numbered. The recipient's copy now says which meeting it is.
		$this->writer($this->bot())->write(
			['name' => '2026-09-04/001_planerka/01_Executive_Summary.md', 'kind' => 'summary'],
			self::SUMMARY, ['anatoliy.h', 'vadim.k']);

		$this->assertSame([
			['anatoliy.h', '/Shares/2026-09-04 Статусы задач и внедрение ИИ — итоги.md'],
			['vadim.k', '/Shares/2026-09-04 Статусы задач и внедрение ИИ — итоги.md'],
		], $this->moves);
	}

	public function testTheTranscriptIsCalledATranscript(): void {
		$enriched = "---\nmeeting_date: 2026-09-04T11:00\n"
			. "meeting_name: Статусы задач и внедрение ИИ\n"
			. "title: Статусы задач и внедрение ИИ\n---\n\nслова\n";

		$this->writer($this->bot())->write(
			['name' => '2026-09-04/001_planerka/09_Enriched_Transcript.md', 'kind' => 'analysis'],
			$enriched, ['vadim.k']);

		$this->assertSame(
			[['vadim.k', '/Shares/2026-09-04 Статусы задач и внедрение ИИ — расшифровка.md']],
			$this->moves);
	}

	public function testAFileWithoutATopicKeepsItsOwnName(): void {
		// Anything written before the analyser named its topic: a half-made name
		// ("2026-09-04 — итоги.md") would be worse than the old one.
		$this->writeOne($this->writer($this->bot()));

		$this->assertSame([], $this->moves);
	}

	public function testATopicIsMadeSafeForAFilename(): void {
		$summary = str_replace('title: Статусы задач и внедрение ИИ',
			"title: 'Релиз 2.0: API/UI и «что дальше?»'", self::SUMMARY);

		$this->writer($this->bot())->write(
			['name' => '2026-09-04/001_planerka/01_Executive_Summary.md', 'kind' => 'summary'],
			$summary, ['vadim.k']);

		$this->assertSame(
			[['vadim.k', '/Shares/2026-09-04 Релиз 2.0 API UI и «что дальше» — итоги.md']],
			$this->moves);
	}

	public function testAFoldedTopicIsReadWhole(): void {
		// PyYAML wraps a long value at eighty columns onto indented lines.
		$meta = ArtifactWriter::frontMatter("---\nmeeting_date: '2026-09-04'\n"
			. "title: Очень длинная тема встречи, которая не поместилась в восемьдесят\n"
			. "  символов одной строкой\nparticipants:\n- Вадим\n---\n");

		$this->assertSame('Очень длинная тема встречи, которая не поместилась в восемьдесят'
			. ' символов одной строкой', $meta['title']);
		$this->assertSame('2026-09-04', $meta['meeting_date']);
	}

	public function testARenameThatFailsLeavesTheShare(): void {
		// The share is what matters; its name is a convenience. One recipient's
		// failed rename must not cost the next one their share.
		$this->moveThrows = true;

		$this->assertTrue($this->writer($this->bot())->write(
			['name' => '2026-09-04/001_planerka/01_Executive_Summary.md', 'kind' => 'summary'],
			self::SUMMARY, ['anatoliy.h', 'vadim.k']));
		$this->assertSame(['transcriber', 'transcriber'], $this->actors);
		$this->assertNull($this->sessionUser);
	}

	public function testAnAccountNextcloudDoesNotKnowStillWritesAndShares(): void {
		// An administrator who names an account that was later removed. The
		// files still matter more than who they appear to come from.
		$writer = $this->writer(null);

		$this->assertTrue($this->writeOne($writer));
		$this->assertSame([null, null], $this->actors);
		$this->assertNull($this->sessionUser);
	}
}
