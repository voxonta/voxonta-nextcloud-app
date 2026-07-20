<?php

declare(strict_types=1);

namespace OCA\DoneTranscription\Service;

use OCP\AppFramework\Http;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Share\IManager;
use OCP\Share\IShare;
use Psr\Log\LoggerInterface;

/**
 * The archive, read from the files the transcription service already writes.
 *
 * Access control is Nextcloud's, and this is the whole design: a call reaches a
 * person only if it was shared with them, and the shares are what this class
 * reads. There is no permission rule here to enforce or to forget — a call that
 * was never shared is simply not in what comes back.
 *
 * Files come from two sources at once. A file search sees everything mounted
 * into the user's tree; the share manager sees shares directly. Neither is
 * complete alone — the search misses Talk-conversation shares that never mount,
 * the share manager's getSharedWith does not return Talk's per-user room shares
 * — so both are read and merged by file id.
 *
 * Two markdown formats live in the archive and both are read: current files
 * open with a YAML block, spring ones with a "# Транскрипция:" header.
 * Converting the old ones would rewrite documents people already hold.
 */
class FileArchive {
	private const HEADER_LEGACY = '# Транскрипция:';
	private const MINUTES_MARKER = 'Протокол';

	/**
	 * Share types a recipient can hold. Numeric rather than the IShare::TYPE_*
	 * constants because the newer ones (USERROOM, DECK_USER) are absent from
	 * some versions of the published stubs, while the values are stable and the
	 * core takes an int. Legend: 0 user, 1 group, 2 usergroup, 10 Talk room,
	 * 11 Talk user-in-room, 13 Deck. The two Talk types are exactly what a file
	 * search cannot see.
	 */
	private const SHARE_TYPES = [0, 1, 2, 10, 11, 13];

	/** Shares fetched per page, per type. */
	private const SHARE_PAGE = 200;

	/**
	 * Most pages any one share type may take before we stop. At SHARE_PAGE=200
	 * this is 200 000 shares — far past any real archive, and only a backstop
	 * against a provider that ignores the offset and never advances.
	 */
	private const PAGE_GUARD = 1000;

	/** @var array<string, array<int, array{name: string, node: ?File, share: ?IShare}>> */
	private array $cache = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private IManager $shareManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Calls this person can see, newest first.
	 *
	 * Candidate names are cheap — a share carries its target path without the
	 * file being loaded — so the full list is filtered and sorted on names
	 * alone, and only the page about to be shown has its header read. Paging is
	 * over transcripts, and the offset the caller gets back is where to resume.
	 *
	 * @return array{meetings: array<int, array<string, mixed>>,
	 *               next_offset: int, has_more: bool}
	 */
	public function list(string $userId, int $limit = 50, int $offset = 0): array {
		$candidates = $this->transcriptCandidates($userId);

		$meetings = [];
		$scanned = 0;
		foreach (array_slice($candidates, $offset) as $entry) {
			$scanned++;
			$file = $this->resolve($entry);
			$meta = $file === null ? null : $this->transcriptMetadata($file);
			if ($meta !== null) {
				$meetings[] = $meta;
				if (count($meetings) >= $limit) {
					break;
				}
			}
		}

		$this->logger->debug('archive listing for {user}: {total} candidates, '
			. '{scanned} scanned from {offset}, {kept} shown', [
				'user' => $userId,
				'total' => count($candidates),
				'scanned' => $scanned,
				'offset' => $offset,
				'kept' => count($meetings),
			]);

		return [
			'meetings' => $meetings,
			'next_offset' => $offset + $scanned,
			'has_more' => ($offset + $scanned) < count($candidates),
		];
	}

	/**
	 * The minutes, when the analyser has produced them.
	 *
	 * @throws BackendException
	 */
	public function summary(string $userId, string $sessionId): string {
		$transcript = $this->fileFor($userId, $sessionId);
		$prefix = $this->timestampPrefix($transcript->getName());
		if ($prefix === '') {
			return '';
		}

		foreach ($this->candidates($userId) as $id => $entry) {
			if ($id === (int)$sessionId
				|| !str_contains($entry['name'], self::MINUTES_MARKER)
				|| $this->timestampPrefix($entry['name']) !== $prefix) {
				continue;
			}
			$file = $this->resolve($entry);
			if ($file !== null) {
				return $this->contents($file);
			}
		}

		// A call transcribed but not analysed is a normal state, not an error.
		return '';
	}

	/**
	 * @throws BackendException
	 */
	public function transcript(string $userId, string $sessionId): string {
		return $this->contents($this->fileFor($userId, $sessionId));
	}

	/**
	 * The file behind a call id, if this person may see it.
	 *
	 * The id is looked up among the caller's own shares, so a file never shared
	 * with them is not there — the lookup is the permission check.
	 *
	 * @throws BackendException
	 */
	private function fileFor(string $userId, string $sessionId): File {
		$entry = $this->candidates($userId)[(int)$sessionId] ?? null;
		$file = $entry === null ? null : $this->resolve($entry);
		if ($file !== null) {
			return $file;
		}

		// Not "no access" — from here the two are indistinguishable, and saying
		// which would confirm the call exists to someone who cannot open it.
		throw new BackendException('not found', Http::STATUS_NOT_FOUND);
	}

	/**
	 * Every markdown file the user can reach, by file id, cheaply: names come
	 * from share targets and directory listings, without loading the files.
	 *
	 * @return array<int, array{name: string, node: ?File, share: ?IShare}>
	 */
	private function candidates(string $userId): array {
		if (isset($this->cache[$userId])) {
			return $this->cache[$userId];
		}

		$entries = [];

		// Two sources, because neither is complete on its own. A file search
		// sees everything mounted into the user's tree — their own files and
		// the shares that mounted, which is most of the archive but not the
		// Talk-conversation shares that never mount. The share manager sees the
		// shares directly, including personal ones a search can miss. Together
		// they cover more than either; the file id dedupes the overlap.
		try {
			$userFolder = $this->rootFolder->getUserFolder($userId);
			foreach ($userFolder->searchByMime('text/markdown') as $node) {
				if ($node instanceof File) {
					$entries[$node->getId()] ??= [
						'name' => $node->getName(),
						'node' => $node,
						'share' => null,
					];
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('could not search files for {user}', [
				'user' => $userId, 'exception' => $e,
			]);
		}

		$fromSearch = count($entries);

		// Shares of every type — this is where the Talk-conversation files are,
		// the ones a file search cannot see.
		foreach (self::SHARE_TYPES as $type) {
			$offset = 0;
			// Advance by however many actually came back, and stop only when a
			// page is empty. The count cannot be compared to the requested
			// limit: Talk's share provider returns fewer than asked without
			// that meaning the end, and treating a short page as the end lost
			// most of the archive. GUARD caps the loop if a provider ignored
			// the offset and kept returning the same page.
			for ($guard = 0; $guard < self::PAGE_GUARD; $guard++) {
				try {
					$shares = $this->shareManager->getSharedWith(
						$userId, $type, null, self::SHARE_PAGE, $offset);
				} catch (\Throwable $e) {
					$this->logger->warning('could not read {type} shares for {user}', [
						'type' => $type, 'user' => $userId, 'exception' => $e,
					]);
					break;
				}
				if ($shares === []) {
					break;
				}
				foreach ($shares as $share) {
					$id = $share->getNodeId();
					// The owner's own copy, already added, is the better handle:
					// it opens without resolving a share.
					if (!isset($entries[$id])) {
						$entries[$id] = [
							'name' => basename($share->getTarget()),
							'node' => null,
							'share' => $share,
						];
					}
				}
				$offset += count($shares);
			}
		}

		$this->logger->debug('candidate files for {user}: {search} from search, '
			. '{total} total after shares', [
				'user' => $userId,
				'search' => $fromSearch,
				'total' => count($entries),
			]);

		return $this->cache[$userId] = $entries;
	}

	/**
	 * Candidates that look like a transcript by name — newest first, and no
	 * files opened yet.
	 *
	 * @return array<int, array{name: string, node: ?File, share: ?IShare}>
	 */
	private function transcriptCandidates(string $userId): array {
		$transcripts = array_filter(
			$this->candidates($userId),
			fn (array $e) => $this->looksLikeTranscript($e['name']),
		);

		// Filenames begin with the timestamp, so sorting them reverse is
		// chronological — newest first, without reading a single file.
		uasort($transcripts, static fn ($a, $b) => strcmp($b['name'], $a['name']));
		return array_values($transcripts);
	}

	/**
	 * A transcript's filename starts with a date and is not the minutes. Both
	 * checks are on the name alone; the header confirms it later.
	 */
	private function looksLikeTranscript(string $name): bool {
		return preg_match('/^20\d\d-\d\d-\d\d /u', $name) === 1
			&& !str_contains($name, self::MINUTES_MARKER);
	}

	/**
	 * @param array{name: string, node: ?File, share: ?IShare} $entry
	 */
	private function resolve(array $entry): ?File {
		if ($entry['node'] instanceof File) {
			return $entry['node'];
		}
		try {
			$node = $entry['share']?->getNode();
			return $node instanceof File ? $node : null;
		} catch (\Throwable $e) {
			// A share whose file was deleted since — skip it rather than fail
			// the whole listing.
			$this->logger->warning('could not resolve a shared file', [
				'exception' => $e,
			]);
			return null;
		}
	}

	private function sessionId(File $file): string {
		return (string)$file->getId();
	}

	/**
	 * What the transcript's own header states about the call.
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

		// The minutes carry the same shape of header, so a meeting name is not
		// enough to call this a transcript; the marker is that it states a time.
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
			$this->logger->warning('could not read a transcript header', [
				'exception' => $e,
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
			$this->logger->error('could not read a call', ['exception' => $e]);
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
		// a negative duration would show nonsense, so drop it.
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
	 * The part of the filename people recognise, without the timestamp.
	 */
	private function title(string $name): string {
		$name = preg_replace('/\.md$/u', '', $name);
		$parts = explode(' - ', $name, 2);
		return trim($parts[1] ?? $parts[0]);
	}

	/**
	 * The timestamp a filename starts with — what pairs a transcript with its
	 * minutes.
	 */
	private function timestampPrefix(string $name): string {
		return preg_match('/^([\d-]{10}(?:\s[\d-]{8})?)/u', $name, $m) ? $m[1] : '';
	}
}
