<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Psr\Log\LoggerInterface;

/**
 * The archive, read from the files that already exist.
 *
 * The transcription service writes two markdown files per call — the
 * transcript, and the minutes produced by the analyser — and shares them with
 * the people who were on the call. This class reads exactly that, in exactly
 * that format. Nothing here asks the service to change, and nothing requires a
 * migration: the archive on screen is the archive on disk today.
 *
 * Access control is therefore Nextcloud's. The search runs against the
 * *caller's* own files, so a call that was never shared with them cannot be
 * found here — there is no rule to enforce and none to forget.
 *
 * Files are recognised by their header rather than by their location, because
 * a share lands wherever the recipient moved it. The pairing between a
 * transcript and its minutes is the timestamp both filenames start with, which
 * is what the service already puts there.
 */
class FileArchive {
	private const HEADER = '# Транскрипция:';
	private const MINUTES_MARKER = 'Протокол';

	/**
	 * Where the service keeps them, for the account it writes as.
	 *
	 * Listing a known folder is one storage operation; searching a whole home
	 * directory is thousands, and the account that owns the archive is exactly
	 * the one with the most files. Participants see their calls as shares and
	 * fall through to the search, which for them covers a handful of files.
	 */
	private const FOLDERS = ['Talk/Транскрипции', 'Talk/Протоколы'];

	public function __construct(
		private IRootFolder $rootFolder,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Calls this person can see, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0): array {
		// Candidates are chosen, ordered and paged on filenames alone, and only
		// then are headers read. Opening every markdown file the user can see
		// to draw one screen means thousands of storage reads — this instance
		// has thirteen thousand of them — and the page is fifty rows long.
		$candidates = $this->transcriptCandidates($userId);

		// Filenames begin with the timestamp, so sorting them reverse-
		// alphabetically is chronological, without opening anything.
		usort($candidates, static fn (File $a, File $b) => strcmp($b->getName(), $a->getName()));

		$meetings = [];
		foreach (array_slice($candidates, $offset, $limit) as $file) {
			$meta = $this->transcriptMetadata($file);
			if ($meta !== null) {
				$meetings[] = $meta;
			}
		}
		return $meetings;
	}

	/**
	 * The minutes: what people open, when the analyser has produced them.
	 *
	 * @throws BackendException
	 */
	public function summary(string $userId, string $sessionId): string {
		$transcript = $this->fileFor($userId, $sessionId);
		$prefix = $this->timestampPrefix($transcript->getName());

		foreach ($this->markdownFiles($userId) as $candidate) {
			$name = $candidate->getName();
			// Cheap checks first: the timestamp rules out all but a handful
			// before anything is opened.
			if ($this->timestampPrefix($name) !== $prefix
				|| !str_contains($name, self::MINUTES_MARKER)
				|| $candidate->getId() === $transcript->getId()) {
				continue;
			}
			return $this->contents($candidate);
		}

		// A call that was transcribed but never analysed is a normal state, not
		// an error: the analyser runs after the fact and can be turned off.
		return '';
	}

	/**
	 * @throws BackendException
	 */
	public function transcript(string $userId, string $sessionId): string {
		return $this->contents($this->fileFor($userId, $sessionId));
	}

	/**
	 * @throws BackendException
	 */
	private function fileFor(string $userId, string $sessionId): File {
		foreach ($this->transcriptCandidates($userId) as $file) {
			if ($this->sessionId($file) === $sessionId) {
				return $file;
			}
		}

		// Not "no access" — from here the two are indistinguishable, and
		// distinguishing them would confirm the call exists to someone who
		// cannot open it.
		throw new BackendException('not found', Http::STATUS_NOT_FOUND);
	}

	/**
	 * A stable handle for a call.
	 *
	 * The file id is Nextcloud's own and survives renaming and moving, which
	 * both happen: people tidy their shares.
	 */
	private function sessionId(File $file): string {
		return (string)$file->getId();
	}

	/**
	 * Files that could be a transcript, judged by name alone.
	 *
	 * The service names them "<timestamp> - <who>.md" and the minutes
	 * "<timestamp> - Протокол <who>.md". Recognising both from the filename
	 * costs nothing, and it is what keeps a listing from opening every note the
	 * user has ever written.
	 *
	 * @return File[]
	 */
	private function transcriptCandidates(string $userId): array {
		return array_values(array_filter(
			$this->markdownFiles($userId),
			fn (File $file) => $this->timestampPrefix($file->getName()) !== ''
				&& !str_contains($file->getName(), self::MINUTES_MARKER),
		));
	}

	/**
	 * @return File[]
	 */
	private function markdownFiles(string $userId): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
		} catch (\Throwable $e) {
			$this->logger->error('could not open the files of {user}', [
				'user' => $userId, 'exception' => $e,
			]);
			return [];
		}

		$fromFolders = $this->fromKnownFolders($userFolder);
		if ($fromFolders !== []) {
			return $fromFolders;
		}

		try {
			$found = $userFolder->searchByMime('text/markdown');
		} catch (\Throwable $e) {
			// Show nothing rather than everything, and say why in the log.
			$this->logger->error('could not search the archive for {user}', [
				'user' => $userId, 'exception' => $e,
			]);
			return [];
		}

		return array_values(array_filter(
			$found,
			static fn (Node $node) => $node instanceof File,
		));
	}

	/**
	 * @return File[]
	 */
	private function fromKnownFolders(Folder $userFolder): array {
		$files = [];
		foreach (self::FOLDERS as $path) {
			try {
				$folder = $userFolder->get($path);
			} catch (\Throwable) {
				// Absent for everyone but the service account. Not a problem:
				// their calls arrive as shares and the search finds those.
				continue;
			}
			if (!$folder instanceof Folder) {
				continue;
			}
			foreach ($folder->getDirectoryListing() as $node) {
				if ($node instanceof File) {
					$files[] = $node;
				}
			}
		}
		return $files;
	}

	/**
	 * Read what the transcript's own header states.
	 *
	 * Only the head of the file is read: the body is the conversation and can
	 * be long, and drawing one screen must not mean loading the whole archive.
	 *
	 * @return array<string, mixed>|null null when this is not a transcript
	 */
	private function transcriptMetadata(File $file): ?array {
		$head = $this->head($file);
		if ($head === null || !str_starts_with(ltrim($head), self::HEADER)) {
			return null;
		}

		$name = $file->getName();
		[$start, $end] = $this->period($head);

		return [
			'session_id' => $this->sessionId($file),
			'room_name' => $this->title($name),
			'call_start_ts' => $start,
			'call_end_ts' => $end,
			'participants' => $this->participants($head),
			'has_transcript' => true,
		];
	}

	private function head(File $file): ?string {
		try {
			$handle = $file->fopen('r');
			if ($handle === false) {
				return null;
			}
			$head = fread($handle, 2048);
			fclose($handle);
			return is_string($head) ? $head : null;
		} catch (\Throwable $e) {
			$this->logger->warning('could not read the header of {path}', [
				'path' => $file->getPath(), 'exception' => $e,
			]);
			return null;
		}
	}

	/**
	 * @throws BackendException
	 */
	private function contents(File $file): string {
		try {
			return (string)$file->getContent();
		} catch (\Throwable $e) {
			$this->logger->error('could not read {path}', [
				'path' => $file->getPath(), 'exception' => $e,
			]);
			throw new BackendException('could not read the call',
				Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}

	/**
	 * "Дата: 2026-03-05 14:49 — 14:51" as a pair of timestamps.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function period(string $head): array {
		if (!preg_match('/^Дата:\s*(\S+)\s+(\d{2}:\d{2})(?:\s*[—-]\s*(\d{2}:\d{2}))?/mu',
			$head, $m)) {
			return [0, 0];
		}

		$start = strtotime($m[1] . ' ' . $m[2]);
		$end = isset($m[3]) ? strtotime($m[1] . ' ' . $m[3]) : false;

		// A call that crosses midnight ends "before" it starts by this reading;
		// treating that as a negative duration would show nonsense, so drop it.
		if ($end !== false && $start !== false && $end < $start) {
			$end = false;
		}
		return [$start ?: 0, $end ?: 0];
	}

	/**
	 * @return string[]
	 */
	private function participants(string $head): array {
		if (!preg_match('/^Участники:\s*(.+)$/mu', $head, $m)) {
			return [];
		}
		return array_values(array_filter(array_map('trim', explode(',', $m[1]))));
	}

	/**
	 * The part of the filename people recognise, without the timestamp it
	 * starts with.
	 */
	private function title(string $name): string {
		$name = preg_replace('/\.md$/u', '', $name);
		$parts = explode(' - ', $name, 2);
		return trim($parts[1] ?? $parts[0]);
	}

	/**
	 * The timestamp a filename starts with, which is what pairs a transcript
	 * with its minutes.
	 */
	private function timestampPrefix(string $name): string {
		return preg_match('/^([\d-]{10}(?:\s[\d-]{8})?)/u', $name, $m) ? $m[1] : '';
	}
}
