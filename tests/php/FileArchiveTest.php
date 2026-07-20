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
use OCP\Files\NotFoundException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Reading meetings out of the user's own files.
 *
 * The security property being tested is indirect and worth stating plainly:
 * nothing here decides who may read what. The search runs against the caller's
 * folder, so a meeting that was never shared with them is not in the result at
 * all. These tests pin that the code keeps asking the *caller's* folder — the
 * one mistake that would quietly turn this into an archive-wide reader.
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

	/**
	 * Build an archive over the given files, exactly as they exist in
	 * Nextcloud today: markdown in a shared folder, no tags, no front matter.
	 *
	 * @param array<string, string> $files filename => content
	 */
	private function archive(array $files, ?string &$searchedFor = null,
		bool $searchThrows = false): FileArchive {
		$nodes = [];
		$id = 100;
		foreach ($files as $name => $content) {
			$file = $this->createMock(File::class);
			$file->method('getName')->willReturn($name);
			$file->method('getId')->willReturn($id++);
			$file->method('getContent')->willReturn($content);
			$file->method('fopen')->willReturnCallback(
				static function () use ($content) {
					$handle = fopen('php://memory', 'r+');
					fwrite($handle, $content);
					rewind($handle);
					return $handle;
				});
			$nodes[] = $file;
		}

		$userFolder = $this->createMock(Folder::class);
		// Stand in for the file index: apply the query the way the database
		// would, so the tests exercise the query we actually build.
		$userFolder->method('search')->willReturnCallback(
			function (ISearchQuery $query) use ($nodes, $searchThrows) {
				if ($searchThrows) {
					throw new \RuntimeException('storage unavailable');
				}
				$matching = array_values(array_filter($nodes,
					static fn (File $f) => self::satisfies($f->getName(),
						$query->getSearchOperation())));
				usort($matching, static fn ($a, $b) => strcmp($b->getName(), $a->getName()));
				return array_slice($matching, $query->getOffset(), $query->getLimit());
			});
		$userFolder->method('getById')->willReturnCallback(
			static function (int $id) use ($nodes) {
				return array_values(array_filter($nodes,
					static fn (File $f) => $f->getId() === $id));
			});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $user) use ($userFolder, &$searchedFor) {
				$searchedFor = $user;
				return $userFolder;
			});

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturn($this->createMock(IUser::class));

		return new FileArchive($root, $users,
			$this->createMock(LoggerInterface::class));
	}

	/**
	 * Evaluate a search operator against a filename, mirroring how the index
	 * applies it. Only the operators this service builds are supported.
	 */
	private static function satisfies(string $name, $operator): bool {
		if ($operator instanceof \OCP\Files\Search\ISearchBinaryOperator) {
			$results = array_map(
				static fn ($arg) => self::satisfies($name, $arg),
				$operator->getArguments());
			return match ($operator->getType()) {
				'and' => !in_array(false, $results, true),
				'or' => in_array(true, $results, true),
				'not' => !$results[0],
				default => true,
			};
		}

		if ($operator instanceof \OCP\Files\Search\ISearchComparison) {
			if ($operator->getField() === 'mimetype') {
				return true;   // every fixture is markdown
			}
			// LIKE semantics: % is any run of characters, _ is exactly one.
			// Getting _ wrong here would let a test accept a pattern the
			// database rejects rows on — which is how the prompt files ended up
			// filling the first page in production.
			$pattern = preg_quote((string)$operator->getValue(), '/');
			$pattern = str_replace(['%', '_'], ['.*', '.'], $pattern);
			return (bool)preg_match('/^' . $pattern . '$/u', $name);
		}

		return true;
	}

	private function transcriptFile(): array {
		return ['2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT];
	}

	public function testTheSearchRunsAgainstTheCallersOwnFiles(): void {
		$searched = null;
		$archive = $this->archive($this->transcriptFile(), $searched);

		$archive->list('alice');

		$this->assertSame('alice', $searched,
			'searching anyone else\'s files would return calls the caller was '
			. 'never given');
	}

	public function testMetadataComesFromTheTranscriptHeader(): void {
		$archive = $this->archive($this->transcriptFile());

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Вадим Куницын', $meetings[0]['room_name']);
		$this->assertSame(['Вадим Куницын', 'Алексей Морозов'],
			$meetings[0]['participants']);
		$this->assertSame(15 * 60,
			$meetings[0]['call_end_ts'] - $meetings[0]['call_start_ts']);
	}

	public function testMarkdownThatIsNotATranscriptIsIgnored(): void {
		$archive = $this->archive([
			'Shopping list.md' => "# Milk\n",
			'Readme.md' => "Some notes\n",
		]);

		$this->assertSame([], $archive->list('alice')['meetings'],
			'the user\'s own markdown must not appear as calls');
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
			'pairing on anything looser than the timestamp attaches the wrong '
			. 'minutes to a call');
	}

	public function testACallWithoutMinutesStillOpens(): void {
		// Transcribed but not analysed is a normal state, not an error.
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
			$this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus(),
				'anything but 404 tells the caller the call exists');
		}
	}

	public function testAFailingSearchShowsNothingRatherThanEverything(): void {
		$searched = null;
		$archive = $this->archive($this->transcriptFile(), $searched, true);

		$this->assertSame([], $archive->list('alice')['meetings']);
	}

	public function testNewestCallsComeFirst(): void {
		$older = str_replace('2026-03-05 14:49', '2026-03-01 09:00', self::TRANSCRIPT);
		$archive = $this->archive([
			'2026-03-01 09-00-00 - Старый звонок.md' => $older,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertSame('Вадим Куницын', $meetings[0]['room_name'],
			'the call people look for is almost always a recent one');
	}

	/**
	 * Nextcloud interpolates search operators into strings while building the
	 * query, but the published interfaces never say so — the omission surfaced
	 * in production as "could not be converted to string", thrown from inside
	 * code that never mentions this app.
	 */
	public function testSearchOperatorsCanBeConvertedToString(): void {
		$comparison = new \OCA\DoneTranscription\Service\Search\Comparison(
			'eq', 'mimetype', 'text/markdown');
		$this->assertStringContainsString('mimetype', (string)$comparison);

		$binary = new \OCA\DoneTranscription\Service\Search\BinaryOperator(
			'and', $comparison, $comparison);
		$this->assertStringContainsString('and', (string)$binary);

		$negated = new \OCA\DoneTranscription\Service\Search\BinaryOperator(
			'not', $comparison);
		$this->assertStringContainsString('not', (string)$negated);
	}

	public function testFilesThatMerelyStartWithTwentyAreNotCalls(): void {
		// The prompt files on this instance are named 20_extract_knowledge.md,
		// and "_" sorts above digits — a looser pattern let them fill the whole
		// first page and pushed every real transcript off it.
		$archive = $this->archive([
			'20_extract_knowledge.md' => "# Prompt\n",
			'20_cross_check.md' => "# Prompt\n",
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Вадим Куницын', $meetings[0]['room_name']);
	}

	public function testTheCurrentYamlFormatIsRead(): void {
		$archive = $this->archive([
			'2026-07-20 17-00-23 - Вводная встреча по Superset.md' => self::YAML,
		]);

		$meetings = $archive->list('alice')['meetings'];

		$this->assertCount(1, $meetings);
		$this->assertSame('Вводная встреча по Superset', $meetings[0]['room_name'],
			'the meeting name in the header is better than the one in the filename');
		$this->assertSame(['Анатолий Хватиков', 'Евгений Кутявин'],
			$meetings[0]['participants']);
		$this->assertSame(40 * 60,
			$meetings[0]['call_end_ts'] - $meetings[0]['call_start_ts']);
	}

	public function testBothFormatsAppearInOneList(): void {
		// The archive holds months of each; reading only one silently drops the
		// other.
		$archive = $this->archive([
			'2026-07-20 17-00-23 - Superset.md' => self::YAML,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$this->assertCount(2, $archive->list('alice')['meetings']);
	}

	public function testAYamlFileThatIsNotACallIsIgnored(): void {
		// Front matter alone is not a transcript — plenty of notes have it.
		$archive = $this->archive([
			'2026-07-20 10-00-00 - Notes.md' =>
				"---\ntitle: Just notes\ntags:\n  - idea\n---\n\nText\n",
		]);

		$this->assertSame([], $archive->list('alice')['meetings'],
			'a call is recognised by having a time, not by having a header');
	}
}
