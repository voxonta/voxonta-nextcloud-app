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
	private const HEADER_LEGACY = '# Транскрипция:';
	private const MINUTES_MARKER = 'Протокол';

	/**
	 * The date a transcript's filename starts with: "2026-03-24 …".
	 *
	 * `_` matches exactly one character, so this is the shape of a date and not
	 * merely "starts with 20". The looser version also matched prompt files
	 * named 20_extract_knowledge.md — and since `_` sorts above digits, those
	 * filled the whole first page and pushed every real transcript out of it.
	 */
	private const NAME_PATTERN = '20__-__-__ %';

	public function __construct(
		private IRootFolder $rootFolder,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Calls this person can see, newest first.
	 *
	 * Paging is over transcripts, but the index pages over *files*, and the two
	 * differ: some files that match the name pattern turn out on reading not to
	 * be transcripts. So the offset the caller passes is a file offset, and the
	 * answer carries the offset to resume from — otherwise a page a few
	 * non-transcripts short would look like the end of the archive, and the
	 * older calls behind it would never load.
	 *
	 * @return array{meetings: array<int, array<string, mixed>>,
	 *               next_offset: int, has_more: bool}
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0): array {
		$meetings = [];
		$scanned = 0;
		$exhausted = false;

		// Read files in batches until the page is full or the archive runs out.
		// Selection, ordering and paging are the index's job — doing them in
		// PHP would mean fetching every markdown file the user can see, and this
		// instance has thousands.
		while (count($meetings) < $limit) {
			$batch = $this->transcripts($userId, $limit, $offset + $scanned);
			foreach ($batch as $file) {
				$scanned++;
				$meta = $this->transcriptMetadata($file);
				if ($meta !== null) {
					$meetings[] = $meta;
					if (count($meetings) >= $limit) {
						break;
					}
				}
			}
			if (count($batch) < $limit) {
				$exhausted = true;
				break;
			}
		}

		// Counts only — never names or content: filenames carry meeting titles
		// and participants.
		$this->logger->debug('archive listing for {user}: {scanned} files from '
			. 'offset {offset}, {kept} transcripts', [
				'user' => $userId,
				'scanned' => $scanned,
				'offset' => $offset,
				'kept' => count($meetings),
			]);

		return [
			'meetings' => $meetings,
			'next_offset' => $offset + $scanned,
			'has_more' => !$exhausted,
		];
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
	 * What the transcript itself states about the call.
	 *
	 * Two formats are in the archive and both have to work. Current files open
	 * with a YAML block — started_at, participants, meeting_name. Files from
	 * the spring open with "# Транскрипция:" and a couple of Russian-labelled
	 * lines. Reading only the newer one would silently drop several months of
	 * calls; converting them would rewrite files people already have.
	 *
	 * @return array<string, mixed>|null null when this is not a transcript
	 */
	private function transcriptMetadata(File $file): ?array {
		$head = $this->head($file);
		if ($head === null) {
			return null;
		}
		$head = ltrim($head);

		$meta = str_starts_with($head, '---')
			? $this->fromYaml($head)
			: (str_starts_with($head, self::HEADER_LEGACY)
				? $this->fromLegacyHeader($head)
				: null);

		if ($meta === null) {
			return null;
		}

		$name = $meta['meeting_name'] !== ''
			? $meta['meeting_name']
			: $this->title($file->getName());
		unset($meta['meeting_name']);

		return $meta + [
			'session_id' => $this->sessionId($file),
			'room_name' => $name,
			'has_transcript' => true,
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fromYaml(string $head): ?array {
		$end = strpos($head, "\n---", 3);
		$block = $end === false ? $head : substr($head, 3, $end - 3);

		$scalars = [];
		$participants = [];
		$listKey = '';
		foreach (explode("\n", $block) as $line) {
			// A list item belongs to whichever key introduced it.
			if (preg_match('/^\s+-\s+(.+)$/u', $line, $item)) {
				if ($listKey === 'participants') {
					$participants[] = trim($item[1], " \"\'");
				}
				continue;
			}
			if (preg_match('/^(\w+):\s*(.*)$/u', $line, $pair)) {
				$listKey = $pair[1];
				if ($pair[2] !== '') {
					$scalars[$pair[1]] = trim($pair[2], " \"\'");
				}
			}
		}

		// The minutes carry the same shape of header, so the presence of a
		// meeting name is not enough to call this a transcript; the marker is
		// that it describes a call at all.
		if (!isset($scalars['started_at']) && !isset($scalars['date'])) {
			return null;
		}

		$start = strtotime($scalars['started_at'] ?? $scalars['date'] ?? '') ?: 0;
		$end = strtotime($scalars['finished_at'] ?? '') ?: 0;

		return [
			'call_start_ts' => $start,
			'call_end_ts' => $end > $start ? $end : 0,
			'participants' => $participants,
			'meeting_name' => $scalars['meeting_name'] ?? '',
		];
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function fromLegacyHeader(string $head): ?array {
		[$start, $end] = $this->period($head);
		return [
			'call_start_ts' => $start,
			'call_end_ts' => $end,
			'participants' => $this->participants($head),
			'meeting_name' => '',
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
