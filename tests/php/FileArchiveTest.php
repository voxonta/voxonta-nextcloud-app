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

	/** The analyser's own header: no started_at, unindented participants. */
	private const ANALYSIS = "---\ndate: 2026-07-20\n"
		. "duration: 19 min\n"
		. "finished_at: 2026-07-20 06:50:33+00:00\n"
		. "meeting_name: ППортал • Дейли\n"
		. "participants:\n- Александр Лимонов\n- Анатолий Хватиков\n"
		. "source_file: \"2026-07-20 09-31-08 - ППортал • Дейли.md\"\n---\n\n"
		. "[00:05] **Александр Лимонов:** начнём\n";

	private const SUMMARY = "---\nmeeting_date: '2026-07-20'\n---\n\n"
		. "# Executive Summary\n\nРешили передать задачу.\n";

	/** A summary from before the transcript was shared beside it. */
	private const SUMMARY_ALONE = "---\nmeeting_date: '2026-06-11'\n"
		. "meeting_file_stem: 2026-06-11_zapusk-obrabotchika-vekh-vruchnuiu\n---\n\n"
		. "# Executive Summary: Запуск обработчика вех вручную (Чт, 11 июня 2026)\n\n"
		. "## Executive brief\n\nРешили передать задачу.\n";

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
		bool $lookupThrows = false, bool $viaShare = false,
		array $index = [], bool $analysisFolder = false): FileArchive {
		$nodes = [];
		$shares = [];
		$rows = [];
		foreach ($files as $name => $content) {
			$file = $this->file($name, $content);
			if ($viaShare) {
				$shares[] = $this->shareOf($file);
			} else {
				$nodes[] = $file;
			}
			// What the file index says about this file. The analyser's files
			// have a meeting folder and a dated path there; nothing else does.
			$rows[$file->getId()] = ($index[$name] ?? [])
				+ ['parent' => 1, 'path' => 'files/' . $name];
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

		// The analyser's folder, when the test says this person can read it
		// whole — the account that keeps the archive, as against a participant
		// who only holds shares of single files.
		$analysis = $this->createMock(Folder::class);
		$analysis->method('getId')->willReturn(2);

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('get')->willReturnCallback(
			function (string $path) use ($folder, $analysis, $viaShare, $analysisFolder) {
				if ($analysisFolder && $path === 'Talk/Аналитика встреч') {
					return $analysis;
				}
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
			fn () => $this->queryBuilder($nodes, $lookupThrows, $rows,
				$viaShare ? $shares : []));

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

		// A cache that keeps nothing: every test reads the fixtures it wrote,
		// and a header parsed in one test must not answer another.
		$cache = $this->createMock(\OCP\ICacheFactory::class);
		$cache->method('createDistributed')->willReturn(
			$this->createMock(\OCP\ICache::class));

		return new FileArchive($root, $shareManager, $users, $db,
			$this->createMock(LoggerInterface::class), $cache);
	}

	/**
	 * @param File[] $nodes
	 */
	private function queryBuilder(array $nodes, bool $throws, array $rows = [],
		array $shares = []): \OCP\DB\QueryBuilder\IQueryBuilder {
		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$qb->method('expr')->willReturn($expr);
		foreach (['from', 'where', 'andWhere', 'orderBy'] as $m) {
			$qb->method($m)->willReturn($qb);
		}
		// Which columns were asked for is what tells the queries apart, the way
		// the real ones differ: the folder's own row, the analysed calls under
		// it, and the plain listing of a folder.
		$selected = [];
		$qb->method('select')->willReturnCallback(
			function (...$cols) use ($qb, &$selected) {
				$selected = $cols;
				return $qb;
			});
		$qb->method('createNamedParameter')->willReturn('?');

		// Every fixture as an index row, with whatever the test said about where
		// it sits.
		$all = array_map(static fn (File $f) => [
			'fileid' => $f->getId(),
			'name' => $f->getName(),
		] + ($rows[$f->getId()] ?? []), $nodes);
		foreach ($shares as $share) {
			$id = $share->getNodeId();
			$all[] = ['fileid' => $id, 'name' => ''] + ($rows[$id] ?? []);
		}

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturnCallback(
			static fn () => $selected === ['storage', 'path']
				? ['storage' => 1, 'path' => 'files/Talk/Аналитика встреч']
				: false);
		$result->method('fetchAll')->willReturnCallback(
			static function () use ($all, $throws, &$selected) {
				if ($throws) {
					throw new \RuntimeException('storage down');
				}
				// The analysed calls are those the test placed under the
				// analyser's tree; the other queries see everything, as the
				// folder listing does.
				if ($selected === ['fileid', 'name', 'parent', 'path']) {
					return array_values(array_filter($all, static fn (array $r) =>
						str_contains((string)($r['path'] ?? ''), 'Аналитика встреч')));
				}
				return $all;
			});
		$qb->method('executeQuery')->willReturn($result);
		return $qb;
	}

	private function file(string $name, string $content): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/alice/files/Talk/' . $name);
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

	public function testSearchMatchesTheMeetingName(): void {
		$other = str_replace('Вадим Куницын, Алексей Морозов',
			'Дарья Костусенко, Алексей Морозов', self::TRANSCRIPT);
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Встреча с Вадимом.md' => self::TRANSCRIPT,
			'2026-03-06 10-00-00 - Встреча с Дарьей.md' => $other,
		]);

		$meetings = $archive->list('alice', 50, 0, 'Вадим')['meetings'];

		$this->assertCount(1, $meetings);
	}

	public function testSearchMatchesAParticipant(): void {
		// The name people search by is often not in the meeting's title at all.
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Планёрка.md' => self::TRANSCRIPT,
			'2026-03-06 10-00-00 - Ретро.md' => str_replace(
				'Вадим Куницын, Алексей Морозов', 'Дарья Костусенко', self::TRANSCRIPT),
		]);

		$meetings = $archive->list('alice', 50, 0, 'Костусенко')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Ретро', $meetings[0]['room_name']);
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

	/**
	 * A meeting folder in the analyser's tree, as the file index sees it.
	 */
	private function meetingFolder(int $id, string $date, string $seq): array {
		return ['parent' => $id,
			'path' => "files/Talk/Аналитика встреч/$date/{$seq}_planerka/x.md"];
	}

	public function testAnAnalysedCallIsListed(): void {
		$archive = $this->archive(
			['10_Original_Transcript.md' => self::ANALYSIS],
			index: ['10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004')],
		);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('ППортал • Дейли', $meetings[0]['room_name']);
		$this->assertSame(['Александр Лимонов', 'Анатолий Хватиков'],
			$meetings[0]['participants']);
		$this->assertSame(19 * 60,
			$meetings[0]['call_end_ts'] - $meetings[0]['call_start_ts'],
			'the analyser states the end and the length, never the start');
	}

	public function testTheSummaryIsTheFileInTheSameMeetingFolder(): void {
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary.md' => self::SUMMARY,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		]);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertStringContainsString('Решили передать задачу',
			$archive->summary('alice', $id));
	}

	public function testASummaryFromAnotherMeetingIsNotAttached(): void {
		// Both arrive flat in Talk/ with the same name bar a suffix, so only the
		// meeting folder tells them apart.
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary (2).md' => self::SUMMARY,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary (2).md' => $this->meetingFolder(9, '2026-07-19', '002'),
		]);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertSame('', $archive->summary('alice', $id),
			'pairing on the mounted name shows a stranger the wrong call');
	}

	public function testAnalysedCallsAreOrderedByTheirMeetingFolder(): void {
		// Identical filenames: nothing but the index can order these.
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'10_Original_Transcript (2).md' => str_replace(
				'2026-07-20', '2026-06-01', self::ANALYSIS),
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'10_Original_Transcript (2).md' => $this->meetingFolder(9, '2026-06-01', '001'),
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(2, $meetings);
		$this->assertGreaterThan($meetings[1]['call_start_ts'],
			$meetings[0]['call_start_ts']);
	}

	public function testADateFilterUsesTheMeetingFolderNotTheFilename(): void {
		$archive = $this->archive(
			['10_Original_Transcript.md' => self::ANALYSIS],
			index: ['10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004')],
		);

		$this->assertCount(1, $archive->list('alice', 50, 0, '',
			strtotime('2026-07-01'), strtotime('2026-07-31'))['meetings']);
		$this->assertSame([], $archive->list('alice', 50, 0, '',
			strtotime('2026-03-01'), strtotime('2026-03-31'))['meetings']);
	}

	public function testSearchingAnAnalysedCallReadsItsHeader(): void {
		$archive = $this->archive(
			['10_Original_Transcript.md' => self::ANALYSIS],
			index: ['10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004')],
		);

		$this->assertCount(1, $archive->list('alice', 50, 0, 'Лимонов')['meetings'],
			'the mounted filename holds neither the meeting nor the people');
		$this->assertSame([], $archive->list('alice', 50, 0, 'Superset')['meetings']);
	}

	public function testTheOtherAnalysisFilesAreNotCalls(): void {
		// Never shared in practice, but a folder shared by hand would bring them
		// along, and only two of the dozen stand for a call.
		$archive = $this->archive([
			'04_Meeting_Dynamics.md' => self::SUMMARY,
			'05_Speaker_aleksandr-limonov.md' => self::SUMMARY,
			'09_Enriched_Transcript.md' => self::ANALYSIS,
		], index: [
			'04_Meeting_Dynamics.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'05_Speaker_aleksandr-limonov.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'09_Enriched_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		]);

		$this->assertSame([], $archive->list('alice')['meetings']);
	}

	public function testASummaryAloneStandsForTheCall(): void {
		// The months before the service shared the transcript: a participant
		// holds the summary and nothing else.
		$archive = $this->archive(
			['01_Executive_Summary.md' => self::SUMMARY_ALONE],
			index: ['01_Executive_Summary.md' => $this->meetingFolder(7, '2026-06-11', '002')],
		);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Запуск обработчика вех вручную', $meetings[0]['room_name'],
			'the name is in the heading; the date in it is not part of it');
		$this->assertFalse($meetings[0]['has_transcript']);
		$this->assertFalse($meetings[0]['has_time'],
			'the summary states the day only — an hour would be invented');
		$this->assertSame(strtotime('2026-06-11'), $meetings[0]['call_start_ts']);
	}

	public function testSuchACallOpensItsSummaryAndHasNoTranscript(): void {
		$archive = $this->archive(
			['01_Executive_Summary.md' => self::SUMMARY_ALONE],
			index: ['01_Executive_Summary.md' => $this->meetingFolder(7, '2026-06-11', '002')],
		);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$this->assertStringContainsString('Решили передать задачу',
			$archive->summary('alice', $id));
		$this->assertSame('', $archive->transcript('alice', $id),
			'returning the summary again would read as a transcript');
	}

	public function testASummaryIsNotListedTwiceWhenItsTranscriptIsThere(): void {
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary.md' => self::SUMMARY,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertTrue($meetings[0]['has_transcript']);
	}

	public function testSearchReachesACallKnownOnlyByItsSummary(): void {
		$archive = $this->archive(
			['01_Executive_Summary.md' => self::SUMMARY_ALONE],
			index: ['01_Executive_Summary.md' => $this->meetingFolder(7, '2026-06-11', '002')],
		);

		$this->assertCount(1, $archive->list('alice', 50, 0, 'обработчика')['meetings']);
		$this->assertSame([], $archive->list('alice', 50, 0, 'Superset')['meetings']);
	}

	// ── the analyser's folder as the archive ───────────────────────────────
	public function testTheAnalysersFolderGivesTheCallWhole(): void {
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary.md' => self::SUMMARY,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		], analysisFolder: true);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings, 'the pair is one call, not two');
		$this->assertTrue($meetings[0]['has_transcript']);
		$this->assertStringContainsString('Решили передать задачу',
			$archive->summary('alice', $meetings[0]['session_id']));
	}

	public function testALooseTranscriptOfAnAnalysedDayIsNotListedTwice(): void {
		// The service writes both: the analysed copy and the loose transcript.
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary.md' => self::SUMMARY,
			'2026-07-20 09-31-08 - ППортал • Дейли.md' => self::YAML,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		], analysisFolder: true);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('ППортал • Дейли', $meetings[0]['room_name'],
			'the analysed copy is the one kept — it has a summary');
	}

	public function testCallsFromBeforeTheAnalyserAreStillListed(): void {
		// The months the analyser did not cover are the loose transcripts and
		// their "Протокол" minutes, and nothing may drop them.
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
			'2026-03-05 14-49-00 - Протокол Вадим Куницын.md' => self::MINUTES,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		], analysisFolder: true);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(2, $meetings);
		$old = $meetings[1];
		$this->assertSame('Вадим Куницын', $old['room_name']);
		$this->assertStringContainsString('Решили выкатывать',
			$archive->summary('alice', $old['session_id']),
			'the old minutes still pair by timestamp');
	}

	public function testWithoutAnalysisNothingIsHidden(): void {
		// A participant with no analysed calls at all: the cutoff must not
		// swallow their archive.
		$archive = $this->archive([
			'2026-07-20 09-31-08 - Superset.md' => self::YAML,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$this->assertCount(2, $archive->list('alice')['meetings']);
	}

	public function testCallsAreListedInTheOrderTheyWereHeld(): void {
		// The analyser numbers folders as it processes them: 004 here is the
		// call that started later, and only the headers say so.
		$early = str_replace('finished_at: 2026-07-20 06:50:33+00:00',
			'finished_at: 2026-07-20 06:20:00+00:00', self::ANALYSIS);
		$late = str_replace(
			['finished_at: 2026-07-20 06:50:33+00:00', 'duration: 19 min'],
			['finished_at: 2026-07-20 07:10:00+00:00', 'duration: 5 min'],
			self::ANALYSIS);

		$archive = $this->archive([
			'10_Original_Transcript.md' => $early,
			'10_Original_Transcript (2).md' => $late,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '005'),
			'10_Original_Transcript (2).md' => $this->meetingFolder(9, '2026-07-20', '004'),
		], analysisFolder: true);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(2, $meetings);
		$this->assertGreaterThan($meetings[1]['call_start_ts'],
			$meetings[0]['call_start_ts'],
			'the folder number is not the order calls were held');
	}

	public function testAPageRunsToTheEndOfItsDay(): void {
		// Three calls on one day, asked for two: the third comes along, because
		// a day split across pages could not be ordered or headed.
		$files = $index = [];
		foreach ([1, 2, 3] as $i) {
			$name = $i === 1 ? '10_Original_Transcript.md' : "10_Original_Transcript ($i).md";
			$files[$name] = self::ANALYSIS;
			$index[$name] = $this->meetingFolder(6 + $i, '2026-07-20', "00$i");
		}
		$archive = $this->archive($files, index: $index, analysisFolder: true);

		$this->assertCount(3, $archive->list('alice', 2, 0)['meetings']);
	}

	public function testTheHeaderIsNotPrintedAboveTheMeeting(): void {
		$archive = $this->archive([
			'10_Original_Transcript.md' => self::ANALYSIS,
			'01_Executive_Summary.md' => self::SUMMARY,
		], index: [
			'10_Original_Transcript.md' => $this->meetingFolder(7, '2026-07-20', '004'),
			'01_Executive_Summary.md' => $this->meetingFolder(7, '2026-07-20', '004'),
		], analysisFolder: true);
		$id = $archive->list('alice')['meetings'][0]['session_id'];

		$summary = $archive->summary('alice', $id);
		$transcript = $archive->transcript('alice', $id);

		$this->assertStringStartsWith('# Executive Summary', $summary);
		$this->assertStringNotContainsString('meeting_date:', $summary);
		$this->assertStringStartsWith('[00:05]', $transcript);
		$this->assertStringNotContainsString('source_file:', $transcript);
	}

	// ── conversations as a filter ──────────────────────────────────────────
	public function testOnlyGroupConversationsAreOffered(): void {
		// Two people is a one-to-one however it is named — and most of the
		// archive is those, which is exactly what makes the list unusable.
		$group = str_replace("participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин",
			"participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин\n  - Дарья Костусенко",
			self::YAML);
		$archive = $this->archive([
			'2026-07-20 10-00-00 - Планёрка.md' => $group,
			'2026-07-19 10-00-00 - Тет-а-тет.md' => self::YAML,
		]);

		$rooms = $archive->rooms('alice');

		$this->assertSame(['Вводная встреча по Superset'],
			array_column($rooms, 'name'));
	}

	public function testConversationsComeMostUsedFirst(): void {
		$three = "participants:\n  - A\n  - B\n  - C";
		$one = str_replace("participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин",
			$three, str_replace('Вводная встреча по Superset', 'Дейли', self::YAML));
		$two = str_replace("participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин",
			$three, str_replace('Вводная встреча по Superset', 'Ретро', self::YAML));
		$archive = $this->archive([
			'2026-07-20 10-00-00 - a.md' => $one,
			'2026-07-19 10-00-00 - b.md' => $one,
			'2026-07-18 10-00-00 - c.md' => $two,
		]);

		$rooms = $archive->rooms('alice');

		$this->assertSame(['Дейли', 'Ретро'], array_column($rooms, 'name'));
		$this->assertSame([2, 1], array_column($rooms, 'count'));
	}

	public function testFilteringByConversationKeepsOnlyItsCalls(): void {
		$three = "participants:\n  - A\n  - B\n  - C";
		$daily = str_replace("participants:\n  - Анатолий Хватиков\n  - Евгений Кутявин",
			$three, str_replace('Вводная встреча по Superset', 'Дейли', self::YAML));
		$archive = $this->archive([
			'2026-07-20 10-00-00 - a.md' => $daily,
			'2026-07-19 10-00-00 - b.md' => self::YAML,
		]);

		$meetings = $archive->list('alice', 50, 0, '', 0, 0, 'Дейли')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Дейли', $meetings[0]['room_name']);
	}

}
