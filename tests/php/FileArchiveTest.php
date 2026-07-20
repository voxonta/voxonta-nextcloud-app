<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Tests;

use OCA\DoneTranscription\Service\BackendException;
use OCA\DoneTranscription\Service\FileArchive;
use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
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
		$userFolder->method('searchByMime')->willReturnCallback(
			function (string $mime) use ($nodes, $searchThrows) {
				if ($searchThrows) {
					throw new \RuntimeException('storage unavailable');
				}
				return $nodes;
			});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(
			function (string $user) use ($userFolder, &$searchedFor) {
				$searchedFor = $user;
				return $userFolder;
			});

		return new FileArchive($root, $this->createMock(LoggerInterface::class));
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

		$meetings = $archive->list('alice');

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

		$this->assertSame([], $archive->list('alice'),
			'the user\'s own markdown must not appear as calls');
	}

	public function testTheTranscriptIsReadable(): void {
		$archive = $this->archive($this->transcriptFile());
		$id = $archive->list('alice')[0]['session_id'];

		$this->assertStringContainsString('что решаем',
			$archive->transcript('alice', $id));
	}

	public function testTheMinutesArePairedByTimestamp(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
			'2026-03-05 14-49-00 - Протокол Вадим Куницын.md' => self::MINUTES,
		]);
		$id = $archive->list('alice')[0]['session_id'];

		$this->assertStringContainsString('Решили выкатывать',
			$archive->summary('alice', $id));
	}

	public function testMinutesFromADifferentCallAreNotPickedUp(): void {
		$archive = $this->archive([
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
			'2026-03-06 09-00-00 - Протокол Вадим Куницын.md' => self::MINUTES,
		]);
		$id = $archive->list('alice')[0]['session_id'];

		$this->assertSame('', $archive->summary('alice', $id),
			'pairing on anything looser than the timestamp attaches the wrong '
			. 'minutes to a call');
	}

	public function testACallWithoutMinutesStillOpens(): void {
		// Transcribed but not analysed is a normal state, not an error.
		$archive = $this->archive($this->transcriptFile());
		$id = $archive->list('alice')[0]['session_id'];

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

		$this->assertSame([], $archive->list('alice'));
	}

	public function testNewestCallsComeFirst(): void {
		$older = str_replace('2026-03-05 14:49', '2026-03-01 09:00', self::TRANSCRIPT);
		$archive = $this->archive([
			'2026-03-01 09-00-00 - Старый звонок.md' => $older,
			'2026-03-05 14-49-00 - Вадим Куницын.md' => self::TRANSCRIPT,
		]);

		$meetings = $archive->list('alice');

		$this->assertSame('Вадим Куницын', $meetings[0]['room_name'],
			'the call people look for is almost always a recent one');
	}
}
