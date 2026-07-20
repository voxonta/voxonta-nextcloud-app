<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\AppFramework\Http;
use OCA\DoneTranscription\Service\Search\BinaryOperator;
use OCA\DoneTranscription\Service\Search\Comparison;
use OCA\DoneTranscription\Service\Search\Order;
use OCA\DoneTranscription\Service\Search\Query;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\Search\ISearchBinaryOperator;
use OCP\Files\Search\ISearchComparison;
use OCP\Files\Search\ISearchOperator;
use OCP\Files\Search\ISearchOrder;
use OCP\IUserManager;
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

	/** Filenames start with the year, which is what makes them recognisable. */
	private const NAME_PATTERN = '20%';

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Calls this person can see, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0): array {
		// Selection, ordering and paging all happen in the file index, which is
		// what it is for. Doing any of it in PHP means fetching every markdown
		// file the user can see — thirteen thousand of them on this instance —
		// to display fifty rows.
		$meetings = [];
		foreach ($this->transcripts($userId, $limit, $offset) as $file) {
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

		// Asked of the index by name, so the answer is the one file rather than
		// every file with a chance of being it.
		foreach ($this->minutesMatching($userId, $prefix) as $candidate) {
			if ($candidate->getId() !== $transcript->getId()) {
				return $this->contents($candidate);
			}
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
	 * The transcript behind a call id, if this person may see it.
	 *
	 * getById is resolved against the caller's own mounts, so a file they were
	 * never given comes back as nothing — the permission check is the lookup.
	 *
	 * @throws BackendException
	 */
	private function fileFor(string $userId, string $sessionId): File {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$nodes = $userFolder->getById((int)$sessionId);
		} catch (\Throwable $e) {
			$this->logger->error('could not resolve call {id} for {user}', [
				'id' => $sessionId, 'user' => $userId, 'exception' => $e,
			]);
			$nodes = [];
		}

		foreach ($nodes as $node) {
			if ($node instanceof File) {
				return $node;
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
	 * Transcripts, newest first, one page at a time.
	 *
	 * @return File[]
	 */
	private function transcripts(string $userId, int $limit, int $offset): array {
		return $this->search($userId, new BinaryOperator(
			ISearchBinaryOperator::OPERATOR_AND,
			$this->isMarkdown(),
			$this->nameLike(self::NAME_PATTERN),
			// The minutes sit beside the transcript and share its name; a
			// listing that included them would show every call twice.
			new BinaryOperator(ISearchBinaryOperator::OPERATOR_NOT,
				$this->nameLike('%' . self::MINUTES_MARKER . '%')),
		), $limit, $offset);
	}

	/**
	 * The minutes belonging to a given timestamp.
	 *
	 * @return File[]
	 */
	private function minutesMatching(string $userId, string $prefix): array {
		if ($prefix === '') {
			return [];
		}
		return $this->search($userId, new BinaryOperator(
			ISearchBinaryOperator::OPERATOR_AND,
			$this->isMarkdown(),
			$this->nameLike($prefix . '%'),
			$this->nameLike('%' . self::MINUTES_MARKER . '%'),
		), 5, 0);
	}

	/**
	 * @return File[]
	 */
	private function search(string $userId, ISearchOperator $operator,
		int $limit, int $offset): array {
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			$user = $this->userManager->get($userId);
			$found = $userFolder->search(new Query(
				$operator,
				$limit,
				$offset,
				// Names begin with the timestamp, so descending by name is
				// newest first — ordered by the database, not by us.
				[new Order(ISearchOrder::DIRECTION_DESCENDING, 'name')],
				$user,
			));
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

	private function isMarkdown(): ISearchOperator {
		return new Comparison(ISearchComparison::COMPARE_EQUAL,
			'mimetype', 'text/markdown');
	}

	private function nameLike(string $pattern): ISearchOperator {
		return new Comparison(ISearchComparison::COMPARE_LIKE, 'name', $pattern);
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
