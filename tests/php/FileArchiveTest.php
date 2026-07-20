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
	private const HEADER = "---\nsession_id: s1\nroom: Standup\n"
		. "start_ts: 1000\nend_ts: 1900\n"
		. "participants: [alice, bob]\n---\n\nWhat was decided.\n";

	/**
	 * @param array<string, string> $files name => content, as one meeting folder
	 */
	private function archive(array $files, ?string &$searchedFor = null,
		bool $searchThrows = false): FileArchive {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturnCallback(
			function (string $name) use ($files) {
				if (!isset($files[$name])) {
					throw new NotFoundException($name);
				}
				$node = $this->createMock(File::class);
				$node->method('getContent')->willReturn($files[$name]);
				return $node;
			});

		$summary = $this->createMock(File::class);
		$summary->method('getName')->willReturn('summary.md');
		$summary->method('getParent')->willReturn($folder);
		$summary->method('fopen')->willReturnCallback(
			static function () use ($files) {
				$handle = fopen('php://memory', 'r+');
				fwrite($handle, $files['summary.md'] ?? '');
				rewind($handle);
				return $handle;
			});

		$userFolder = $this->createMock(Folder::class);
		$userFolder->method('searchBySystemTag')->willReturnCallback(
			function (string $tag, string $user) use ($summary, &$searchedFor, $searchThrows) {
				$searchedFor = $user;
				if ($searchThrows) {
					throw new \RuntimeException('storage unavailable');
				}
				return [$summary];
			});

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturn($userFolder);

		return new FileArchive($root, $this->createMock(LoggerInterface::class));
	}

	public function testTheSearchRunsAgainstTheCallersOwnFolder(): void {
		$searched = null;
		$archive = $this->archive(['summary.md' => self::HEADER], $searched);

		$archive->list('alice');

		$this->assertSame('alice', $searched,
			'searching as anyone else would return meetings the caller was '
			. 'never given');
	}

	public function testMetadataComesFromTheFrontMatter(): void {
		$archive = $this->archive(['summary.md' => self::HEADER]);

		$meetings = $archive->list('alice');

		$this->assertCount(1, $meetings);
		$this->assertSame('s1', $meetings[0]['session_id']);
		$this->assertSame('Standup', $meetings[0]['room_name']);
		$this->assertSame(1000, $meetings[0]['call_start_ts']);
		$this->assertSame(['alice', 'bob'], $meetings[0]['participants']);
	}

	public function testAFileWithoutAHeaderIsNotAMeeting(): void {
		$archive = $this->archive(['summary.md' => "Just some notes.\n"]);

		$this->assertSame([], $archive->list('alice'),
			'a stray summary.md in someone\'s files must not become a meeting');
	}

	public function testTheSummaryIsReadable(): void {
		$archive = $this->archive(['summary.md' => self::HEADER]);

		$this->assertStringContainsString('What was decided.',
			$archive->summary('alice', 's1'));
	}

	public function testAnUnknownMeetingIsNotFound(): void {
		$archive = $this->archive(['summary.md' => self::HEADER]);

		$this->expectException(BackendException::class);
		$archive->summary('alice', 'someone-elses-session');
	}

	public function testARefusalDoesNotDistinguishMissingFromForbidden(): void {
		$archive = $this->archive(['summary.md' => self::HEADER]);

		try {
			$archive->summary('alice', 'someone-elses-session');
			$this->fail('expected a refusal');
		} catch (BackendException $e) {
			$this->assertSame(Http::STATUS_NOT_FOUND, $e->getStatus(),
				'anything but 404 tells the caller the meeting exists');
		}
	}

	public function testACallWhereNobodySpokeStillOpens(): void {
		// summary.md but no transcript.md: a real outcome, not an error.
		$archive = $this->archive(['summary.md' => self::HEADER]);

		$this->assertSame('', $archive->transcript('alice', 's1'));
	}

	public function testAFailingSearchShowsNothingRatherThanEverything(): void {
		$searched = null;
		$archive = $this->archive(['summary.md' => self::HEADER], $searched, true);

		$this->assertSame([], $archive->list('alice'));
	}

	public function testOnlySummaryFilesBecomeMeetings(): void {
		// The tag sits on the folder's files; transcript.md carries it too, and
		// counting both would list every meeting twice.
		$archive = $this->archive([
			'summary.md' => self::HEADER,
			'transcript.md' => "---\nsession_id: s1\n---\n",
		]);

		$this->assertCount(1, $archive->list('alice'));
	}
}
