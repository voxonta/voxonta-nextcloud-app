<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Service\BackendException;
use OCA\DoneTranscription\Service\FileArchive;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Search\ISearchQuery;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reading meetings from the shares a person holds.
 *
 * Nothing here decides who may read what. Files come from the caller's own
 * shares, so a call never shared with them is not in the result at all. The
 * move to the share manager is what makes that complete — the file index cannot
 * see calls shared into a Talk conversation, and most calls are shared so.
 */
class FileArchiveTest extends TestCase {
	private const TRANSCRIPT = "# Транскрипция: [\"alice\",\"bob\"]\n"
		. "Дата: 2026-03-05 14:49 — 15:04\n"
		. "Участники: Вадим Куницын, Алексей Морозов\n\n---\n\n"
		. "[00:05] **Вадим Куницын:** что решаем\n";

	private const MINUTES = "# Протокол\n\nРешили выкатывать.\n";

	private const YAML = "---\ndate: 2026-07-20\n"
		. "started_at: 2026-07-20T14:00:23Z\n"
		. "finished_at: 2026-07-20T14:40:23Z\n"
		. "participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин\n"
		. "meeting_name: \"Вводная встреча по Superset\"\n"
		. "tags:\n  - type/meeting\n---\n\n# Встреча\n";

	private int $nextId = 100;

	/**
	 * @param array<string, string> $files filename => content
	 */
	private function archive(array $files, ?string &$askedFor = null,
		bool $lookupThrows = false, bool $viaShare = false): FileArchive {
		$nodes = [];
		$shares = [];
		foreach ($files as $name => $content) {
			$file = $this->file($name, $content);
			if ($viaShare) {
				$shares[] = $this->shareOf($file);
			} else {
				$nodes[] = $file;
			}
		}

		// The archive folder holds the files, unless the test routes them
		// through shares (the participant case) — then the folder is absent and
		// the search fallback runs instead. The folder is read from the index by
		// its parent id, so the database is what returns the rows.
		$byId = [];
		foreach ($nodes as $node) {
			$byId[$node->getId()] = $node;
		}

		$folder = $this->createMock(Folder::class);
		$folder->method('getId')->willReturn(1);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($folder, $viaShare) {
				if (!$viaShare && $path === 'Talk/Транскрипции') {
					return $folder;
				}
				throw new \OCP\Files\NotFoundException();
			});
		$userFolder->method('getById')->willReturnCallback(
			static fn (int $id) => isset($byId[$id]) ? [$byId[$id]] : []);
		$userFolder->method('search')->willReturnCallback(
			function () use ($lookupThrows) {
				if ($lookupThrows) {
					throw new \RuntimeException('storage down');
				}
				return [];
			});
		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $user) use ($userFolder, &$askedFor) {
				$askedFor = $user;
				return $userFolder;
			});

		// The index read: a query builder that ignores its clauses and returns
		// the fixtures as rows, unless the lookup is set to fail.
		$db = $this->createMock(\OCP\IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(
			fn () => $this->queryBuilder($nodes, $lookupThrows));

		$shareManager = $this->createMock(IManager::class);
		$shareManager->method('getSharedWith')->willReturnCallback(
			function (string $user, int $type, $node, int $limit, int $offset)
			use ($shares) {
				if ($type !== 11 || $offset > 0) {
					return [];
				}
				return $shares;
			});

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new FileArchive($root, $shareManager, $users, $db,
			$this->createMock(LoggerInterface::class));
	}

	/**
	 * @param File[] $nodes
	 */
	private function queryBuilder(array $nodes, bool $throws): \OCP\DB\QueryBuilder\IQueryBuilder {
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['select', 'from', 'where', 'andWhere'] as $m) {
			$qb->method($m)->willReturn($qb);
		}
		$qb->method('createNamedParameter')->willReturn('?');

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetchAll')->willReturnCallback(
			function () use ($nodes, $throws) {
				if ($throws) {
					throw new \RuntimeException('storage down');
				}
				return array_map(static fn (File $f) => [
					'fileid' => $f->getId(),
					'name' => $f->getName(),
				], $nodes);
			});
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($this->nextId++);
		$file->method('getContent')->willReturn($content);
		$file->method('fopen')->willReturnCallback(
			static function () use ($content) {
				$handle = fopen('php://memory', 'r+');
				fwrite($handle, $content);
				rewind($handle);
				return $handle;
			});
		return $file;
	}

	private function shareOf(File $file): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn($file->getId());
		$share->method('getNodeType')->willReturn('file');
		$share->method('getTarget')->willReturn('/' . $file->getName());
		$share->method('getNode')->willReturn($file);
		return $share;
	}

	private function transcriptFile(): array {
		return ['2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT];
	}

	public function testFilesComeFromTheCallersOwnAccount(): void {
		$asked = null;
		$archive = $this->archive($this->transcriptFile(), $asked);

		$archive->list('alice');

		$this->assertSame('alice', $asked,
			"reading anyone else's shares would return calls never given");
	}

	public function testCallsSharedIntoAConversationAreFound(): void {
		// These arrive through the share manager, not the file search.
		$archive = $this->archive($this->transcriptFile(),
			viaShare: true);

		$this->assertCount(1, $archive->list('alice')['meetings']);
	}

	public function testMetadataComesFromTheHeader(): void {
		$archive = $this->archive($this->transcriptFile());

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Вадим Куницын', $meetings[0]['room_name']);
		$this->assertSame(['Вадим Куницын', 'Алексей Морозов'],
			$meetings[0]['participants']);
		$this->assertSame(15 * 60,
			$meetings[0]['call_end_ts'] - $meetings[0]['call_start_ts']);
	}

	public function testTheCurrentYamlFormatIsRead(): void {
		$archive = $this->archive([
			'2026-07-20 17-00-23 - Вводная встреча по Superset.md' => self::YAML,
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Вводная встреча по Superset', $meetings[0]['room_name']);
		$this->assertSame(40 * 60,
			$meetings[0]['call_end_ts'] - $meetings[0]['call_start_ts']);
	}

	public function testBothFormatsAppearInOneList(): void {
		$archive = $this->archive([
			'2026-07-20 17-00-23 - Superset.md' => self::YAML,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$this->assertCount(2, $archive->list('alice')['meetings']);
	}

	public function testMarkdownThatIsNotATranscriptIsIgnored(): void {
		$archive = $this->archive([
			'Shopping list.md' => "# Milk\n",
			'2026-07-20 10-00-00 - Notes.md' => "---\ntitle: Notes\n---\n\nText\n",
		]);

		$this->assertSame([], $archive->list('alice')['meetings'],
			'a call is recognised by starting with a date and stating a time');
	}

	public function testFilesThatMerelyStartWithTwentyAreNotCalls(): void {
		$archive = $this->archive([
			'20_extract_knowledge.md' => "# Prompt\n",
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$this->assertCount(1, $archive->list('alice')['meetings']);
	}

	public function testNewestCallsComeFirst(): void {
		// Same header (so same room_name); the order must come from the
		// filename timestamp, which is what sorts without opening files.
		$older = str_replace('2026-03-05 14:49', '2026-03-01 09:00', self::TRANSCRIPT);
		$archive = $this->archive([
			'2026-03-01 09-00-00 - Call.md' => $older,
			'2026-03-05 14-49-00 - Call.md' => self::TRANSCRIPT,
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertGreaterThan($meetings[1]['call_start_ts'],
			$meetings[0]['call_start_ts'],
			'the call people look for is almost always a recent one');
	}

	public function testPagingWalksTheWholeArchive(): void {
		$files = [];
		for ($i = 1; $i <= 3; $i++) {
			$files["2026-03-0$i 09-00-00 - Call $i.md"] =
				str_replace('2026-03-05', "2026-03-0$i", self::TRANSCRIPT);
		}
		$archive = $this->archive($files);

		$first = $archive->list('alice', 2, 0);
		$this->assertCount(2, $first['meetings']);
		$this->assertTrue($first['has_more']);

		$second = $archive->list('alice', 2, $first['next_offset']);
		$this->assertCount(1, $second['meetings']);
		$this->assertFalse($second['has_more']);
	}

	public function testTheTranscriptIsReadable(): void {
		$archive = $this->archive($this->transcriptFile());
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertStringContainsString('что решаем',
			$archive->transcript('alice', $id));
	}

	public function testTheMinutesArePairedByTimestamp(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
			'2026-03-05 14-49-00 - Протокол Вадим Куницын.md' => self::MINUTES,
		]);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertStringContainsString('Решили выкатывать',
			$archive->summary('alice', $id));
	}

	public function testMinutesFromADifferentCallAreNotPickedUp(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
			'2026-03-06 09-00-00 - Протокол Вадим Куницын.md' => self::MINUTES,
		]);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertSame('', $archive->summary('alice', $id),
			'pairing looser than the timestamp attaches the wrong minutes');
	}

	public function testACallWithoutMinutesStillOpens(): void {
		$archive = $this->archive($this->transcriptFile());
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertSame('', $archive->summary('alice', $id));
	}

	public function testAnUnknownCallIsNotFound(): void {
		$archive = $this->archive($this->transcriptFile());

		try {
			$archive->transcript('alice', '999999');
			$this->fail('expected a refusal');
		} catch (BackendException $e) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus());
		}
	}

	public function testAFailingLookupShowsNothingRatherThanEverything(): void {
		$asked = null;
		$archive = $this->archive($this->transcriptFile(), $asked,
			lookupThrows: true);

		$this->assertSame([], $archive->list('alice')['meetings']);
	}

	public function testSearchMatchesTheFilename(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Встреча с Вадимом.md' => self::TRANSCRIPT,
			'2026-03-06 10-00-00 - Встреча с Дарьей.md' => self::TRANSCRIPT,
		]);

		$meetings = $archive->list('alice', 50, 0, 'Вадим')['meetings'];

		$this->assertCount(1, $meetings);
	}

	public function testSearchIsCaseInsensitive(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Superset review.md' => self::TRANSCRIPT,
		]);

		$this->assertCount(1,
			$archive->list('alice', 50, 0, 'superset')['meetings']);
	}

	public function testDateRangeExcludesCallsOutsideIt(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - March call.md' =>
				str_replace('2026-03-05', '2026-03-05', self::TRANSCRIPT),
			'2026-07-20 10-00-00 - July call.md' =>
				str_replace('2026-03-05', '2026-07-20', self::TRANSCRIPT),
		]);

		// Only March: from 2026-03-01 to 2026-03-31.
		$from = strtotime('2026-03-01');
		$to = strtotime('2026-03-31');
		$meetings = $archive->list('alice', 50, 0, '', $from, $to)['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertStringContainsString('March', $meetings[0]['room_name']);
	}

	public function testAnOpenEndedRangeIncludesEverythingAfterFrom(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Old.md' => self::TRANSCRIPT,
			'2026-07-20 10-00-00 - New.md' =>
				str_replace('2026-03-05', '2026-07-20', self::TRANSCRIPT),
		]);

		$from = strtotime('2026-07-01');
		$this->assertCount(1, $archive->list('alice', 50, 0, '', $from, 0)['meetings']);
	}
}
